<?php
/**
 * POI Nearby Cron - automatické zpracování fronty přes WP-Cron
 * 
 * @package DobityBaterky
 */

namespace DB\Jobs;

if (!defined('ABSPATH')) {
    exit;
}

class POI_Nearby_Cron {
    
    const CRON_HOOK = 'db_poi_nearby_process_queue';
    const CRON_INTERVAL = 15 * MINUTE_IN_SECONDS; // 15 minut
    
    public function __construct() {
        add_action(self::CRON_HOOK, array($this, 'process_queue'));
        add_filter('cron_schedules', array($this, 'add_cron_schedule'));
        
        // Zajistit, že je cron naplánován (pro případ, že plugin už byl aktivován před přidáním této třídy)
        add_action('admin_init', array($this, 'maybe_schedule_cron'));
    }
    
    /**
     * Přidá vlastní cron interval
     */
    public function add_cron_schedule($schedules) {
        $schedules['db_poi_nearby_15min'] = array(
            'interval' => self::CRON_INTERVAL,
            'display' => __('Každých 15 minut', 'dobity-baterky')
        );
        return $schedules;
    }
    
    /**
     * Naplánuje cron event
     */
    public function schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            wp_schedule_event(time(), 'db_poi_nearby_15min', self::CRON_HOOK);
        }
    }
    
    /**
     * Zruší cron event
     */
    public function unschedule_cron() {
        $timestamp = wp_next_scheduled(self::CRON_HOOK);
        if ($timestamp) {
            wp_unschedule_event($timestamp, self::CRON_HOOK);
        }
    }
    
    /**
     * Zkontroluje a případně naplánuje cron
     */
    public function maybe_schedule_cron() {
        if (!wp_next_scheduled(self::CRON_HOOK)) {
            $this->schedule_cron();
        }
    }
    
    /**
     * Zpracuje frontu (voláno z cronu)
     */
    public function process_queue() {
        // Kontrola, zda není fronta pozastavena
        $paused = (bool) get_option('db_nearby_paused', false);
        if ($paused) {
            return;
        }
        
        $queue_manager = new POI_Nearby_Queue_Manager();
        $recompute_job = new Nearby_Recompute_Job();
        
        $batch = 50;
        $limit_per_hour = $this->get_limit_per_hour();
        
        // Získat pending záznamy
        $items = $queue_manager->get_pending($batch);
        
        if (empty($items)) {
            return;
        }
        
        // Kontrola rate limitu
        $stats = $queue_manager->get_stats();
        $processed_last_hour = $stats['processed_last_hour'];
        
        if ($processed_last_hour >= $limit_per_hour) {
            return; // Tichý return, logování pouze při skutečném zpracování
        }
        
        $remaining_limit = $limit_per_hour - $processed_last_hour;
        $items_to_process = array_slice($items, 0, min(count($items), $remaining_limit));
        
        $processed_ok = 0;
        $processed_failed = 0;
        $quota_blocked = 0;
        $last_id = 0;
        $start_time = time();
        $max_execution_time = ini_get('max_execution_time') ? (int)ini_get('max_execution_time') : 300;
        $time_limit = $max_execution_time > 0 ? $max_execution_time - 10 : 0; // Rezerva 10s
        
        $poi_quota = new POI_Quota_Manager();
        $charging_quota = new Charging_Quota_Manager();
        
        foreach ($items_to_process as $item) {
            // Kontrola max_execution_time
            if ($time_limit > 0 && (time() - $start_time) >= $time_limit) {
                break;
            }
            
            $queue_id = (int) $item['id'];
            $post_id = (int) $item['post_id'];
            $origin_type = isset($item['origin_type']) ? $item['origin_type'] : 'poi';
            $last_id = $queue_id;
            
            // Označit jako processing
            $queue_manager->mark_processing($queue_id);
            
            // Kontrola kvót
            if (!$poi_quota->can_use_google() && !$charging_quota->can_use_google()) {
                // Vrátit na pending bez zvýšení attempts
                global $wpdb;
                $table_name = $wpdb->prefix . 'db_nearby_queue';
                $wpdb->update(
                    $table_name,
                    array('status' => 'pending', 'dts' => current_time('mysql')),
                    array('id' => $queue_id),
                    array('%s', '%s'),
                    array('%d')
                );
                $quota_blocked++;
                continue;
            }
            
            try {
                // Určit cílový typ podle origin_type
                $target_type = ($origin_type === 'poi') ? 'charging_location' : 'poi';
                
                // Zavolat existující nearby discovery/refresh
                $recompute_job->recompute_nearby_for_origin($post_id, $target_type);
                
                // Označit jako done
                $queue_manager->mark_done($queue_id);
                $processed_ok++;
                
            } catch (\Throwable $e) {
                // Označit jako failed
                $queue_manager->mark_failed($queue_id, $e->getMessage());
                $processed_failed++;
                error_log('[POI Nearby Cron] Post ID ' . $post_id . ': ' . $e->getMessage());
            }
        }
        
        // Aktualizovat progress
        $this->update_progress($processed_ok, $processed_failed, $last_id, $limit_per_hour, $processed_last_hour);
        
        // Logovat pouze pokud bylo něco zpracováno nebo došlo k chybě
        if ($processed_ok > 0 || $processed_failed > 0) {
            error_log(sprintf(
                '[POI Nearby Cron] OK=%d, FAILED=%d, QUOTA_BLOCKED=%d',
                $processed_ok,
                $processed_failed,
                $quota_blocked
            ));
        }
    }
    
    /**
     * Získá limit per hour z option nebo default hodnotu
     */
    private function get_limit_per_hour(): int {
        $limit = get_option('db_nearby_limit_per_hour');
        if ($limit !== false) {
            return max(1, (int)$limit);
        }
        
        // Default hodnoty podle prostředí
        $is_staging = (defined('WP_ENVIRONMENT_TYPE') && WP_ENVIRONMENT_TYPE === 'staging') 
                   || (defined('WP_DEBUG') && WP_DEBUG);
        
        return $is_staging ? 2000 : 500;
    }
    
    /**
     * Získá počet zpracovaných záznamů za poslední hodinu
     */
    private function get_processed_last_hour(): int {
        $queue_manager = new POI_Nearby_Queue_Manager();
        $stats = $queue_manager->get_stats();
        return $stats['processed_last_hour'];
    }
    
    /**
     * Aktualizuje progress option
     */
    private function update_progress(int $processed_ok, int $processed_failed, int $last_id, int $limit_per_hour, int $processed_last_hour): void {
        $queue_manager = new POI_Nearby_Queue_Manager();
        $stats = $queue_manager->get_stats();
        
        $progress = array(
            'pending' => $stats['pending'],
            'processing' => $stats['processing'],
            'done_today' => $stats['done_today'],
            'failed' => $stats['failed'],
            'processed_ok' => $processed_ok,
            'processed_failed' => $processed_failed,
            'last_run' => current_time('mysql'),
            'last_id' => $last_id,
            'last_limit_reached' => false,
            'limit_per_hour' => $limit_per_hour,
            'processed_last_hour' => $processed_last_hour,
        );
        
        update_option('db_nearby_progress', $progress, false);
    }
    
    /**
     * Získá počet zpracovaných záznamů za poslední hodinu
     */
    private function get_processed_last_hour(): int {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        return (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE status = 'done' 
             AND done_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
    }
    
    /**
     * Aktualizuje progress option
     */
    private function update_progress(int $processed_ok, int $processed_failed, int $last_id): void {
        $queue_manager = new POI_Nearby_Queue_Manager();
        $stats = $queue_manager->get_stats();
        
        $progress = array(
            'pending' => $stats['pending'],
            'processing' => $stats['processing'],
            'done_today' => $stats['done_today'],
            'failed' => $stats['failed'],
            'processed_ok' => $processed_ok,
            'processed_failed' => $processed_failed,
            'last_run' => current_time('mysql'),
            'last_id' => $last_id,
            'last_limit_reached' => false,
        );
        
        update_option('db_nearby_progress', $progress, false);
    }
}

