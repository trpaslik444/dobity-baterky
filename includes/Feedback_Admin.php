<?php
/**
 * Admin list table for feedback
 *
 * @package DobityBaterky
 */

namespace DB;

class Feedback_Admin {
	private static $instance = null;

	public static function get_instance() {
		if ( self::$instance === null ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
	}

	public function add_menu() {
		add_menu_page(
			'Zpětná vazba',
			'Zpětná vazba',
			'manage_options',
			'db-feedback',
			array( $this, 'render_page' ),
			'dashicons-feedback',
			32
		);
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}
		global $wpdb;
		$table = $wpdb->prefix . 'db_feedback';

		$status = isset( $_GET['status'] ) ? sanitize_text_field( $_GET['status'] ) : '';
		$component_key = isset( $_GET['component_key'] ) ? sanitize_text_field( $_GET['component_key'] ) : '';
		$type = isset( $_GET['type'] ) ? sanitize_text_field( $_GET['type'] ) : '';
		$where = array();
		$params = array();
		if ( $status ) { $where[] = 'status = %s'; $params[] = $status; }
		if ( $component_key ) { $where[] = 'component_key = %s'; $params[] = $component_key; }
		if ( $type ) { $where[] = 'type = %s'; $params[] = $type; }
		$sql = "SELECT * FROM {$table}";
		if ( ! empty( $where ) ) { $sql .= ' WHERE ' . implode( ' AND ', $where ); }
		$sql .= ' ORDER BY id DESC LIMIT 500';
		$items = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );

		echo '<div class="wrap">';
		echo '<h1>Zpětná vazba</h1>';
		echo '<form method="get" style="margin:12px 0;">';
		echo '<input type="hidden" name="page" value="db-feedback" />';
		echo 'Stav: <select name="status"><option value="">— všechny —</option><option value="open"' . selected( $status, 'open', false ) . '>open</option><option value="in_progress"' . selected( $status, 'in_progress', false ) . '>in_progress</option><option value="resolved"' . selected( $status, 'resolved', false ) . '>resolved</option></select> ';
		echo 'Typ: <select name="type"><option value="">— všechny —</option><option value="bug"' . selected( $type, 'bug', false ) . '>bug</option><option value="suggestion"' . selected( $type, 'suggestion', false ) . '>suggestion</option><option value="content"' . selected( $type, 'content', false ) . '>content</option></select> ';
		echo 'Component key: <input type="text" name="component_key" value="' . esc_attr( $component_key ) . '" /> ';
		echo '<button class="button">Filtrovat</button>';
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Created</th><th>Status</th><th>Type</th><th>Severity</th><th>Component</th><th>Page</th><th>Snippet</th>';
		echo '</tr></thead><tbody>';
		if ( ! empty( $items ) ) {
			foreach ( $items as $it ) {
				echo '<tr>';
				echo '<td>' . intval( $it->id ) . '</td>';
				echo '<td>' . esc_html( $it->created_at ) . '</td>';
				echo '<td><code>' . esc_html( $it->status ) . '</code></td>';
				echo '<td>' . esc_html( $it->type ) . '</td>';
				echo '<td>' . esc_html( $it->severity ) . '</td>';
				echo '<td><code>' . esc_html( $it->component_key ) . '</code></td>';
				echo '<td><a href="' . esc_url( $it->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $it->page_type ) . '</a></td>';
				echo '<td>' . esc_html( mb_strimwidth( (string) $it->message, 0, 120, '…' ) ) . '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="8">Žádné záznamy</td></tr>';
		}
		echo '</tbody></table>';
		echo '</div>';
	}
}


