<?php

namespace DB\Jobs;

if (!defined('ABSPATH')) { exit; }

class Charging_Discovery_Worker {
    private const TOKEN_OPT = 'db_charging_discovery_worker_token';
    private const RUN_LOCK  = 'db_charging_discovery_running';

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
        if (get_transient(self::RUN_LOCK)) {
            return false;
        }
        $url = rest_url('db/v1/charging-discovery/worker');
        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'token' => self::ensure_token(),
                'delay' => max(0, min(300, $delay_seconds)),
            ),
        );
        wp_remote_post($url, $args);
        return true;
    }

    public static function run(int $delay_seconds = 0): array {
        if (get_transient(self::RUN_LOCK)) {
            return array('status' => 'locked');
        }
        set_transient(self::RUN_LOCK, 1, 60);
        $result = array('processed' => 0, 'errors' => 0, 'usedGoogle' => 0, 'usedOpenChargeMap' => 0, 'attempted' => 0);
        try {
            if ($delay_seconds > 0) {
                sleep(min(60, $delay_seconds));
            }
            @set_time_limit(0);
            $batch = new Charging_Discovery_Batch_Processor();
            $result = $batch->process_batch(10);
            update_option('db_charging_last_batch', array_merge($result, array('ts' => current_time('mysql'))), false);
            return $result;
        } catch (\Throwable $e) {
            error_log('Charging Discovery Worker error: ' . $e->getMessage());
            $result['errors'] = 1;
            $result['error_message'] = $e->getMessage();
            update_option('db_charging_last_batch', array_merge($result, array('ts' => current_time('mysql'))), false);
            return $result;
        } finally {
            delete_transient(self::RUN_LOCK);
            if ((int) ($result['attempted'] ?? 0) > 0) {
                self::dispatch(5);
            }
        }
    }
}
