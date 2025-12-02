<?php

namespace DB\Jobs;

use DB\Charging_Discovery;
use DB\Util\Places_Enrichment_Service;

if (!defined('ABSPATH')) { exit; }

class Charging_Discovery_Batch_Processor {
    private $queue;
    private $enrichment_service;

    public function __construct() {
        $this->queue = new Charging_Discovery_Queue_Manager();
        $this->enrichment_service = Places_Enrichment_Service::get_instance();
    }

    public function process_batch(int $limit = 3): array {
        // Menší default dávka pro testování (3 místo 10)
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
                
                // DŮLEŽITÉ: Google API se NEPOUŽÍVÁ v batch processoru automaticky
                // Google API se volá pouze on-demand při kliknutí na bod na mapě
                // Batch processor pouze kontroluje, zda už máme Google ID
                $useGoogle = ($existingGoogle === '') && $this->enrichment_service->is_enabled();
                $useOcm = false; // OCM disabled

                // Pokud už máme Google ID, přeskočit
                if ($existingGoogle !== '') {
                    $this->queue->mark_completed($id);
                    $processed++;
                    continue;
                }

                // Pokud nemáme Google ID, označit jako "potřebuje on-demand zpracování"
                // Google API se zavolá až při kliknutí na bod na mapě
                $this->queue->mark_completed($id);
                $processed++;
                continue;

                // PŮVODNÍ KÓD - ZAKÁZÁNO (Google API se volá pouze on-demand)
                /*
                if (!$useGoogle && !$useOcm) {
                    global $wpdb;
                    $wpdb->update($wpdb->prefix . 'db_charging_discovery_queue', array('status' => 'pending'), array('id' => $id), array('%s'), array('%d'));
                    continue;
                }

                $attempted++;
                $result = $service->discoverForCharging($stationId, false, false, $useGoogle, $useOcm);

                if ($useGoogle) {
                    $usedGoogle++;
                }
                if ($useOcm) {
                    $usedOcm++;
                }
                */

                // PŮVODNÍ KÓD - ZAKÁZÁNO (Google API se volá pouze on-demand)
                // Google API se volá pouze při kliknutí na bod na mapě přes on-demand processor
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
