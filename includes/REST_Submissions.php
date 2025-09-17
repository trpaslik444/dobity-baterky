<?php
/**
 * REST API pro uživatelská podání (všechny CPT: charging_location, poi, rv_spot)
 * @package DobityBaterky
 */

namespace DB;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

if (!defined('ABSPATH')) exit;

class REST_Submissions {
	private static $instance = null;

	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action('rest_api_init', array($this, 'register_routes'));
	}

	public function register_routes() {
		register_rest_route('db/v1', '/submissions', array(
			'methods' => 'POST',
			'callback' => array($this, 'create_submission'),
			'permission_callback' => array($this, 'permissions_logged_in')
		));

		register_rest_route('db/v1', '/submissions', array(
			'methods' => 'GET',
			'callback' => array($this, 'list_my_submissions'),
			'permission_callback' => array($this, 'permissions_logged_in')
		));

		register_rest_route('db/v1', '/submissions/(?P<id>\d+)', array(
			'methods' => 'PATCH',
			'callback' => array($this, 'update_submission'),
			'permission_callback' => array($this, 'permissions_logged_in')
		));

		register_rest_route('db/v1', '/submissions/(?P<id>\d+)/validate', array(
			'methods' => 'POST',
			'callback' => array($this, 'trigger_validation'),
			'permission_callback' => array($this, 'permissions_logged_in')
		));
	}

	public function permissions_logged_in($request) {
		$nonce = $request->get_header('X-WP-Nonce');
		if (!wp_verify_nonce($nonce, 'wp_rest')) {
			return new WP_Error('invalid_nonce', 'Neplatný nonce.', array('status' => 403));
		}
		if (!is_user_logged_in()) {
			return new WP_Error('not_logged_in', 'Přihlášení je vyžadováno.', array('status' => 401));
		}
		return true;
	}

	private function sanitize_payload(array $params) : array {
		$target_post_type = sanitize_text_field($params['post_type'] ?? '');
		$title = sanitize_text_field($params['title'] ?? '');
		$description = sanitize_textarea_field($params['description'] ?? '');
		$lat = isset($params['lat']) ? floatval($params['lat']) : null;
		$lng = isset($params['lng']) ? floatval($params['lng']) : null;
		$address = sanitize_text_field($params['address'] ?? '');
		$rating = isset($params['rating']) ? intval($params['rating']) : null;
		$comment = sanitize_text_field($params['comment'] ?? '');

		return compact('target_post_type','title','description','lat','lng','address','rating','comment');
	}

	private function validate_business_rules(array $data) {
		// základní
		if (!in_array($data['target_post_type'], array('charging_location','poi','rv_spot'), true)) {
			return new WP_Error('invalid_type', 'Neplatný cílový typ.', array('status' => 400));
		}
		if (!$data['lat'] || !$data['lng']) {
			return new WP_Error('invalid_coords', 'Chybí souřadnice.', array('status' => 400));
		}
		if ($data['comment'] && ($data['rating'] === null || $data['rating'] === '')) {
			return new WP_Error('rating_required', 'Komentář vyžaduje hodnocení.', array('status' => 400));
		}
		return true;
	}

	public function create_submission(WP_REST_Request $request) {
		$current_user = get_current_user_id();
		$params = $request->get_json_params();
		$data = $this->sanitize_payload(is_array($params) ? $params : array());
		$valid = $this->validate_business_rules($data);
		if (is_wp_error($valid)) return $valid;

		$post_id = wp_insert_post(array(
			'post_type' => 'user_submission',
			'post_title' => $data['title'] ?: __('Nové podání', 'dobity-baterky'),
			'post_content' => $data['description'] ?: '',
			'post_status' => 'publish',
			'post_author' => $current_user,
		));
		if (is_wp_error($post_id)) return $post_id;

		update_post_meta($post_id, '_target_post_type', $data['target_post_type']);
		update_post_meta($post_id, '_address', $data['address']);
		if ($data['lat'] !== null) update_post_meta($post_id, '_lat', $data['lat']);
		if ($data['lng'] !== null) update_post_meta($post_id, '_lng', $data['lng']);
		if ($data['rating'] !== null) update_post_meta($post_id, '_rating', $data['rating']);
		if ($data['comment'] !== '') update_post_meta($post_id, '_comment', $data['comment']);
		update_post_meta($post_id, '_submission_status', 'pending_review');

		return new WP_REST_Response(array('id' => $post_id, 'status' => 'pending_review'), 201);
	}

	public function list_my_submissions(WP_REST_Request $request) {
		$user_id = get_current_user_id();
		$q = new \WP_Query(array(
			'post_type' => 'user_submission',
			'author' => $user_id,
			'post_status' => array('publish'),
			'posts_per_page' => 50,
			'orderby' => 'date',
			'order' => 'DESC',
		));
		$items = array();
		foreach ($q->posts as $p) {
			$items[] = array(
				'id' => $p->ID,
				'title' => get_the_title($p),
				'post_type' => get_post_meta($p->ID, '_target_post_type', true),
				'lat' => get_post_meta($p->ID, '_lat', true),
				'lng' => get_post_meta($p->ID, '_lng', true),
				'address' => get_post_meta($p->ID, '_address', true),
				'status' => get_post_meta($p->ID, '_submission_status', true) ?: 'pending_review',
			);
		}
		return new WP_REST_Response(array('items' => $items), 200);
	}

	public function update_submission(WP_REST_Request $request) {
		$id = intval($request['id']);
		$post = get_post($id);
		if (!$post || $post->post_type !== 'user_submission') {
			return new WP_Error('not_found', 'Podání nenalezeno.', array('status' => 404));
		}
		if ((int)$post->post_author !== get_current_user_id() && !current_user_can('edit_post', $id)) {
			return new WP_Error('forbidden', 'Nemáte oprávnění upravit podání.', array('status' => 403));
		}
		$params = $request->get_json_params();
		$data = $this->sanitize_payload(is_array($params) ? $params : array());
		$valid = $this->validate_business_rules($data);
		if (is_wp_error($valid)) return $valid;

		wp_update_post(array(
			'ID' => $id,
			'post_title' => $data['title'] ?: get_the_title($id),
			'post_content' => $data['description'] ?? $post->post_content,
		));
		update_post_meta($id, '_target_post_type', $data['target_post_type']);
		update_post_meta($id, '_address', $data['address']);
		if ($data['lat'] !== null) update_post_meta($id, '_lat', $data['lat']);
		if ($data['lng'] !== null) update_post_meta($id, '_lng', $data['lng']);
		if ($data['rating'] !== null) update_post_meta($id, '_rating', $data['rating']);
		update_post_meta($id, '_comment', $data['comment']);

		return new WP_REST_Response(array('id' => $id, 'status' => get_post_meta($id, '_submission_status', true) ?: 'pending_review'), 200);
	}

	public function trigger_validation(WP_REST_Request $request) {
		$id = intval($request['id']);
		$post = get_post($id);
		if (!$post || $post->post_type !== 'user_submission') {
			return new WP_Error('not_found', 'Podání nenalezeno.', array('status' => 404));
		}
		if ((int)$post->post_author !== get_current_user_id() && !current_user_can('edit_post', $id)) {
			return new WP_Error('forbidden', 'Nemáte oprávnění validovat podání.', array('status' => 403));
		}

		// Zatím jen změna stavu na pending_review -> validated (placeholder pro službu)
		update_post_meta($id, '_submission_status', 'validated');
		return new WP_REST_Response(array('id' => $id, 'status' => 'validated'), 200);
	}
}

