<?php
declare(strict_types=1);

namespace DB\CLI;

use DB\Charging_Discovery;

if (!defined('ABSPATH')) { exit; }
if (!defined('WP_CLI')) { return; }

class Charging_Discovery_Command {
    public function discover_one($args, $assoc): void {
        $stationId = (int) ($args[0] ?? 0);
        $save = isset($assoc['save']);
        $force = isset($assoc['force']);
        if (!$stationId) {
            \WP_CLI::error('Missing charging location ID');
            return;
        }
        $svc = new Charging_Discovery();
        $quota = new \DB\Jobs\Charging_Quota_Manager();
        $useGoogle = $quota->can_use_google();
        $useOcm = $quota->can_use_ocm();
        $res = $svc->discoverForCharging($stationId, $save, $force, $useGoogle, $useOcm);
        if ($save) {
            if ($useGoogle) { $quota->record_google(1); }
            if ($useOcm) { $quota->record_ocm(1); }
        }
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        \WP_CLI::success('Done');
    }

    public function discover_batch($args, $assoc): void {
        $limit = isset($assoc['limit']) ? (int) $assoc['limit'] : 50;
        $save = isset($assoc['save']);
        $svc = new Charging_Discovery();
        $ids = get_posts(array(
            'post_type' => 'charging_location',
            'post_status' => 'publish',
            'fields' => 'ids',
            'posts_per_page' => $limit,
            'orderby' => 'date',
            'order' => 'DESC',
            'meta_query' => array(
                'relation' => 'OR',
                array('key' => '_charging_google_place_id', 'compare' => 'NOT EXISTS'),
                array('key' => '_charging_google_place_id', 'value' => '', 'compare' => '='),
                array('key' => '_openchargemap_id', 'compare' => 'NOT EXISTS'),
                array('key' => '_openchargemap_id', 'value' => '', 'compare' => '='),
            ),
        ));
        $processed = 0;
        $updated = 0;
        $quota = new \DB\Jobs\Charging_Quota_Manager();
        foreach ((array) $ids as $id) {
            $processed++;
            $useGoogle = $quota->can_use_google();
            $useOcm = $quota->can_use_ocm();
            $res = $svc->discoverForCharging((int) $id, $save, false, $useGoogle, $useOcm);
            if ($save) {
                if ($useGoogle) { $quota->record_google(1); }
                if ($useOcm) { $quota->record_ocm(1); }
            }
            if (($res['google'] ?? null) || ($res['open_charge_map'] ?? null)) {
                $updated++;
            }
        }
        \WP_CLI::log(json_encode(array('processed' => $processed, 'updated' => $updated), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        \WP_CLI::success('Batch done');
    }

    public function quotas($args, $assoc): void {
        $qm = new \DB\Jobs\Charging_Quota_Manager();
        $st = $qm->get_status();
        \WP_CLI::log(json_encode($st, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        \WP_CLI::success('OK');
    }

    public function enqueue_missing($args, $assoc): void {
        $queue = new \DB\Jobs\Charging_Discovery_Queue_Manager();
        $res = $queue->enqueue_missing_batch(isset($assoc['limit']) ? (int) $assoc['limit'] : 200);
        \WP_CLI::log(json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        \WP_CLI::success('OK');
    }
}
