<?php

namespace DB\Jobs;

class POI_Discovery_Worker {
	private const TOKEN_OPT = 'db_poi_discovery_worker_token';
	private const RUN_LOCK  = 'db_poi_discovery_running';

	public static function ensure_token(): string {
		$token = get_option(self::TOKEN_OPT);
		if (!is_string($token) || strlen($token) < 16) {
			$token = wp_generate_password(24, false, false);
			update_option(self::TOKEN_OPT, $token, false);
		}
		return $token;
	}

	public static function verify_token(?string $token): bool {
		return is_string($token) && hash_equals(self::ensure_token(), $token);
	}

	public static function dispatch(int $delay_seconds = 0): bool {
		if (get_transient(self::RUN_LOCK)) return false;
		$url = rest_url('db/v1/poi-discovery/worker');
		$args = array(
			'timeout' => 0.01,
			'blocking' => false,
			'body' => array('token' => self::ensure_token(), 'delay' => max(0, min(300, $delay_seconds)))
		);
		wp_remote_post($url, $args);
		return true;
	}

	public static function run(int $delay_seconds = 0): array {
		if (get_transient(self::RUN_LOCK)) return array('status' => 'locked');
		set_transient(self::RUN_LOCK, 1, 60);
		$res = array('processed' => 0, 'errors' => 0, 'usedGoogle' => 0, 'usedTripadvisor' => 0, 'attempted' => 0);
		try {
			if ($delay_seconds > 0) sleep(min(60, $delay_seconds));
			@set_time_limit(0);
			$batch = new POI_Discovery_Batch_Processor();
			$res = $batch->process_batch(10);
			// Ulož poslední výsledek pro admin UI
			update_option('db_poi_last_batch', array_merge($res, ['ts' => current_time('mysql')]), false);
			return $res;
		} catch (\Throwable $e) {
			// Log error and ensure worker continues
			error_log('POI Discovery Worker error: ' . $e->getMessage());
			$res['errors'] = 1;
			$res['error_message'] = $e->getMessage();
			update_option('db_poi_last_batch', array_merge($res, ['ts' => current_time('mysql')]), false);
			return $res;
		} finally {
			delete_transient(self::RUN_LOCK);
			// Schedule next batch after clearing the lock
			if ((int)($res['attempted'] ?? 0) > 0) {
				self::dispatch(5);
			}
		}
	}
}


