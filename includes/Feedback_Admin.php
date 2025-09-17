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
		add_action( 'admin_post_db_feedback_update', array( $this, 'handle_update' ) );
		add_action( 'admin_post_db_feedback_delete', array( $this, 'handle_delete' ) );
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
		$search = isset( $_GET['s'] ) ? sanitize_text_field( $_GET['s'] ) : '';
		$paged = max( 1, intval( $_GET['paged'] ?? 1 ) );
		$per_page = 50;
		$where = array();
		$params = array();
		if ( $status ) { $where[] = 'status = %s'; $params[] = $status; }
		if ( $component_key ) { $where[] = 'component_key = %s'; $params[] = $component_key; }
		if ( $type ) { $where[] = 'type = %s'; $params[] = $type; }
		if ( $search ) { $where[] = '(message LIKE %s OR text_snippet LIKE %s OR component_key LIKE %s OR dom_selector LIKE %s)'; $like = '%' . $wpdb->esc_like( $search ) . '%'; array_push( $params, $like, $like, $like, $like ); }
		$sql = "SELECT SQL_CALC_FOUND_ROWS * FROM {$table}";
		if ( ! empty( $where ) ) { $sql .= ' WHERE ' . implode( ' AND ', $where ); }
		$offset = ( $paged - 1 ) * $per_page;
		$sql .= $wpdb->prepare( ' ORDER BY id DESC LIMIT %d OFFSET %d', $per_page, $offset );
		$items = empty( $params ) ? $wpdb->get_results( $sql ) : $wpdb->get_results( $wpdb->prepare( $sql, $params ) );
		$total = intval( $wpdb->get_var( 'SELECT FOUND_ROWS()' ) );
		$total_pages = max( 1, ceil( $total / $per_page ) );

		echo '<div class="wrap">';
		echo '<h1>Zpětná vazba</h1>';
		echo '<form method="get" style="margin:12px 0;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
		echo '<input type="hidden" name="page" value="db-feedback" />';
		echo 'Stav: <select name="status"><option value="">— všechny —</option><option value="open"' . selected( $status, 'open', false ) . '>open</option><option value="in_progress"' . selected( $status, 'in_progress', false ) . '>in_progress</option><option value="resolved"' . selected( $status, 'resolved', false ) . '>resolved</option></select> ';
		echo 'Typ: <select name="type"><option value="">— všechny —</option><option value="bug"' . selected( $type, 'bug', false ) . '>bug</option><option value="suggestion"' . selected( $type, 'suggestion', false ) . '>suggestion</option><option value="content"' . selected( $type, 'content', false ) . '>content</option></select> ';
		echo 'Component key: <input type="text" name="component_key" value="' . esc_attr( $component_key ) . '" /> ';
		echo 'Hledat: <input type="search" name="s" value="' . esc_attr( $search ) . '" placeholder="text, selektor, component key" /> ';
		echo '<button class="button">Filtrovat</button>';
		echo '</form>';

		echo '<table class="wp-list-table widefat fixed striped">';
		echo '<thead><tr>';
		echo '<th>ID</th><th>Created</th><th>Status</th><th>Type</th><th>Severity</th><th>Component</th><th>Selector</th><th>Page</th><th>User</th><th>Text</th><th>Akce</th>';
		echo '</tr></thead><tbody>';
		if ( ! empty( $items ) ) {
			foreach ( $items as $it ) {
				$u = null; $u_label = '';
				if ( isset( $it->user_id ) && $it->user_id ) { $u = get_userdata( intval( $it->user_id ) ); }
				if ( $u ) { $u_label = $u->display_name . ' (' . $u->user_login . ')'; }
				echo '<tr>';
				echo '<td>' . intval( $it->id ) . '</td>';
				echo '<td>' . esc_html( $it->created_at ) . '</td>';
				echo '<td>';
				echo '<form method="post" action="' . esc_url( admin_url( 'admin-post.php' ) ) . '" style="display:flex;gap:4px;align-items:center">';
				echo '<input type="hidden" name="action" value="db_feedback_update" />';
				echo '<input type="hidden" name="id" value="' . intval( $it->id ) . '" />';
				echo wp_nonce_field( 'db_feedback_update_' . intval( $it->id ), '_wpnonce', true, false );
				echo '<select name="status">';
				foreach ( array( 'open','in_progress','resolved' ) as $st ) {
					echo '<option value="' . esc_attr( $st ) . '"' . selected( $it->status, $st, false ) . '>' . esc_html( $st ) . '</option>';
				}
				echo '</select>';
				echo '</td>';
				echo '<td>' . esc_html( $it->type ) . '</td>';
				echo '<td><select name="severity">';
				foreach ( array( 'low','medium','high' ) as $sv ) {
					echo '<option value="' . esc_attr( $sv ) . '"' . selected( $it->severity, $sv, false ) . '>' . esc_html( $sv ) . '</option>';
				}
				echo '</select></td>';
				echo '<td><code>' . esc_html( $it->component_key ) . '</code></td>';
				echo '<td><code style="white-space:nowrap;">' . esc_html( $it->dom_selector ?? '' ) . '</code></td>';
				echo '<td><a href="' . esc_url( $it->page_url ) . '" target="_blank" rel="noopener">' . esc_html( $it->page_type ) . '</a></td>';
				echo '<td>' . esc_html( $u_label ) . '</td>';
				echo '<td>' . esc_html( mb_strimwidth( (string) ( $it->text_snippet ?: $it->message ), 0, 120, '…' ) ) . '</td>';
				echo '<td style="min-width:220px;display:flex;gap:6px;align-items:center">';
				echo '<input type="text" name="admin_note" placeholder="Poznámka" value="' . esc_attr( get_option( 'db_feedback_note_' . intval( $it->id ), '' ) ) . '" /> ';
				echo '<button class="button">Uložit</button> ';
				// Kopírovací tlačítko s JSON daty
				$copy_payload = array(
					'id' => intval( $it->id ),
					'created_at' => (string) $it->created_at,
					'status' => (string) $it->status,
					'type' => (string) $it->type,
					'severity' => (string) $it->severity,
					'page_url' => (string) $it->page_url,
					'page_type' => (string) $it->page_type,
					'template' => (string) $it->template,
					'component_key' => (string) $it->component_key,
					'dom_selector' => (string) ( $it->dom_selector ?? '' ),
					'text_snippet' => (string) ( $it->text_snippet ?? '' ),
					'message' => (string) $it->message,
					'reported_by' => array( 'id' => isset( $it->user_id ) ? intval( $it->user_id ) : 0, 'login' => $u ? (string) $u->user_login : '', 'name' => $u ? (string) $u->display_name : '', 'email' => $u ? (string) $u->user_email : '' ),
				);
				$copy_attr = esc_attr( wp_json_encode( $copy_payload ) );
				echo '<button type="button" class="button copy-feedback" data-feedback="' . $copy_attr . '">Kopírovat</button> ';
				echo '<a class="button button-link-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_feedback_delete&id=' . intval( $it->id ) ), 'db_feedback_delete_' . intval( $it->id ) ) ) . '" onclick="return confirm(\'Smazat záznam #' . intval( $it->id ) . '?\')">Smazat</a>';
				echo '</form>';
				echo '</td>';
				echo '</tr>';
			}
		} else {
			echo '<tr><td colspan="10">Žádné záznamy</td></tr>';
		}
		echo '</tbody></table>';

		// Pagination
		if ( $total_pages > 1 ) {
			echo '<div class="tablenav"><div class="tablenav-pages">';
			for ( $p = 1; $p <= $total_pages; $p++ ) {
				$qs = $_GET; $qs['paged'] = $p;
				$url = esc_url( admin_url( 'admin.php?' . http_build_query( $qs ) ) );
				$cls = $p === $paged ? 'class="page-numbers current"' : 'class="page-numbers"';
				echo '<a ' . $cls . ' href="' . $url . '">' . $p . '</a> ';
			}
			echo '</div></div>';
		}
		// Inline JS pro kopírování do schránky ve strukturovaném formátu
		echo '<script>
document.addEventListener("click",function(e){
  var btn = e.target.closest && e.target.closest(".copy-feedback");
  if(!btn) return;
  try{
    var data = JSON.parse(btn.getAttribute("data-feedback")||"{}");
    var lines = [];
    lines.push("### Feedback");
    lines.push("- id: " + (data.id||""));
    lines.push("- created_at: " + (data.created_at||""));
    lines.push("- status: " + (data.status||""));
    lines.push("- type: " + (data.type||""));
    lines.push("- severity: " + (data.severity||""));
    lines.push("- page_url: " + (data.page_url||""));
    lines.push("- page_type: " + (data.page_type||""));
    lines.push("- template: " + (data.template||""));
    lines.push("- component_key: " + (data.component_key||""));
    lines.push("- dom_selector: " + (data.dom_selector||""));
    lines.push("- text_snippet:\n```\n" + (data.text_snippet||"") + "\n```");
    lines.push("- message:\n```\n" + (data.message||"") + "\n```");
    var text = lines.join("\n");
    navigator.clipboard.writeText(text).then(function(){
      btn.textContent = "Zkopírováno";
      setTimeout(function(){ btn.textContent = "Kopírovat"; }, 1200);
    }).catch(function(){
      var ta = document.createElement("textarea"); ta.style.position = "fixed"; ta.style.opacity = "0"; ta.value = text; document.body.appendChild(ta); ta.select(); try{ document.execCommand("copy"); btn.textContent = "Zkopírováno"; setTimeout(function(){ btn.textContent = "Kopírovat"; }, 1200);} finally { document.body.removeChild(ta); }
    });
  }catch(err){ alert("Kopírování selhalo: " + err.message); }
});
</script>';

		echo '</div>';
	}

	public function handle_update() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		$id = intval( $_POST['id'] ?? 0 );
		check_admin_referer( 'db_feedback_update_' . $id );
		$status = sanitize_text_field( $_POST['status'] ?? '' );
		$severity = sanitize_text_field( $_POST['severity'] ?? '' );
		$admin_note = sanitize_text_field( $_POST['admin_note'] ?? '' );
		update_option( 'db_feedback_note_' . $id, $admin_note, false );
		if ( $id ) {
			global $wpdb; $table = $wpdb->prefix . 'db_feedback';
			$data = array(); if ( $status ) $data['status'] = $status; if ( $severity ) $data['severity'] = $severity;
			if ( ! empty( $data ) ) { $wpdb->update( $table, $data, array( 'id' => $id ) ); }
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=db-feedback' ) );
		exit;
	}

	public function handle_delete() {
		if ( ! current_user_can( 'manage_options' ) ) wp_die( 'Forbidden' );
		$id = intval( $_GET['id'] ?? 0 );
		check_admin_referer( 'db_feedback_delete_' . $id );
		if ( $id ) {
			global $wpdb; $table = $wpdb->prefix . 'db_feedback';
			$wpdb->delete( $table, array( 'id' => $id ) );
			delete_option( 'db_feedback_note_' . $id );
		}
		wp_safe_redirect( wp_get_referer() ?: admin_url( 'admin.php?page=db-feedback' ) );
		exit;
	}
}


