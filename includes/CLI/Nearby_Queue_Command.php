<?php
declare(strict_types=1);

namespace DB\CLI;

use DB\Jobs\POI_Nearby_Queue_Manager;
use DB\Jobs\Nearby_Recompute_Job;
use DB\Jobs\POI_Quota_Manager;
use DB\Jobs\Charging_Quota_Manager;

if (!defined('ABSPATH')) { exit; }
if (!defined('WP_CLI')) { return; }

/**
 * WP-CLI: Nearby Queue processing příkazy
 */
class Nearby_Queue_Command {

    /**
     * Zpracuje dávku pending záznamů z nearby fronty
     *
     * ## OPTIONS
     *
     * [--batch=<n>]
     * : Velikost dávky (default 50)
     *
     * [--interval=<n>]
     * : Interval mezi dávkami v sekundách (default 60)
     *
     * [--limit-per-hour=<n>]
     * : Maximální počet zpracovaných záznamů za hodinu (default 500)
     *
     * [--dry-run]
     * : Simulace bez skutečného zpracování
     *
     * ## EXAMPLES
     * wp db-nearby process --batch=50 --interval=60 --limit-per-hour=500
     * wp db-nearby process --batch=10 --dry-run
     */
    public function process($args, $assoc): void {
        $batch = isset($assoc['batch']) ? max(1, (int)$assoc['batch']) : 50;
        $interval = isset($assoc['interval']) ? max(0, (int)$assoc['interval']) : 60;
        $limit_per_hour = isset($assoc['limit-per-hour']) 
            ? max(1, (int)$assoc['limit-per-hour']) 
            : $this->get_limit_per_hour();
        $dry_run = isset($assoc['dry-run']);
        
        // Kontrola, zda není fronta pozastavena
        $paused = (bool) get_option('db_nearby_paused', false);
        if ($paused) {
            \WP_CLI::warning('Fronta je pozastavena. Použijte admin UI nebo option db_nearby_paused pro obnovení.');
            return;
        }
        
        $queue_manager = new POI_Nearby_Queue_Manager();
        $recompute_job = new Nearby_Recompute_Job();
        
        // Získat pending záznamy
        $items = $queue_manager->get_pending($batch);
        
        if (empty($items)) {
            \WP_CLI::success('Žádné pending záznamy k zpracování.');
            return;
        }
        
        \WP_CLI::log(sprintf('Načteno %d pending záznamů k zpracování.', count($items)));
        
        // Kontrola rate limitu
        $stats = $queue_manager->get_stats();
        $processed_last_hour = $stats['processed_last_hour'];
        
        if ($processed_last_hour >= $limit_per_hour) {
            \WP_CLI::warning(sprintf(
                'Limit per hour (%d) dosažen. Zpracováno za poslední hodinu: %d',
                $limit_per_hour,
                $processed_last_hour
            ));
            return;
        }
        
        $remaining_limit = $limit_per_hour - $processed_last_hour;
        $items_to_process = array_slice($items, 0, min(count($items), $remaining_limit));
        
        if ($dry_run) {
            \WP_CLI::log('DRY RUN - žádné změny nebudou provedeny');
            foreach ($items_to_process as $item) {
                \WP_CLI::log(sprintf('  - Post ID: %d, Origin Type: %s', $item['post_id'], $item['origin_type'] ?? 'poi'));
            }
            \WP_CLI::success(sprintf('Simulace: zpracováno by bylo %d záznamů', count($items_to_process)));
            return;
        }
        
        $processed_ok = 0;
        $processed_failed = 0;
        $quota_blocked = 0;
        $last_id = 0;
        $start_time = time();
        $max_execution_time = ini_get('max_execution_time') ? (int)ini_get('max_execution_time') : 300;
        $time_limit = $max_execution_time > 0 ? $max_execution_time - 10 : 0; // Rezerva 10s
        
        foreach ($items_to_process as $item) {
            // Kontrola max_execution_time
            if ($time_limit > 0 && (time() - $start_time) >= $time_limit) {
                \WP_CLI::warning(sprintf('Dosažen max_execution_time limit, zpracováno %d z %d záznamů', 
                    $processed_ok + $processed_failed, count($items_to_process)));
                break;
            }
            
            $queue_id = (int) $item['id'];
            $post_id = (int) $item['post_id'];
            $origin_type = isset($item['origin_type']) ? $item['origin_type'] : 'poi';
            $last_id = $queue_id;
            
            // Označit jako processing
            $queue_manager->mark_processing($queue_id);
            
            // Kontrola kvót před zpracováním
            $poi_quota = new POI_Quota_Manager();
            $charging_quota = new Charging_Quota_Manager();
            
            // Pokud jsou kvóty vyčerpány, nepočítat jako failed, ale nechat pending
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
                error_log(sprintf('[POI Nearby Queue] Post ID %d: FAILED - %s', $post_id, $e->getMessage()));
            }
            
            // Pauza mezi zpracováním
            if ($interval > 0 && $processed_ok + $processed_failed < count($items_to_process)) {
                sleep($interval);
            }
        }
        
        // Aktualizovat progress
        $this->update_progress($processed_ok, $processed_failed, $last_id, $limit_per_hour, $processed_last_hour);
        
        \WP_CLI::success(sprintf(
            'Zpracováno: OK=%d, FAILED=%d, QUOTA_BLOCKED=%d',
            $processed_ok,
            $processed_failed,
            $quota_blocked
        ));
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
     * Zobrazí statistiky fronty
     *
     * ## EXAMPLES
     * wp db-nearby stats
     */
    public function stats($args, $assoc): void {
        $queue_manager = new POI_Nearby_Queue_Manager();
        $stats = $queue_manager->get_stats();
        $progress = get_option('db_nearby_progress', array());
        $paused = (bool) get_option('db_nearby_paused', false);
        $limit_per_hour = $this->get_limit_per_hour();
        
        $table_data = array(
            array('Metrika', 'Hodnota'),
            array('Pending', $stats['pending']),
            array('Processing', $stats['processing']),
            array('Done (dnes)', $stats['done_today']),
            array('Failed', $stats['failed']),
            array('Zpracováno (24h)', $stats['processed_last_24h']),
            array('Zpracováno (1h)', $stats['processed_last_hour']),
            array('Limit per hour', $limit_per_hour),
            array('Pozastaveno', $paused ? 'Ano' : 'Ne'),
            array('Poslední běh', isset($progress['last_run']) ? $progress['last_run'] : 'Nikdy'),
        );
        
        \WP_CLI\Utils\format_items('table', $table_data, array('Metrika', 'Hodnota'));
        
        if (!empty($progress)) {
            \WP_CLI::log('');
            \WP_CLI::log('Poslední běh:');
            \WP_CLI::log(json_encode($progress, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }
}

