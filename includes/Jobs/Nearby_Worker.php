<?php

namespace DB\Jobs;

class Nearby_Worker {

    private const TOKEN_OPTION = 'db_nearby_worker_token';
    private const RUN_LOCK = 'db_nearby_worker_running';

    /**
     * Vytvořit nebo získat tajný token pro REST ověření.
     */
    public static function ensure_token(): string {
        $token = get_option(self::TOKEN_OPTION);
        if (!is_string($token) || strlen($token) < 12) {
            $token = wp_generate_password(24, false, false);
            update_option(self::TOKEN_OPTION, $token, false);
        }
        return $token;
    }

    /**
     * Ověřit token z REST požadavku.
     */
    public static function verify_token(?string $token): bool {
        if (!is_string($token) || $token === '') {
            return false;
        }
        return hash_equals(self::ensure_token(), $token);
    }

    /**
     * Spustit asynchronní worker přes REST volání (non-blocking).
     */
    public static function dispatch(int $delay_seconds = 0): bool {
        // Pokud už worker běží, další požadavek neodesílej (zabráníme bouři).
        if (get_transient(self::RUN_LOCK)) {
            return false;
        }

        $token = self::ensure_token();
        $url = rest_url('db/v1/nearby/worker');

        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'token' => $token,
                'delay' => max(0, min(300, $delay_seconds)),
            ),
        );

        wp_remote_post($url, $args);
        return true;
    }

    /**
     * Vykonání workeru (voláno z RESTu).
     */
    public static function run(int $delay_seconds = 0): array {
        if (get_transient(self::RUN_LOCK)) {
            return array('status' => 'locked');
        }

        set_transient(self::RUN_LOCK, 1, 2 * MINUTE_IN_SECONDS);

        try {
            if ($delay_seconds > 0) {
                sleep(min(60, $delay_seconds));
            }

            @set_time_limit(0);

            $queue_manager = new Nearby_Queue_Manager();
            $batch_processor = new Nearby_Batch_Processor();

            $loops = 0;
            $max_loops = 3;
            $last_result = null;

            while ($loops < $max_loops) {
                $loops++;
                $last_result = $batch_processor->process_batch(1);

                $processed = (int)($last_result['processed'] ?? 0);
                $errors = (int)($last_result['errors'] ?? 0);
                $message = (string)($last_result['message'] ?? '');

                if ($processed > 0) {
                    $stats = $queue_manager->get_stats();
                    if (($stats->pending ?? 0) === 0) {
                        break;
                    }
                    continue;
                }

                if ($processed === 0 && $errors === 0) {
                    break; // nic k práci
                }

                if ($errors > 0 && stripos($message, 'minutový limit') !== false) {
                    self::dispatch(15);
                }
                break;
            }

            $stats = $queue_manager->get_stats();
            $pending = (int)($stats->pending ?? 0);

            if ($pending > 0) {
                try {
                    $quota_manager = new API_Quota_Manager();
                    $next_run = $quota_manager->schedule_next_run();
                    $delay = max(1, $next_run - time());
                } catch (\Throwable $__) {
                    $delay = 1;
                }
                self::dispatch($delay);
            }

            return array(
                'status' => 'ok',
                'loops' => $loops,
                'pending' => $pending,
                'last_result' => $last_result,
            );

        } finally {
            delete_transient(self::RUN_LOCK);
        }
    }
}
