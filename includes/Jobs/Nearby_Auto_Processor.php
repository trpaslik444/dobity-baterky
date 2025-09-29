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

        add_action('init', array($this, 'maybe_bootstrap_worker'));
    }
    public function maybe_bootstrap_worker() {
        if (!get_option('db_nearby_auto_enabled', false)) {
            return;
        }

        Nearby_Worker::dispatch();
    }

    /**
     * Získat stav automatického zpracování
     */
    public function get_auto_status() {
        $quota_stats = $this->quota_manager->get_usage_stats();
        $queue_stats = $this->queue_manager->get_stats();
        return array(
            'queue_stats' => $queue_stats,
            'quota_stats' => $quota_stats,
            'next_run' => 'asynchronous',
            'auto_enabled' => get_option('db_nearby_auto_enabled', false)
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
        
        if (($this->queue_manager->get_stats()->pending ?? 0) > 0) {
            Nearby_Worker::dispatch();
        }

        error_log("[DB Nearby Manual] Zpracováno: {$result['processed']}, chyb: {$result['errors']}");
        
        return $result;
    }
    
    /**
     * Zastavit automatické zpracování
     */
    public function stop_auto_processing() {
        // Nic dalšího není potřeba – option se nastaví mimo třídu
    }
    
    /**
     * Restartovat automatické zpracování
     */
    public function restart_auto_processing() {
        Nearby_Worker::dispatch();
    }
}
