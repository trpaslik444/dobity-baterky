<?php
declare(strict_types=1);

namespace DB\CLI;

use DB\POI_Discovery;
use DB\POI_Admin;

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
		$res = $svc->discoverForPoi($poiId, $save, $withTA, true);
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

	/**
	 * Importuje POI z CSV souboru pomocí stejné logiky jako admin rozhraní.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Absolutní nebo relativní cesta k CSV souboru.
	 *
	 * [--log-every=<n>]
	 * : Po kolika záznamech zalogovat průběh (default 500).
	 *
	 * ## EXAMPLES
	 * wp db-poi import-csv /var/www/html/wp-content/uploads/all_pois_unique-1.csv --log-every=1000
	 */
	public function import_csv($args, $assoc): void {
		$file = $args[0] ?? '';
		if (!$file) {
			\WP_CLI::error('Zadejte cestu k CSV souboru.');
			return;
		}

		$resolved = realpath($file);
		$path = $resolved !== false ? $resolved : $file;
		if (!is_readable($path)) {
			$alt = trailingslashit(ABSPATH) . ltrim($file, '/');
			if (is_readable($alt)) {
				$path = $alt;
			} else {
				\WP_CLI::error('Soubor nelze načíst: ' . $file);
				return;
			}
		}

		$logEvery = isset($assoc['log-every']) ? max(1, (int)$assoc['log-every']) : 500;

		if (!class_exists(POI_Admin::class)) {
			\WP_CLI::error('POI admin služba není dostupná.');
			return;
		}

		$admin = POI_Admin::get_instance();
		$handle = fopen($path, 'r');
		if (!$handle) {
			\WP_CLI::error('Nepodařilo se otevřít soubor: ' . $path);
			return;
		}

		// Nastavit flag, že probíhá import (zabrání spuštění nearby recompute)
		if (function_exists('\DB\db_set_poi_import_running')) {
			\DB\db_set_poi_import_running(true);
		}
		$flagSet = true;

		try {
			$result = $admin->import_from_stream($handle, [
				'log_every' => $logEvery,
				'log_callback' => function(array $stats) {
					\WP_CLI::log(sprintf(
						'Řádek %d | nové: %d | aktualizované: %d | chyby: %d | prázdné: %d',
						$stats['row'],
						$stats['imported'],
						$stats['updated'],
						$stats['errors'],
						$stats['skipped']
					));
				},
			]);
		} catch (\Throwable $e) {
			fclose($handle);
			\WP_CLI::error($e->getMessage());
			return;
		} finally {
			// Vždy vymazat flag, i když došlo k chybě
			if ($flagSet && function_exists('\DB\db_set_poi_import_running')) {
				\DB\db_set_poi_import_running(false);
			}
		}

		fclose($handle);

		// Zařadit všechna importovaná/aktualizovaná POI do fronty pro nearby recompute
		if (!empty($result['processed_poi_ids']) && class_exists('\DB\Jobs\Nearby_Queue_Manager')) {
			$queue_manager = new \DB\Jobs\Nearby_Queue_Manager();
			$enqueued_count = 0;
			$affected_count = 0;
			foreach ($result['processed_poi_ids'] as $poi_id) {
				// POI potřebuje najít nearby charging locations
				if ($queue_manager->enqueue($poi_id, 'charging_location', 1)) {
					$enqueued_count++;
				}
				// Zařadit charging locations v okruhu pro aktualizaci jejich nearby POI seznamů
				$affected_count += $queue_manager->enqueue_affected_points($poi_id, 'poi');
			}
			\WP_CLI::log(sprintf('Zařazeno %d POI do fronty pro nearby recompute, %d affected charging locations', $enqueued_count, $affected_count));
		}

		if (!empty($result['errors'])) {
			\WP_CLI::warning(sprintf('Import dokončen s %d hlášenými problémy.', count($result['errors'])));
			foreach (array_slice($result['errors'], 0, 20) as $err) {
				\WP_CLI::log(' - ' . $err);
			}
			if (count($result['errors']) > 20) {
				\WP_CLI::log(sprintf('... a dalších %d chyb', count($result['errors']) - 20));
			}
		}

		\WP_CLI::success(sprintf(
			'Hotovo. Nové: %d | aktualizované: %d | zpracované řádky: %d | přeskočené prázdné: %d',
			$result['imported'],
			$result['updated'],
			$result['total_rows'],
			$result['skipped_rows']
		));
	}
}


