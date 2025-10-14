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
}


