<?php
/**
 * Nearby Auto Processor - Automatické zpracování fronty
 * @package DobityBaterky
 */

namespace DB\Jobs;

class Nearby_Auto_Processor {
    
    private $queue_manager;
    private $batch_processor;
    private $quota_manager;
    
    public function __construct() {
        $this->queue_manager = new Nearby_Queue_Manager();
        $this->batch_processor = new Nearby_Batch_Processor();
        $this->quota_manager = new API_Quota_Manager();
        
        // Registrace WordPress cron hooku
        add_action('db_nearby_auto_process', array($this, 'process_queue_auto'));
        
        // Registrace custom cron intervalu
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Registrace hooku pro pravidelné spouštění
        add_action('init', array($this, 'schedule_auto_processing'));
    }
    
    /**
     * Přidat custom cron interval
     */
    public function add_cron_interval($schedules) {
        $schedules['db_nearby_auto_process_interval'] = array(
            'interval' => MINUTE_IN_SECONDS, // 1 minuta
            'display' => __('Každou minutu')
        );
        return $schedules;
    }
    
    /**
     * Naplánovat automatické zpracování
     */
    public function schedule_auto_processing($force = false) {
        $auto_enabled = (bool)get_option('db_nearby_auto_enabled', false);

        if (!$auto_enabled) {
            $this->stop_auto_processing();
            return;
        }

        $next_run = wp_next_scheduled('db_nearby_auto_process');

        if ($force && $next_run) {
            $this->stop_auto_processing();
            $next_run = false;
        }

        if (!$next_run) {
            $first_recurring_run = time() + MINUTE_IN_SECONDS;
            wp_schedule_event($first_recurring_run, 'db_nearby_auto_process_interval', 'db_nearby_auto_process');
            // Naplánovat okamžitý jednorázový běh, aby se zpracovala první položka hned
            wp_schedule_single_event(time(), 'db_nearby_auto_process');
        }
    }
    
    /**
     * Automatické zpracování fronty
     */
    public function process_queue_auto() {
        // Zkontrolovat, zda je automatické zpracování povoleno
        $auto_enabled = get_option('db_nearby_auto_enabled', false);
        if (!$auto_enabled) {
            error_log("[DB Nearby Auto] Automatické zpracování je vypnuto");
            return;
        }
        
        $result = $this->trigger_auto_processing();

        if (!$result['success']) {
            if (!empty($result['reset_at'])) {
                wp_schedule_single_event((int)$result['reset_at'], 'db_nearby_auto_process');
            }
            error_log("[DB Nearby Auto] " . ($result['message'] ?? 'Žádné zpracování neproběhlo'));
            return;
        }

        error_log("[DB Nearby Auto] Zpracováno: {$result['processed']}, chyb: {$result['errors']}");
    }
    
    /**
     * Získat stav automatického zpracování
     */
    public function get_auto_status() {
        $quota_stats = $this->quota_manager->get_usage_stats();
        $queue_stats = $this->queue_manager->get_stats();
        
        $next_run = wp_next_scheduled('db_nearby_auto_process');
        
        return array(
            'queue_stats' => $queue_stats,
            'quota_stats' => $quota_stats,
            'next_run' => $next_run ? date('Y-m-d H:i:s', $next_run) : null,
            'auto_enabled' => (bool)get_option('db_nearby_auto_enabled', false),
            'scheduled' => $next_run !== false
        );
    }
    
    /**
     * Spustit automatické zpracování ručně
     */
    public function trigger_auto_processing() {
        $result = array(
            'success' => false,
            'processed' => 0,
            'errors' => 0,
            'message' => ''
        );

        if (!$this->quota_manager->can_process_queue()) {
            $reset_time = $this->quota_manager->get_reset_time();
            $result['message'] = 'Nedostatečná API kvóta';
            $result['reset_at'] = $reset_time;
            error_log("[DB Nearby Manual] Nelze pokračovat, reset v " . date('Y-m-d H:i:s', $reset_time));
            $result['queue_stats'] = $this->queue_manager->get_stats();
            return $result;
        }

        $recommended = $this->quota_manager->get_recommended_batch_size();

        if ($recommended <= 0) {
            $result['message'] = 'Žádná kvóta k dispozici';
            error_log("[DB Nearby Manual] Žádná kvóta k dispozici");
            $result['queue_stats'] = $this->queue_manager->get_stats();
            return $result;
        }

        $batch_size = max(1, $recommended);

        $batch_result = $this->batch_processor->process_batch($batch_size);

        if (!empty($batch_result['processed'])) {
            $this->quota_manager->record_api_usage($batch_result['processed']);
        }

        $result = array_merge($result, $batch_result);
        $result['success'] = $batch_result['processed'] > 0;
        if (!empty($batch_result['retry_after'])) {
            $result['reset_at'] = (int)$batch_result['retry_after'];
        }
        $result['queue_stats'] = $this->queue_manager->get_stats();

        error_log("[DB Nearby Manual] Zpracováno: {$result['processed']}, chyb: {$result['errors']}");

        return $result;
    }

    /**
     * Zastavit automatické zpracování
     */
    public function stop_auto_processing() {
        wp_clear_scheduled_hook('db_nearby_auto_process');
    }

    /**
     * Restartovat automatické zpracování
     */
    public function restart_auto_processing() {
        $this->schedule_auto_processing(true);
    }
}
