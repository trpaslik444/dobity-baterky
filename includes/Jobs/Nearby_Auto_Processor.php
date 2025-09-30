<?php

namespace DB\Jobs;

class Nearby_Auto_Processor {

    private $queue_manager;
    private $batch_processor;
    private $quota_manager;

    public function __construct() {
        $this->queue_manager = new Nearby_Queue_Manager();
        $this->batch_processor = new Nearby_Batch_Processor();
        $this->quota_manager = new API_Quota_Manager();

        add_action('db_nearby_auto_process', array($this, 'process_queue_auto'));
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        add_action('init', array($this, 'maybe_bootstrap_worker'));
    }

    public function add_cron_interval($schedules) {
        $schedules['db_nearby_auto_process_interval'] = array(
            'interval' => MINUTE_IN_SECONDS,
            'display' => __('Každou minutu')
        );
        return $schedules;
    }

    public function maybe_bootstrap_worker() {
        if (!get_option('db_nearby_auto_enabled', false)) {
            return;
        }

        $this->schedule_auto_processing();
        Nearby_Worker::dispatch();
    }

    public function schedule_auto_processing($force = false) {
        if (!get_option('db_nearby_auto_enabled', false)) {
            return;
        }

        if ($force) {
            wp_clear_scheduled_hook('db_nearby_auto_process');
        }

        if (!wp_next_scheduled('db_nearby_auto_process')) {
            wp_schedule_event(time() + MINUTE_IN_SECONDS, 'db_nearby_auto_process_interval', 'db_nearby_auto_process');
        }
    }

    public function process_queue_auto() {
        if (!get_option('db_nearby_auto_enabled', false)) {
            $this->stop_auto_processing();
            error_log('[DB Nearby Auto] Automatické zpracování je vypnuto');
            return;
        }

        Nearby_Worker::dispatch();

        $stats = $this->queue_manager->get_stats();
        if (($stats->pending ?? 0) > 0) {
            $next = $this->quota_manager->schedule_next_run();
            if ($next && $next > time()) {
                wp_schedule_single_event($next, 'db_nearby_auto_process');
            }
        }
    }

    public function get_auto_status() {
        $quota_stats = $this->quota_manager->get_usage_stats();
        $queue_stats = $this->queue_manager->get_stats();
        $next_run_ts = wp_next_scheduled('db_nearby_auto_process');

        return array(
            'queue_stats' => $queue_stats,
            'quota_stats' => $quota_stats,
            'next_run' => $next_run_ts ? date('Y-m-d H:i:s', $next_run_ts) : null,
            'auto_enabled' => get_option('db_nearby_auto_enabled', false)
        );
    }

    public function trigger_auto_processing() {
        if (!$this->quota_manager->can_process_queue()) {
            $reset_time = $this->quota_manager->get_reset_time();
            error_log('[DB Nearby Manual] Nelze pokračovat, reset v ' . date('Y-m-d H:i:s', $reset_time));
            return;
        }

        $batch_size = $this->quota_manager->get_recommended_batch_size();
        if ($batch_size <= 0) {
            error_log('[DB Nearby Manual] Žádná kvóta k dispozici');
            return;
        }

        $result = $this->batch_processor->process_batch($batch_size);
        $this->quota_manager->record_api_usage($result['processed']);

        if (($this->queue_manager->get_stats()->pending ?? 0) > 0) {
            $next = $this->quota_manager->schedule_next_run();
            if ($next && $next > time()) {
                wp_schedule_single_event($next, 'db_nearby_auto_process');
            }
            Nearby_Worker::dispatch();
        }

        error_log('[DB Nearby Manual] Zpracováno: ' . $result['processed'] . ', chyb: ' . $result['errors']);

        return $result;
    }

    public function stop_auto_processing() {
        wp_clear_scheduled_hook('db_nearby_auto_process');
    }

    public function restart_auto_processing() {
        $this->schedule_auto_processing(true);
        Nearby_Worker::dispatch();
    }
}
