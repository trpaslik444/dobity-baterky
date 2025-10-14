<?php
declare(strict_types=1);

namespace DB;

if (!defined('ABSPATH')) { exit; }

class REST_POI_Discovery {
	private static $instance = null;

	public static function get_instance() {
		if (self::$instance === null) self::$instance = new self();
		return self::$instance;
	}

	public function register(): void {
		add_action('rest_api_init', function() {
			register_rest_route('db/v1', '/poi-discovery/(?P<id>\d+)', [
				'methods' => 'POST',
				'callback' => [$this, 'handle_discover_one'],
				'permission_callback' => function() { return current_user_can('manage_options'); },
			]);
		});
	}

	public function handle_discover_one($request) {
		$post_id = (int) ($request['id'] ?? 0);
		$save = (bool) ($request->get_param('save') ?? false);
		$with_ta = (bool) ($request->get_param('with_tripadvisor') ?? false);
		if (!$post_id) return new \WP_Error('bad_request', 'Missing id', ['status' => 400]);

		$svc = new POI_Discovery();
		$res = $svc->discoverForPoi($post_id, $save, $with_ta);
		return rest_ensure_response($res);
	}
}


