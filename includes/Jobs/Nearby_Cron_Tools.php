<?php

namespace DB\Jobs;

/**
 * Pomocné funkce pro práci s WP-Cron událostmi souvisejícími s nearby recompute.
 */
class Nearby_Cron_Tools {

    public const RECOMPUTE_HOOK = 'db_nearby_recompute';
    public const ACTION_SCHEDULER_GROUP = 'db-nearby';
    private const MAX_RECOMPUTE_EVENTS = 60; // tvrdý limit, aby se nerozplánovaly tisíce jobů

    /**
     * Naplánuje recompute běh s kontrolou limitů a duplicit
     */
    public static function schedule_recompute(int $delay_seconds, int $origin_id, string $type): bool {
        $delay_seconds = max(1, $delay_seconds);
        $args = [
            'origin_id' => (int) $origin_id,
            'type' => sanitize_key($type),
        ];

        // Action Scheduler (pokud je k dispozici) – zajistit unikátní úlohu
        if (function_exists('as_enqueue_async_action')) {
            if (function_exists('as_next_scheduled_action')) {
                $next = as_next_scheduled_action(self::RECOMPUTE_HOOK, $args, self::ACTION_SCHEDULER_GROUP);
                if ($next !== false) {
                    return false; // už naplánováno
                }
            }

            as_enqueue_async_action(self::RECOMPUTE_HOOK, $args, self::ACTION_SCHEDULER_GROUP);
            Nearby_Logger::log('CRON', 'Scheduled recompute via Action Scheduler', $args);
            return true;
        }

        // WP-Cron fallback
        $legacy_args = [ (int) $origin_id, sanitize_key($type) ];

        if (wp_next_scheduled(self::RECOMPUTE_HOOK, $legacy_args)) {
            return false; // existuje čekající událost
        }

        $current_total = self::count_scheduled_recompute();
        if ($current_total >= self::MAX_RECOMPUTE_EVENTS) {
            Nearby_Logger::log('CRON', 'Skipping recompute schedule – limit reached', [
                'origin_id' => $origin_id,
                'type' => $type,
                'scheduled_total' => $current_total,
            ]);
            return false;
        }

        wp_schedule_single_event(time() + $delay_seconds, self::RECOMPUTE_HOOK, $legacy_args);
        Nearby_Logger::log('CRON', 'Scheduled recompute via WP-Cron', [
            'origin_id' => $origin_id,
            'type' => $type,
            'delay_s' => $delay_seconds,
            'scheduled_total' => $current_total + 1,
        ]);

        return true;
    }

    /**
     * Vrátí počet naplánovaných WP-Cron událostí pro recompute
     */
    public static function count_scheduled_recompute(): int {
        $cron = self::get_cron_option();
        $count = 0;

        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks)) {
                continue;
            }
            if (!isset($hooks[self::RECOMPUTE_HOOK])) {
                continue;
            }
            $jobs = $hooks[self::RECOMPUTE_HOOK];
            if (is_array($jobs)) {
                $count += count($jobs);
            }
        }

        return $count;
    }

    /**
     * Smaže všechny naplánované WP-Cron události pro nearby recompute
     */
    public static function clear_recompute_events(): int {
        $cron = self::get_cron_option();
        $cleared = 0;

        foreach ($cron as $timestamp => $hooks) {
            if (!is_array($hooks) || !isset($hooks[self::RECOMPUTE_HOOK])) {
                continue;
            }
            $jobs = $hooks[self::RECOMPUTE_HOOK];
            if (is_array($jobs)) {
                $cleared += count($jobs);
            }
            unset($cron[$timestamp][self::RECOMPUTE_HOOK]);
            if (empty($cron[$timestamp])) {
                unset($cron[$timestamp]);
            }
        }

        update_option('cron', $cron);
        if ($cleared > 0) {
            Nearby_Logger::log('CRON', 'Cleared scheduled recompute events', ['cleared' => $cleared]);
        }
        return $cleared;
    }

    private static function get_cron_option(): array {
        $cron = get_option('cron');
        if (!is_array($cron)) {
            return [];
        }
        unset($cron['_transient_timeout'], $cron['_transient']); // defensive cleanup
        return $cron;
    }
}

