<?php

namespace DB\Jobs;

use DB\POI_Discovery;

class POI_Discovery_Batch_Processor {

	private $queue;
	private $quota;

	public function __construct() {
		$this->queue = new POI_Discovery_Queue_Manager();
		$this->quota = new POI_Quota_Manager();
	}

	/**
	 * Zpracovat dávku fronty
	 */
    public function process_batch(int $limit = 10): array {
		$items = $this->queue->get_pending($limit);
        $processed = 0; $errors = 0; $usedG = 0; $usedTA = 0; $attempted = 0;
		$svc = new POI_Discovery();
		foreach ($items as $row) {
			$id = (int)$row->id; $poi_id = (int)$row->poi_id;
			$this->queue->mark_processing($id);
			try {
			$useGoogle = $this->quota->can_use_google();
			$useTA = $this->quota->can_use_tripadvisor();
			
			// If both quotas are exhausted, skip this item without consuming attempts
			if (!$useGoogle && !$useTA) {
				// Reset to pending status without incrementing attempts
				global $wpdb;
				$wpdb->update($wpdb->prefix . 'db_poi_discovery_queue', 
					array('status' => 'pending'), 
					array('id' => $id), 
					array('%s'), 
					array('%d')
				);
				continue;
			}
			
			$attempted++; // Count only items that will actually be processed
			
			$withTA = $useTA; // Only use Tripadvisor when quota allows
			$res = $svc->discoverForPoi($poi_id, false, $withTA, $useGoogle);
                // Record quota usage for all API calls, not just successful matches
                if ($useGoogle) { $this->quota->record_google(1); $usedG++; }
                if ($withTA) { $this->quota->record_tripadvisor(1); $usedTA++; }

				$matched = $res['google_place_id'] ?? ($res['tripadvisor_location_id'] ?? null);
				if ($matched) {
					// heuristika skóre (zatím jednoduchá: pokud máme ID, považuj za confident)
					$score = 1.0;
					// Save discovered IDs using the same result (avoid double API calls)
					if (!empty($res['google_place_id'])) {
						update_post_meta($poi_id, '_poi_google_place_id', $res['google_place_id']);
						delete_post_meta($poi_id, '_poi_google_cache');
						delete_post_meta($poi_id, '_poi_google_cache_expires');
					}
					if (!empty($res['tripadvisor_location_id'])) {
						update_post_meta($poi_id, '_poi_tripadvisor_location_id', $res['tripadvisor_location_id']);
						delete_post_meta($poi_id, '_poi_tripadvisor_cache');
						delete_post_meta($poi_id, '_poi_tripadvisor_cache_expires');
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
        return array('processed' => $processed, 'errors' => $errors, 'usedGoogle' => $usedG, 'usedTripadvisor' => $usedTA, 'attempted' => $attempted);
	}
}


