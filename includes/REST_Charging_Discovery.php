<?php
declare(strict_types=1);

namespace DB;

use DB\Charging_Discovery;
use DB\Jobs\Charging_Discovery_Worker;
use DB\Jobs\Charging_Quota_Manager;
use WP_Error;

if (!defined('ABSPATH')) { exit; }

class REST_Charging_Discovery {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void {
        add_action('rest_api_init', function () {
            register_rest_route('db/v1', '/charging-discovery/(?P<id>\d+)', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_discover_one'],
                'permission_callback' => function () {
                    return current_user_can('manage_options');
                },
            ]);

            register_rest_route('db/v1', '/charging-discovery/worker', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_worker_run'],
                'permission_callback' => '__return_true',
            ]);

            register_rest_route('db/v1', '/charging-external/(?P<id>\d+)', [
                'methods' => 'GET',
                'callback' => [$this, 'handle_external_details'],
                'permission_callback' => function ($request) {
                    if (!wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest')) {
                        return false;
                    }
                    return function_exists('db_user_can_see_map') ? db_user_can_see_map() : false;
                },
            ]);
        });
    }

    public function handle_discover_one($request) {
        $postId = (int) ($request['id'] ?? 0);
        $save = (bool) ($request->get_param('save') ?? false);
        $force = (bool) ($request->get_param('force') ?? false);
        if (!$postId) {
            return new WP_Error('bad_request', 'Missing id', ['status' => 400]);
        }
        $svc = new Charging_Discovery();
        $quota = new Charging_Quota_Manager();
        $useGoogle = $quota->can_use_google();
        $useOcm = $quota->can_use_ocm();
        $result = $svc->discoverForCharging($postId, $save, $force, $useGoogle, $useOcm);
        if ($save) {
            if ($useGoogle) {
                $quota->record_google(1);
            }
            if ($useOcm) {
                $quota->record_ocm(1);
            }
        }
        return rest_ensure_response($result);
    }

    public function handle_worker_run($request) {
        $token = (string) ($request->get_param('token') ?? '');
        $delay = (int) ($request->get_param('delay') ?? 0);
        if (!Charging_Discovery_Worker::verify_token($token)) {
            return new WP_Error('forbidden', 'Bad token', ['status' => 403]);
        }
        $result = Charging_Discovery_Worker::run($delay);
        return rest_ensure_response($result);
    }

    public function handle_external_details($request) {
        $postId = (int) ($request['id'] ?? 0);
        if (!$postId) {
            return new WP_Error('missing_id', 'Missing charging location id', ['status' => 400]);
        }
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'charging_location') {
            return new WP_Error('not_found', 'Charging location not found', ['status' => 404]);
        }
        $svc = new Charging_Discovery();
        $googleId = (string) get_post_meta($postId, '_charging_google_place_id', true);
        $ocmId = (string) get_post_meta($postId, '_openchargemap_id', true);
        $meta = $svc->getCachedMetadata($postId);
        if ($googleId !== '' && $meta['google'] === null) {
            $meta['google'] = $svc->refreshGoogleMetadata($postId, $googleId, false);
        }
        if ($ocmId !== '' && $meta['open_charge_map'] === null) {
            $meta['open_charge_map'] = $svc->refreshOcmMetadata($postId, $ocmId, false);
        }
        $live = $svc->refreshLiveStatus($postId, false);
        return rest_ensure_response([
            'post_id' => $postId,
            'google_place_id' => $googleId ?: null,
            'open_charge_map_id' => $ocmId ?: null,
            'metadata' => $meta,
            'live_status' => $live,
        ]);
    }
}
