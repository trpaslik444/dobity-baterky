<?php
/**
 * Feedback module: REST API + frontend enqueue
 *
 * @package DobityBaterky
 */

namespace DB;

class Feedback {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend' ) );
	}

	public function register_routes() {
		register_rest_route( 'db/v1', '/feedback', array(
			array(
				'methods'  => 'POST',
				'callback' => array( $this, 'handle_feedback_create' ),
				'permission_callback' => function() { return is_user_logged_in(); },
			),
			array(
				'methods'  => 'GET',
				'callback' => array( $this, 'handle_feedback_list' ),
				'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			),
		) );
		register_rest_route( 'db/v1', '/feedback/(?P<id>\\d+)', array(
			'methods'  => 'PATCH',
			'callback' => array( $this, 'handle_feedback_update' ),
			'permission_callback' => function() { return current_user_can( 'manage_options' ); },
			'args' => array(
				'id' => array( 'validate_callback' => function( $param ) { return is_numeric( $param ) && intval( $param ) > 0; } )
			)
		) );
	}

	public function enqueue_frontend() {
		// Feedback je viditelný pro early adopters (stejně jako mapa) i pro adminy/editory
		$enabled = false;
		if ( isset( $_GET['feedback'] ) ) {
			$enabled = true; // Vždy povolit pokud je feedback parametr v URL
		} elseif ( is_user_logged_in() ) {
			// Použít stejnou logiku jako pro mapu (early adopters mají access_app capability)
			if ( function_exists( 'db_user_can_see_map' ) ) {
				$enabled = db_user_can_see_map();
			} else {
				// Fallback pro případ, kdy funkce ještě není definována
				$enabled = current_user_can( 'edit_posts' );
			}
		}
		if ( ! $enabled ) return;

		wp_enqueue_style(
			'db-feedback',
			plugin_dir_url( DB_PLUGIN_FILE ) . 'assets/feedback.css',
			array(),
			DB_PLUGIN_VERSION
		);
		wp_enqueue_script(
			'db-feedback',
			plugin_dir_url( DB_PLUGIN_FILE ) . 'assets/feedback.js',
			array(),
			DB_PLUGIN_VERSION,
			true
		);

		global $template;
		$page_type = is_singular() ? ( get_post_type( get_queried_object_id() ) ?: 'singular' ) : ( is_archive() ? 'archive' : ( is_home() ? 'home' : ( is_front_page() ? 'front' : 'other' ) ) );
		$tmpl = is_string( $template ) ? basename( $template ) : '';
		wp_localize_script( 'db-feedback', 'DB_FEEDBACK', array(
			'enabled' => true,
			'nonce' => wp_create_nonce( 'wp_rest' ),
			'rest' => array(
				'createUrl' => esc_url_raw( rest_url( 'db/v1/feedback' ) ),
				'listUrl' => esc_url_raw( rest_url( 'db/v1/feedback' ) ),
				'updateBaseUrl' => esc_url_raw( rest_url( 'db/v1/feedback/' ) ),
			),
			'user' => array(
				'id' => get_current_user_id(),
			),
			'page' => array(
				'url' => esc_url_raw( home_url( add_query_arg( array() ) ) ),
				'page_type' => $page_type,
				'template' => $tmpl,
			),
			'highlightAttr' => 'data-db-feedback',
		) );
	}

	public function handle_feedback_create( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'db_feedback';
		$params = $request->get_json_params();
		$type = sanitize_text_field( $params['type'] ?? 'general' );
		$severity = sanitize_text_field( $params['severity'] ?? 'medium' );
		$message = sanitize_textarea_field( $params['message'] ?? '' );
		$page_url = esc_url_raw( $params['page_url'] ?? '' );
		$page_type = sanitize_text_field( $params['page_type'] ?? '' );
		$template = sanitize_text_field( $params['template'] ?? '' );
		$component_key = sanitize_text_field( $params['component_key'] ?? '' );
		$dom_selector = sanitize_text_field( $params['dom_selector'] ?? '' );
		$text_snippet = sanitize_textarea_field( $params['text_snippet'] ?? '' );
		$meta_json = isset( $params['meta_json'] ) ? wp_json_encode( $params['meta_json'] ) : null;
		$screenshot_attachment_id = isset( $params['screenshot_attachment_id'] ) ? intval( $params['screenshot_attachment_id'] ) : null;
		$locale = sanitize_text_field( get_locale() );
		$user_agent = isset( $_SERVER['HTTP_USER_AGENT'] ) ? substr( sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ), 0, 255 ) : '';
		$user_id = get_current_user_id();
		$ip = $_SERVER['REMOTE_ADDR'] ?? '';
		$ip_hash = $ip ? hash( 'sha256', $ip . NONCE_SALT ) : '';

		if ( ! $message || ! $page_url ) {
			return new \WP_Error( 'missing_fields', 'Chybí povinná pole (message, page_url)', array( 'status' => 400 ) );
		}

		$ok = $wpdb->insert( $table, array(
			'status' => 'open',
			'type' => $type,
			'severity' => $severity,
			'page_url' => $page_url,
			'page_type' => $page_type,
			'template' => $template,
			'component_key' => $component_key,
			'dom_selector' => $dom_selector,
			'text_snippet' => $text_snippet,
			'message' => $message,
			'screenshot_attachment_id' => $screenshot_attachment_id,
			'meta_json' => $meta_json,
			'user_id' => $user_id,
			'user_agent' => $user_agent,
			'locale' => $locale,
			'ip_hash' => $ip_hash,
		) );

		if ( $ok === false ) {
			return new \WP_Error( 'db_error', 'Nepodařilo se uložit zpětnou vazbu', array( 'status' => 500 ) );
		}

		return array( 'success' => true, 'id' => intval( $wpdb->insert_id ) );
	}

	public function handle_feedback_list( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'db_feedback';
		$where = array();
		$params = array();
		$component_key = $request->get_param( 'component_key' );
		$status = $request->get_param( 'status' );
		$type = $request->get_param( 'type' );
		$date_from = $request->get_param( 'date_from' );
		$date_to = $request->get_param( 'date_to' );
		if ( $component_key ) { $where[] = 'component_key = %s'; $params[] = $component_key; }
		if ( $status ) { $where[] = 'status = %s'; $params[] = $status; }
		if ( $type ) { $where[] = 'type = %s'; $params[] = $type; }
		if ( $date_from ) { $where[] = 'created_at >= %s'; $params[] = $date_from; }
		if ( $date_to ) { $where[] = 'created_at <= %s'; $params[] = $date_to; }
		$sql = "SELECT * FROM {$table}";
		if ( ! empty( $where ) ) {
			$sql .= ' WHERE ' . implode( ' AND ', $where );
		}
		$sql .= ' ORDER BY id DESC LIMIT 500';
		$rows = empty( $params ) ? $wpdb->get_results( $sql, ARRAY_A ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ), ARRAY_A );
		return array( 'items' => $rows );
	}

	public function handle_feedback_update( $request ) {
		global $wpdb;
		$table = $wpdb->prefix . 'db_feedback';
		$id = intval( $request['id'] );
		$params = $request->get_json_params();
		$allowed = array( 'status', 'severity' );
		$data = array();
		foreach ( $allowed as $key ) {
			if ( isset( $params[ $key ] ) ) $data[ $key ] = sanitize_text_field( $params[ $key ] );
		}
		if ( empty( $data ) ) {
			return new \WP_Error( 'no_changes', 'Nezadali jste žádné změny', array( 'status' => 400 ) );
		}
		$ok = $wpdb->update( $table, $data, array( 'id' => $id ) );
		if ( $ok === false ) {
			return new \WP_Error( 'db_error', 'Nepodařilo se upravit záznam', array( 'status' => 500 ) );
		}
		return array( 'success' => true );
	}
}


