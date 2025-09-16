<?php

declare(strict_types=1);

namespace DB\CLI;

if (!defined('ABSPATH')) {
	exit;
}

if (!defined('WP_CLI')) {
	return;
}

/**
 * WP-CLI commands for provider enrichment (desktop app links)
 */
class Provider_Enrichment_Command {

	/**
	 * Test enrichment: list providers and propose desktop app links.
	 *
	 * ## OPTIONS
	 *
	 * [--limit=<number>]
	 * : Process only first N providers.
	 *
	 * [--save]
	 * : Persist discovered links into term meta.
	 *
	 * ## EXAMPLES
	 *
	 *	wp db-providers test --limit=20
	 *	wp db-providers test --save
	 */
	public function test($args, $assoc_args): void {
		$limit = isset($assoc_args['limit']) ? (int)$assoc_args['limit'] : null;
		$save = isset($assoc_args['save']);

		$terms = get_terms([
			'taxonomy' => 'provider',
			'hide_empty' => false,
		]);

		if (is_wp_error($terms)) {
			\WP_CLI::error('Failed to get providers: ' . $terms->get_error_message());
			return;
		}

		if (empty($terms)) {
			\WP_CLI::success('No providers found.');
			return;
		}

		$count = 0;
		\WP_CLI::log(sprintf('Processing %d providers%s...', count($terms), $limit ? " (limit $limit)" : ''));

		foreach ($terms as $term) {
			if ($limit !== null && $count >= $limit) break;

			$term_id = (int)$term->term_id;
			$name = (string)$term->name;
			$friendly = (string)get_term_meta($term_id, 'provider_friendly_name', true);
			$logo = (string)get_term_meta($term_id, 'provider_logo', true);
			$existing_ios = (string)get_term_meta($term_id, 'provider_ios_app_url', true);
			$existing_android = (string)get_term_meta($term_id, 'provider_android_app_url', true);

			$displayName = $friendly !== '' ? $friendly : $name;

			$iosUrl = $this->find_ios_app_url($displayName) ?? '';
			$androidUrl = $this->build_android_search_url($displayName);

			\WP_CLI::log('');
			\WP_CLI::log(sprintf('— Provider: %s (term_id=%d)', $displayName, $term_id));
			if ($logo !== '') {
				\WP_CLI::log('  Logo: ' . $logo);
			}
			if ($existing_ios !== '' || $existing_android !== '') {
				\WP_CLI::log('  Existing:');
				if ($existing_ios !== '') \WP_CLI::log('    iOS:     ' . $existing_ios);
				if ($existing_android !== '') \WP_CLI::log('    Android: ' . $existing_android);
			}
			\WP_CLI::log('  Proposed:');
			\WP_CLI::log('    iOS:     ' . ($iosUrl !== '' ? $iosUrl : '(not found)'));
			\WP_CLI::log('    Android: ' . $androidUrl);

			if ($save) {
				if ($iosUrl !== '') {
					update_term_meta($term_id, 'provider_ios_app_url', esc_url_raw($iosUrl));
				}
				if ($androidUrl !== '') {
					update_term_meta($term_id, 'provider_android_app_url', esc_url_raw($androidUrl));
				}
				update_term_meta($term_id, 'provider_last_enriched_at', current_time('mysql'));
				\WP_CLI::log('  Saved.');
			}

			$count++;
		}

		\WP_CLI::success("Processed $count providers");
	}

	private function find_ios_app_url(string $query): ?string {
		$endpoint = 'https://itunes.apple.com/search';
		$params = [
			'term' => $query,
			'entity' => 'software',
			'country' => 'CZ',
			'limit' => 5,
			'lang' => 'cs_cz',
		];
		$url = $endpoint . '?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986);

		$response = wp_remote_get($url, [
			'timeout' => 15,
			'user-agent' => 'DobityBaterky/1.0 (+https://dobitybaterky.cz)'
		]);
		if (is_wp_error($response)) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code !== 200) {
			return null;
		}
		$body = (string) wp_remote_retrieve_body($response);
		$data = json_decode($body, true);
		if (!is_array($data) || empty($data['results']) || !is_array($data['results'])) {
			return null;
		}

		// Prefer the first result for now.
		$first = $data['results'][0] ?? null;
		if (is_array($first) && isset($first['trackViewUrl'])) {
			return (string) $first['trackViewUrl'];
		}
		return null;
	}

	private function build_android_search_url(string $query): string {
		return 'https://play.google.com/store/search?c=apps&q=' . rawurlencode($query);
	}

	/**
	 * Export providers to CSV file
	 *
	 * ## OPTIONS
	 *
	 * [--file=<filename>]
	 * : Output filename (default: providers_export_YYYY-MM-DD_HH-MM-SS.csv)
	 *
	 * ## EXAMPLES
	 *
	 *     wp db-providers export-csv
	 *     wp db-providers export-csv --file=providers.csv
	 */
	public function export_csv($args, $assoc_args): void {
		$filename = $assoc_args['file'] ?? 'providers_export_' . date('Y-m-d_H-i-s') . '.csv';
		
		$providers = get_terms([
			'taxonomy' => 'provider',
			'hide_empty' => false,
		]);

		if (is_wp_error($providers)) {
			\WP_CLI::error('Failed to get providers: ' . $providers->get_error_message());
			return;
		}

		if (empty($providers)) {
			\WP_CLI::success('No providers found.');
			return;
		}

		\WP_CLI::log(sprintf('Exporting %d providers to %s...', count($providers), $filename));

		$output = fopen($filename, 'w');
		if (!$output) {
			\WP_CLI::error("Cannot create file: $filename");
			return;
		}

		// CSV header
		$headers = [
			'term_id', 'name', 'friendly_name', 'logo', 
			'ios_app_url', 'android_app_url', 'website', 'notes', 'source_url'
		];
		fputcsv($output, $headers);

		// Data
		foreach ($providers as $provider) {
			$row = [
				$provider->term_id,
				$provider->name,
				get_term_meta($provider->term_id, 'provider_friendly_name', true),
				get_term_meta($provider->term_id, 'provider_logo', true),
				get_term_meta($provider->term_id, 'provider_ios_app_url', true),
				get_term_meta($provider->term_id, 'provider_android_app_url', true),
				get_term_meta($provider->term_id, 'provider_website', true),
				get_term_meta($provider->term_id, 'provider_notes', true),
				get_term_meta($provider->term_id, 'provider_source_url', true),
			];
			fputcsv($output, $row);
		}

		fclose($output);
		\WP_CLI::success("Export completed: $filename");
	}

	/**
	 * Import providers from CSV file
	 *
	 * ## OPTIONS
	 *
	 * --file=<filename>
	 * : CSV file to import
	 *
	 * [--mode=<mode>]
	 * : Import mode: update, merge, or replace (default: update)
	 *
	 * ## EXAMPLES
	 *
	 *     wp db-providers import-csv --file=providers_enriched.csv
	 *     wp db-providers import-csv --file=providers.csv --mode=merge
	 */
	public function import_csv($args, $assoc_args): void {
		if (!isset($assoc_args['file'])) {
			\WP_CLI::error('Please specify --file=<filename>');
			return;
		}

		$filename = $assoc_args['file'];
		$mode = $assoc_args['mode'] ?? 'update';

		if (!file_exists($filename)) {
			\WP_CLI::error("File not found: $filename");
			return;
		}

		\WP_CLI::log("Importing from: $filename (mode: $mode)");

		$data = $this->parse_csv($filename);
		if (empty($data)) {
			\WP_CLI::error('CSV file is empty or invalid');
			return;
		}

		\WP_CLI::log(sprintf('Parsed %d rows from CSV', count($data)));

		$stats = [
			'total' => count($data),
			'updated' => 0,
			'created' => 0,
			'errors' => 0,
			'error_messages' => []
		];

		foreach ($data as $row) {
			try {
				if ($mode === 'update' && !empty($row['term_id'])) {
					$this->update_provider($row);
					$stats['updated']++;
				} else {
					$this->create_or_update_provider($row);
					$stats['updated']++;
				}
			} catch (\Exception $e) {
				$stats['errors']++;
				$stats['error_messages'][] = "Row " . ($stats['updated'] + $stats['created'] + $stats['errors']) . ": " . $e->getMessage();
			}
		}

		\WP_CLI::success("Import completed");
		\WP_CLI::log("Total: " . $stats['total']);
		\WP_CLI::log("Updated: " . $stats['updated']);
		\WP_CLI::log("Created: " . $stats['created']);
		\WP_CLI::log("Errors: " . $stats['errors']);

		if (!empty($stats['error_messages'])) {
			\WP_CLI::warning("Errors encountered:");
			foreach (array_slice($stats['error_messages'], 0, 5) as $error) {
				\WP_CLI::warning("  - $error");
			}
		}
	}

	private function parse_csv(string $filepath): array {
		$data = [];
		
		if (($handle = fopen($filepath, 'r')) === false) {
			return [];
		}
		
		$headers = fgetcsv($handle);
		if (!$headers) {
			fclose($handle);
			return [];
		}
		
		while (($row = fgetcsv($handle)) !== false) {
			if (count($row) !== count($headers)) {
				continue; // Skip malformed rows
			}
			
			$row_data = array_combine($headers, $row);
			if ($row_data) {
				$data[] = $row_data;
			}
		}
		
		fclose($handle);
		return $data;
	}

	private function update_provider(array $row): void {
		$term_id = (int) $row['term_id'];
		
		if (!$term_id || !term_exists($term_id, 'provider')) {
			throw new \Exception("Term ID $term_id does not exist");
		}

		$this->update_provider_meta($term_id, $row);
	}

	private function create_or_update_provider(array $row): void {
		$name = sanitize_text_field($row['name'] ?? '');
		if (empty($name)) {
			throw new \Exception("Provider name is required");
		}

		$term = term_exists($name, 'provider');
		if ($term) {
			$term_id = is_array($term) ? $term['term_id'] : $term;
		} else {
			$term = wp_insert_term($name, 'provider');
			if (is_wp_error($term)) {
				throw new \Exception("Error creating term: " . $term->get_error_message());
			}
			$term_id = is_array($term) ? $term['term_id'] : $term;
		}

		$this->update_provider_meta($term_id, $row);
	}

	private function update_provider_meta(int $term_id, array $row): void {
		$meta_fields = [
			'provider_friendly_name' => 'friendly_name',
			'provider_logo' => 'logo',
			'provider_ios_app_url' => 'ios_app_url',
			'provider_android_app_url' => 'android_app_url',
			'provider_website' => 'website',
			'provider_notes' => 'notes',
			'provider_source_url' => 'source_url',
		];

		foreach ($meta_fields as $meta_key => $row_key) {
			if (isset($row[$row_key]) && $row[$row_key] !== '') {
				$value = $meta_key === 'provider_website' ? esc_url_raw($row[$row_key]) : sanitize_text_field($row[$row_key]);
				update_term_meta($term_id, $meta_key, $value);
			}
		}

		// Update last enriched timestamp
		update_term_meta($term_id, 'provider_last_enriched_at', current_time('mysql'));
	}
}


