<?php

namespace DB\Jobs;

use DB\Charging_Discovery;

if (!defined('ABSPATH')) { exit; }

class Charging_Discovery_Batch_Processor {
    private $queue;
    private $quota;

    public function __construct() {
        $this->queue = new Charging_Discovery_Queue_Manager();
        $this->quota = new Charging_Quota_Manager();
    }

    public function process_batch(int $limit = 10): array {
        $items = $this->queue->get_pending($limit);
        $processed = 0;
        $errors = 0;
        $usedGoogle = 0;
        $usedOcm = 0;
        $attempted = 0;
        $service = new Charging_Discovery();

        foreach ($items as $row) {
            $id = (int) $row->id;
            $stationId = (int) $row->station_id;
            $this->queue->mark_processing($id);

            try {
                $existingGoogle = (string) get_post_meta($stationId, '_charging_google_place_id', true);
                $existingOcm = (string) get_post_meta($stationId, '_openchargemap_id', true);
                $useGoogle = ($existingGoogle === '') && $this->quota->can_use_google();
                $useOcm = ($existingOcm === '') && $this->quota->can_use_ocm();

                if (!$useGoogle && !$useOcm) {
                    global $wpdb;
                    $wpdb->update($wpdb->prefix . 'db_charging_discovery_queue', array('status' => 'pending'), array('id' => $id), array('%s'), array('%d'));
                    continue;
                }

                $attempted++;
                $result = $service->discoverForCharging($stationId, false, false, $useGoogle, $useOcm);

                if ($useGoogle) {
                    $this->quota->record_google(1);
                    $usedGoogle++;
                }
                if ($useOcm) {
                    $this->quota->record_ocm(1);
                    $usedOcm++;
                }

                $matchedGoogle = $result['google'] ?? null;
                $matchedOcm = $result['open_charge_map'] ?? null;

                if ($matchedGoogle || $matchedOcm) {
                    if ($matchedGoogle) {
                        update_post_meta($stationId, '_charging_google_place_id', $matchedGoogle);
                        delete_post_meta($stationId, '_charging_google_cache');
                        delete_post_meta($stationId, '_charging_google_cache_expires');
                    }
                    if ($matchedOcm) {
                        update_post_meta($stationId, '_openchargemap_id', $matchedOcm);
                        delete_post_meta($stationId, '_charging_ocm_cache');
                        delete_post_meta($stationId, '_charging_ocm_cache_expires');
                    }
                    $this->queue->mark_completed($id);
                    $processed++;
                } else {
                    $this->queue->mark_failed_or_retry($id, 'no_match');
                }
            } catch (\Throwable $e) {
                $this->queue->mark_failed_or_retry($id, $e->getMessage());
                $errors++;
            }
        }

        return array(
            'processed' => $processed,
            'errors' => $errors,
            'usedGoogle' => $usedGoogle,
            'usedOpenChargeMap' => $usedOcm,
            'attempted' => $attempted,
        );
    }
}
