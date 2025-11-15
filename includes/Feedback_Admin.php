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

	/**
	 * Najít relevantní soubory a kontext pro feedback item
	 */
	private function get_code_context( $feedback_item ) {
		$context = array();
		
		// Hledej podle text_snippet
		if ( ! empty( $feedback_item->text_snippet ) ) {
			$text_to_search = $feedback_item->text_snippet;
			$context['files_with_text'] = $this->find_files_with_text( $text_to_search );
		}
		
		// Hledej podle component_key a DOM selector
		if ( ! empty( $feedback_item->component_key ) ) {
			$context['component_files'] = $this->find_component_files( $feedback_item->component_key, $feedback_item->dom_selector ?? '' );
		}
		
		// Doplnit další relevantní informace
		if ( ! empty( $feedback_item->page_type ) ) {
			$context['page_context'] = $this->get_page_context( $feedback_item->page_type, $feedback_item->template ?? '' );
		}
		
		return $context;
	}

	/**
	 * Najít soubory obsahující text
	 */
	private function find_files_with_text( $text ) {
		$files = array();
		$plugin_dir = DB_PLUGIN_DIR;
		
		// Relevantní adresáře k prohledání
		$search_dirs = array(
			$plugin_dir . 'assets/',
			$plugin_dir . 'includes/',
			$plugin_dir . 'templates/',
		);
		
		foreach ( $search_dirs as $dir ) {
			if ( ! is_dir( $dir ) ) continue;
			$iterator = new \RecursiveIteratorIterator( new \RecursiveDirectoryIterator( $dir ) );
			foreach ( $iterator as $file ) {
				if ( $file->isFile() && in_array( $file->getExtension(), array( 'php', 'js', 'css' ) ) ) {
					$content = @file_get_contents( $file->getRealPath() );
					if ( $content && stripos( $content, $text ) !== false ) {
						$relative_path = str_replace( $plugin_dir, '', $file->getRealPath() );
						$files[] = array(
							'path' => $relative_path,
							'type' => $file->getExtension(),
							'line' => $this->find_text_line( $content, $text ),
						);
					}
				}
			}
		}
		
		return array_slice( $files, 0, 5 ); // Max 5 souborů
	}

	/**
	 * Najít soubory pro komponentu
	 */
	private function find_component_files( $component_key, $dom_selector ) {
		$files = array();
		$plugin_dir = DB_PLUGIN_DIR;
		
		// Pokud je component_key něco jako id nebo class, hledej v assets
		if ( preg_match( '/^[a-z][a-z0-9_-]*$/', $component_key ) ) {
			$search_files = array(
				$plugin_dir . 'assets/map/core.js',
				$plugin_dir . 'assets/map/core.js',
				$plugin_dir . 'assets/feedback.js',
			);
			
			foreach ( $search_files as $file_path ) {
				if ( ! file_exists( $file_path ) ) continue;
				$content = @file_get_contents( $file_path );
				if ( $content && ( stripos( $content, $component_key ) !== false || stripos( $content, $dom_selector ) !== false ) ) {
					$files[] = array(
						'path' => str_replace( $plugin_dir, '', $file_path ),
						'type' => 'js',
						'matches' => $this->count_matches( $content, $component_key ),
					);
				}
			}
		}
		
		return $files;
	}

	/**
	 * Získat kontext stránky
	 */
	private function get_page_context( $page_type, $template ) {
		$context = array();
		
		if ( $page_type === 'page' ) {
			$context['description'] = 'Mapa aplikace';
			$context['related_files'] = array(
				array( 'path' => 'includes/Frontend_Map.php', 'role' => 'Loads map data' ),
				array( 'path' => 'assets/map/core.js', 'role' => 'Map core logic' ),
				array( 'path' => 'templates/', 'role' => 'Template files' ),
			);
		}
		
		if ( $template ) {
			$context['template'] = $template;
			if ( file_exists( DB_PLUGIN_DIR . 'templates/' . $template ) ) {
				$context['related_files'][] = array( 'path' => 'templates/' . $template, 'role' => 'Page template' );
			}
		}
		
		return $context;
	}

	/**
	 * Najít číslo řádku s textem
	 */
	private function find_text_line( $content, $text ) {
		$lines = explode( "\n", $content );
		foreach ( $lines as $num => $line ) {
			if ( stripos( $line, $text ) !== false ) {
				return $num + 1;
			}
		}
		return 0;
	}

	/**
	 * Spočítat výskyty textu
	 */
	private function count_matches( $content, $text ) {
		return substr_count( strtolower( $content ), strtolower( $text ) );
	}

	public function register() {
		add_action( 'admin_menu', array( $this, 'add_menu' ) );
		add_action( 'admin_post_db_feedback_update', array( $this, 'handle_update' ) );
		add_action( 'admin_post_db_feedback_delete', array( $this, 'handle_delete' ) );
		add_action( 'wp_ajax_db_feedback_update', array( $this, 'handle_ajax_update' ) );
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

		echo '<style>
.db-feedback-item {
	border: 1px solid #ddd;
	margin-bottom: 12px;
	border-radius: 4px;
}
.db-feedback-header {
	padding: 12px;
	background: #f5f5f5;
	cursor: pointer;
	display: flex;
	gap: 16px;
	align-items: center;
}
.db-feedback-header:hover { background: #e8e8e8; }
.db-feedback-header.expanded { background: #e8f4fd; }
.db-feedback-details {
	padding: 16px;
	display: none;
	border-top: 1px solid #ddd;
}
.db-feedback-details.show { display: block; }
.db-feedback-detail-row {
	margin-bottom: 12px;
}
.db-feedback-detail-row strong {
	display: inline-block;
	min-width: 120px;
}
.db-feedback-quickinfo {
	flex: 1;
}
.db-feedback-id { font-weight: bold; color: #2271b1; }
.db-feedback-message-preview { color: #666; font-style: italic; }
.db-feedback-actions {
	display: flex;
	gap: 8px;
	align-items: center;
}
.db-feedback-status-select, .db-feedback-severity-select {
	padding: 4px 8px;
	border: 1px solid #8c8f94;
	border-radius: 3px;
}
.db-feedback-status-select { min-width: 120px; }
.db-feedback-severity-select { min-width: 100px; }
</style>';
		if ( ! empty( $items ) ) {
			foreach ( $items as $it ) {
				$u = null; $u_label = '';
				if ( isset( $it->user_id ) && $it->user_id ) { $u = get_userdata( intval( $it->user_id ) ); }
				if ( $u ) { $u_label = $u->display_name . ' (' . $u->user_login . ')'; }
				$message_preview = mb_strimwidth( (string) ( $it->text_snippet ?: $it->message ), 0, 100, '…' );
				echo '<div class="db-feedback-item" data-id="' . intval( $it->id ) . '">';
				echo '<div class="db-feedback-header" data-toggle="details-' . intval( $it->id ) . '">';
				echo '<div class="db-feedback-quickinfo">';
				echo '<span class="db-feedback-id">#' . intval( $it->id ) . '</span> ';
				echo '<span class="dashicons dashicons-' . esc_attr( $it->type === 'bug' ? 'warning' : ( $it->type === 'suggestion' ? 'lightbulb' : 'edit' ) ) . '"></span> ';
				echo '<span class="db-feedback-message-preview">' . esc_html( $message_preview ) . '</span>';
				echo '</div>';
				echo '<div class="db-feedback-actions">';
				echo '<select class="db-feedback-status-select" data-field="status" data-id="' . intval( $it->id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'db_feedback_update_' . intval( $it->id ) ) ) . '">';
				foreach ( array( 'open','in_progress','resolved' ) as $st ) {
					echo '<option value="' . esc_attr( $st ) . '"' . selected( $it->status, $st, false ) . '>' . esc_html( $st ) . '</option>';
				}
				echo '</select>';
				echo '<select class="db-feedback-severity-select" data-field="severity" data-id="' . intval( $it->id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'db_feedback_update_' . intval( $it->id ) ) ) . '">';
				foreach ( array( 'low','medium','high' ) as $sv ) {
					echo '<option value="' . esc_attr( $sv ) . '"' . selected( $it->severity, $sv, false ) . '>' . esc_html( $sv ) . '</option>';
				}
				echo '</select>';
				echo '</div>';
				echo '</div>';
				echo '<div class="db-feedback-details" id="details-' . intval( $it->id ) . '">';
				echo '<div class="db-feedback-detail-row"><strong>Vytvořeno:</strong> ' . esc_html( $it->created_at ) . '</div>';
				echo '<div class="db-feedback-detail-row"><strong>Uživatel:</strong> ' . esc_html( $u_label ) . '</div>';
				echo '<div class="db-feedback-detail-row"><strong>URL stránky:</strong> <a href="' . esc_url( $it->page_url ) . '" target="_blank">' . esc_html( $it->page_url ) . ' <span class="dashicons dashicons-external"></span></a></div>';
				echo '<div class="db-feedback-detail-row"><strong>Typ stránky:</strong> ' . esc_html( $it->page_type ) . '</div>';
				echo '<div class="db-feedback-detail-row"><strong>Template:</strong> <code>' . esc_html( $it->template ) . '</code></div>';
				echo '<div class="db-feedback-detail-row"><strong>Komponenta:</strong> <code>' . esc_html( $it->component_key ) . '</code></div>';
				echo '<div class="db-feedback-detail-row"><strong>DOM selektor:</strong> <code>' . esc_html( $it->dom_selector ?? '' ) . '</code></div>';
				if ( $it->text_snippet ) {
					echo '<div class="db-feedback-detail-row"><strong>Text na stránce:</strong><pre style="background:#f0f0f0;padding:8px;margin:8px 0;border-radius:3px;max-height:150px;overflow:auto;white-space:pre-wrap;word-wrap:break-word;">' . esc_html( $it->text_snippet ) . '</pre></div>';
				}
				echo '<div class="db-feedback-detail-row"><strong>Popis:</strong><pre style="background:#f0f0f0;padding:8px;margin:8px 0;border-radius:3px;max-height:200px;overflow:auto;white-space:pre-wrap;word-wrap:break-word;">' . esc_html( $it->message ) . '</pre></div>';
				
				// Zobrazit kód kontext
				$code_context = $this->get_code_context( $it );
				if ( ! empty( $code_context ) ) {
					echo '<div class="db-feedback-detail-row" style="margin-top:20px;padding-top:16px;border-top:2px solid #049FE8;"><strong style="color:#049FE8;">🔍 Kód kontext:</strong>';
					if ( ! empty( $code_context['files_with_text'] ) ) {
						echo '<div style="margin-top:8px;"><strong>Soubory obsahující text:</strong><ul style="margin:8px 0;padding-left:24px;">';
						foreach ( $code_context['files_with_text'] as $file_info ) {
							echo '<li><code>' . esc_html( $file_info['path'] ) . '</code>';
							if ( ! empty( $file_info['line'] ) ) {
								echo ' (řádek ' . intval( $file_info['line'] ) . ')';
							}
							echo '</li>';
						}
						echo '</ul></div>';
					}
					if ( ! empty( $code_context['component_files'] ) ) {
						echo '<div style="margin-top:8px;"><strong>Soubory s komponentou:</strong><ul style="margin:8px 0;padding-left:24px;">';
						foreach ( $code_context['component_files'] as $file_info ) {
							echo '<li><code>' . esc_html( $file_info['path'] ) . '</code>';
							if ( ! empty( $file_info['matches'] ) ) {
								echo ' (' . intval( $file_info['matches'] ) . ' výskytů)';
							}
							echo '</li>';
						}
						echo '</ul></div>';
					}
					if ( ! empty( $code_context['page_context']['related_files'] ) ) {
						echo '<div style="margin-top:8px;"><strong>Související soubory:</strong><ul style="margin:8px 0;padding-left:24px;">';
						foreach ( $code_context['page_context']['related_files'] as $file_info ) {
							echo '<li><code>' . esc_html( $file_info['path'] ) . '</code>';
							if ( ! empty( $file_info['role'] ) ) {
								echo ' - ' . esc_html( $file_info['role'] );
							}
							echo '</li>';
						}
						echo '</ul></div>';
					}
					echo '</div>';
				}
				
				echo '<div style="margin-top:16px;padding-top:16px;border-top:1px solid #ddd;display:flex;gap:8px;align-items:center;flex-wrap:wrap">';
				echo '<input type="text" class="db-feedback-admin-note" data-id="' . intval( $it->id ) . '" data-nonce="' . esc_attr( wp_create_nonce( 'db_feedback_update_' . intval( $it->id ) ) ) . '" placeholder="Poznámka admina..." value="' . esc_attr( get_option( 'db_feedback_note_' . intval( $it->id ), '' ) ) . '" style="flex:1;min-width:200px;" /> ';
				// Kopírovací tlačítko
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
					'admin_note' => (string) get_option( 'db_feedback_note_' . intval( $it->id ), '' ),
					'code_context' => $code_context,
				);
				$copy_attr = esc_attr( wp_json_encode( $copy_payload ) );
				echo '<button type="button" class="button copy-feedback" data-feedback="' . $copy_attr . '">📋 Kopírovat</button> ';
				echo '<a class="button button-link-delete db-feedback-delete" href="' . esc_url( wp_nonce_url( admin_url( 'admin-post.php?action=db_feedback_delete&id=' . intval( $it->id ) ), 'db_feedback_delete_' . intval( $it->id ) ) ) . '" data-id="' . intval( $it->id ) . '">🗑️ Smazat</a>';
				echo '</div>';
				echo '</div>';
				echo '</div>';
			}
		} else {
			echo '<div class="db-feedback-item"><div class="db-feedback-header" style="cursor:default;background:#fff;"><em>Žádné záznamy</em></div></div>';
		}

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
		// Inline JS pro interaktivitu
		echo '<script>
(function() {
var updateUrl = ' . json_encode( esc_url( admin_url( 'admin-ajax.php' ) ) ) . ';

// Expand/collapse details
document.addEventListener("click", function(e) {
  var hdr = e.target.closest && e.target.closest("[data-toggle]");
  if (!hdr) return;
  var targetId = hdr.getAttribute("data-toggle");
  if (!targetId) return;
  var details = document.getElementById(targetId);
  if (!details) return;
  hdr.classList.toggle("expanded");
  details.classList.toggle("show");
});

// AJAX update status/severity
document.addEventListener("change", function(e) {
  var sel = e.target.closest && (e.target.closest(".db-feedback-status-select") || e.target.closest(".db-feedback-severity-select"));
  if (!sel) return;
  var id = sel.getAttribute("data-id");
  var field = sel.getAttribute("data-field");
  var value = sel.value;
  var nonce = sel.getAttribute("data-nonce");
  if (!id || !field || !nonce) return;
  var formData = new FormData();
  formData.append("action", "db_feedback_update");
  formData.append("id", id);
  formData.append(field, value);
  formData.append("_wpnonce", nonce);
  fetch(updateUrl, { method: "POST", body: formData }).then(function(r) {
    return r.json();
  }).then(function(data) {
    if (!data.success) {
      alert("Chyba: " + (data.data && data.data.message || "neznámá chyba"));
    }
  }).catch(function(err) {
    alert("Chyba při ukládání: " + err.message);
  });
});

// Auto-save admin note
var noteTimeout = {};
document.addEventListener("input", function(e) {
  var inp = e.target.closest && e.target.closest(".db-feedback-admin-note");
  if (!inp) return;
  var id = inp.getAttribute("data-id");
  var nonce = inp.getAttribute("data-nonce");
  if (!id || !nonce) return;
  clearTimeout(noteTimeout[id]);
  noteTimeout[id] = setTimeout(function() {
    var formData = new FormData();
    formData.append("action", "db_feedback_update");
    formData.append("id", id);
    formData.append("admin_note", inp.value);
    formData.append("_wpnonce", nonce);
    fetch(updateUrl, { method: "POST", body: formData }).catch(function(err) {
      console.error("Note save error:", err);
    });
  }, 1000);
});

// Copy feedback
document.addEventListener("click", function(e) {
  var btn = e.target.closest && e.target.closest(".copy-feedback");
  if (!btn) return;
  try {
    var data = JSON.parse(btn.getAttribute("data-feedback") || "{}");
    var lines = [];
    lines.push("### Feedback");
    lines.push("- id: " + (data.id || ""));
    lines.push("- created_at: " + (data.created_at || ""));
    lines.push("- status: " + (data.status || ""));
    lines.push("- type: " + (data.type || ""));
    lines.push("- severity: " + (data.severity || ""));
    lines.push("- page_url: " + (data.page_url || ""));
    lines.push("- page_type: " + (data.page_type || ""));
    lines.push("- template: " + (data.template || ""));
    lines.push("- component_key: " + (data.component_key || ""));
    lines.push("- dom_selector: " + (data.dom_selector || ""));
    if (data.admin_note) {
      lines.push("- admin_note: " + (data.admin_note || ""));
    }
    lines.push("- text_snippet:\n```\n" + (data.text_snippet || "") + "\n```");
    lines.push("- message:\n```\n" + (data.message || "") + "\n```");
    if (data.reported_by && data.reported_by.login) {
      lines.push("- reported_by: " + data.reported_by.login + " (" + (data.reported_by.name || "") + ")");
    }
    if (data.code_context) {
      lines.push("- code_context:");
      if (data.code_context.files_with_text && data.code_context.files_with_text.length > 0) {
        lines.push("  Soubory obsahující text:");
        data.code_context.files_with_text.forEach(function(f) {
          var line = "    - " + (f.path || "") + " (" + (f.type || "") + ")";
          if (f.line) line += " - řádek " + f.line;
          lines.push(line);
        });
      }
      if (data.code_context.component_files && data.code_context.component_files.length > 0) {
        lines.push("  Soubory s komponentou:");
        data.code_context.component_files.forEach(function(f) {
          var line = "    - " + (f.path || "");
          if (f.matches) line += " (" + f.matches + " výskytů)";
          lines.push(line);
        });
      }
      if (data.code_context.page_context && data.code_context.page_context.related_files) {
        lines.push("  Související soubory:");
        data.code_context.page_context.related_files.forEach(function(f) {
          lines.push("    - " + (f.path || "") + " - " + (f.role || ""));
        });
      }
    }
    var text = lines.join("\\n");
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).then(function() {
        btn.textContent = "✓ Zkopírováno";
        setTimeout(function() { btn.textContent = "📋 Kopírovat"; }, 2000);
      });
    } else {
      var ta = document.createElement("textarea");
      ta.style.position = "fixed"; ta.style.opacity = "0";
      ta.value = text;
      document.body.appendChild(ta);
      ta.select();
      try {
        document.execCommand("copy");
        btn.textContent = "✓ Zkopírováno";
        setTimeout(function() { btn.textContent = "📋 Kopírovat"; }, 2000);
      } finally {
        document.body.removeChild(ta);
      }
    }
  } catch(err) {
    alert("Kopírování selhalo: " + err.message);
  }
});

// Delete confirmation
document.addEventListener("click", function(e) {
  var link = e.target.closest && e.target.closest(".db-feedback-delete");
  if (!link) return;
  if (!confirm("Opravdu smazat záznam #" + link.getAttribute("data-id") + "?")) {
    e.preventDefault();
  }
});
})();
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

	public function handle_ajax_update() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => 'Forbidden' ) );
		}
		$id = intval( $_POST['id'] ?? 0 );
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => 'Invalid ID' ) );
		}
		check_admin_referer( 'db_feedback_update_' . $id );
		$status = isset( $_POST['status'] ) ? sanitize_text_field( wp_unslash( $_POST['status'] ) ) : '';
		$severity = isset( $_POST['severity'] ) ? sanitize_text_field( wp_unslash( $_POST['severity'] ) ) : '';
		// Poznámku ukládej pouze tehdy, pokud skutečně přišla v payloadu,
		// aby se nepřepisovala prázdným řetězcem při změně statusu/severity
		if ( array_key_exists( 'admin_note', $_POST ) ) {
			$admin_note = sanitize_text_field( wp_unslash( $_POST['admin_note'] ) );
			update_option( 'db_feedback_note_' . $id, $admin_note, false );
		}
		global $wpdb;
		$table = $wpdb->prefix . 'db_feedback';
		$data = array();
		if ( $status ) $data['status'] = $status;
		if ( $severity ) $data['severity'] = $severity;
		if ( ! empty( $data ) ) {
			$wpdb->update( $table, $data, array( 'id' => $id ) );
		}
		wp_send_json_success( array( 'updated' => true, 'id' => $id ) );
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


