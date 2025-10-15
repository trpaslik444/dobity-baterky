<?php
declare(strict_types=1);

namespace DB\CLI;

use DB\POI_Discovery;

if (!defined('ABSPATH')) { exit; }
if (!defined('WP_CLI')) { return; }

/**
 * WP-CLI: POI discovery příkazy
 */
class POI_Discovery_Command {

	/**
 	 * Najde externí ID pro jedno POI.
 	 *
 	 * ## OPTIONS
 	 *
 	 * <id>
 	 * : ID POI
 	 *
 	 * [--save]
 	 * : Uloží nalezená ID do post meta
 	 *
 	 * [--with-tripadvisor]
 	 * : Zkusí i Tripadvisor
 	 *
 	 * ## EXAMPLES
 	 * wp db-poi discover-one 123 --save --with-tripadvisor
 	 */
	public function discover_one($args, $assoc): void {
		$poiId = (int)($args[0] ?? 0);
		$save = isset($assoc['save']);
		$withTA = isset($assoc['with-tripadvisor']);
		if (!$poiId) {
			\WP_CLI::error('Missing POI ID');
			return;
		}
		$svc = new POI_Discovery();
		$res = $svc->discoverForPoi($poiId, $save, $withTA);
		\WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		\WP_CLI::success('Done');
	}

	/**
 	 * Spustí discovery pro dávku POI bez Google Place ID.
 	 *
 	 * ## OPTIONS
 	 * [--limit=<n>]
 	 * : Max počet záznamů (default 50)
 	 *
 	 * [--save]
 	 * : Uloží nalezená ID
 	 *
 	 * [--with-tripadvisor]
 	 * : Zahrne i Tripadvisor
 	 *
 	 * ## EXAMPLES
 	 * wp db-poi discover-batch --limit=100 --save --with-tripadvisor
 	 */
	public function discover_batch($args, $assoc): void {
		$limit = isset($assoc['limit']) ? (int)$assoc['limit'] : 50;
		$save = isset($assoc['save']);
		$withTA = isset($assoc['with-tripadvisor']);
		$svc = new POI_Discovery();
		$res = $svc->discoverBatch($limit, $save, $withTA);
		\WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		\WP_CLI::success('Batch done');
	}

	/**
	 * Zobrazí stav měsíčních kvót a aktuální využití (Google/Tripadvisor)
	 *
	 * ## EXAMPLES
	 * wp db-poi quotas
	 */
	public function quotas($args, $assoc): void {
		$qm = new \DB\Jobs\POI_Quota_Manager();
		$st = $qm->get_status();
		\WP_CLI::log(json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
		\WP_CLI::success('OK');
	}

	/**
	 * Zařadí 10 posledních publikovaných POI bez Google Place ID do fronty
	 *
	 * ## EXAMPLES
	 * wp db-poi enqueue-ten
	 */
	public function enqueue_ten($args, $assoc): void {
		$qm = new \DB\Jobs\POI_Discovery_Queue_Manager();
		$ids = get_posts(array(
			'post_type' => 'poi',
			'post_status' => 'publish',
			'fields' => 'ids',
			'posts_per_page' => 10,
			'orderby' => 'date', 'order' => 'DESC',
			'meta_query' => array(
				'relation' => 'OR',
				array('key' => '_poi_google_place_id', 'compare' => 'NOT EXISTS'),
				array('key' => '_poi_google_place_id', 'value' => '', 'compare' => '=')
			)
		));
		$added = 0; $skipped = 0;
		foreach ((array)$ids as $pid) { if ($qm->enqueue((int)$pid, 0)) $added++; else $skipped++; }
		\WP_CLI::log(json_encode(array('enqueued' => $added, 'skipped' => $skipped), JSON_UNESCAPED_UNICODE));
		\WP_CLI::success('Done');
	}
}


