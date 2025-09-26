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
    public function schedule_auto_processing() {
        // Spustit každou minutu pouze pokud je povoleno
        $auto_enabled = get_option('db_nearby_auto_enabled', false);
        if ($auto_enabled && !wp_next_scheduled('db_nearby_auto_process')) {
            wp_schedule_event(time(), 'db_nearby_auto_process_interval', 'db_nearby_auto_process');
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
        
        // Zkontrolovat, zda může pokračovat
        if (!$this->quota_manager->can_process_queue()) {
            $reset_time = $this->quota_manager->get_reset_time();
            error_log("[DB Nearby Auto] Nelze pokračovat, reset v " . date('Y-m-d H:i:s', $reset_time));
            
            // Naplánovat další pokus
            wp_schedule_single_event($reset_time, 'db_nearby_auto_process');
            return;
        }
        
        // Získat doporučený batch size
        $batch_size = $this->quota_manager->get_recommended_batch_size();

        if ($batch_size <= 0) {
            error_log("[DB Nearby Auto] Žádná kvóta k dispozici");
            return;
        }
        
        // Zpracovat dávku
        $result = $this->batch_processor->process_batch($batch_size);
        
        // Zaznamenat použití API
        $this->quota_manager->record_api_usage($result['processed']);
        
        error_log("[DB Nearby Auto] Zpracováno: {$result['processed']}, chyb: {$result['errors']}");
        
        // Pokud jsou ještě položky ve frontě, naplánovat další běh
        $stats = $this->queue_manager->get_stats();
        if ($stats->pending > 0) {
            $next_run = time() + MINUTE_IN_SECONDS; // další položka za minutu
            wp_schedule_single_event($next_run, 'db_nearby_auto_process');
        }
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
            'auto_enabled' => wp_next_scheduled('db_nearby_auto_process') !== false
        );
    }
    
    /**
     * Spustit automatické zpracování ručně
     */
    public function trigger_auto_processing() {
        // Zkontrolovat, zda může pokračovat
        if (!$this->quota_manager->can_process_queue()) {
            $reset_time = $this->quota_manager->get_reset_time();
            error_log("[DB Nearby Manual] Nelze pokračovat, reset v " . date('Y-m-d H:i:s', $reset_time));
            return;
        }
        
        // Získat doporučený batch size
        $batch_size = $this->quota_manager->get_recommended_batch_size();
        
        if ($batch_size <= 0) {
            error_log("[DB Nearby Manual] Žádná kvóta k dispozici");
            return;
        }
        
        // Zpracovat dávku
        $result = $this->batch_processor->process_batch($batch_size);
        
        // Zaznamenat použití API
        $this->quota_manager->record_api_usage($result['processed']);
        
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
        $this->stop_auto_processing();
        $this->schedule_auto_processing();
    }
}
