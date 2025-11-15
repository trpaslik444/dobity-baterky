<?php
/**
 * Early Adopters Mailing List Admin
 * Zobrazí seznam emailů early adopters pro kopírování do mailu
 *
 * @package DobityBaterky
 */

namespace DB;

class Early_Adopters_Admin {
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
		add_submenu_page(
			'db-feedback', // Parent slug - Feedback menu
			'Early Adopters Mailing List',
			'Mailing List',
			'manage_options',
			'db-early-adopters',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Získá všechny emaily early adopters
	 * Používá stejnou logiku jako db_user_can_see_map()
	 */
	private function get_early_adopters_emails() {
		$emails = array();
		
		// Pokud není funkce db_user_can_see_map definována, vrátíme prázdný seznam
		if ( ! function_exists( 'db_user_can_see_map' ) ) {
			return $emails;
		}

		// Načíst plugin.php, pokud není načteno
		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		// Získat všechny uživatele
		$users = get_users( array(
			'fields' => array( 'ID', 'user_email', 'display_name', 'user_login' ),
		) );

		// Uložit původního uživatele
		$original_user_id = get_current_user_id();

		foreach ( $users as $user ) {
			// Dočasně nastavit aktuálního uživatele pro kontrolu
			wp_set_current_user( $user->ID );
			
			// Použít stejnou logiku jako db_user_can_see_map()
			$can_see_map = false;
			
			// Admin a Editor mají vždy přístup
			if ( current_user_can( 'administrator' ) || current_user_can( 'editor' ) ) {
				$can_see_map = true;
			}
			// Pokud je Members plugin aktivní, kontrolujeme 'access_app' capability
			elseif ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'members/members.php' ) ) {
				$cap = db_required_capability();
				if ( $cap && current_user_can( $cap ) ) {
					$can_see_map = true;
				}
			}
			// Pokud Members plugin není aktivní, povolíme přístup všem přihlášeným
			else {
				$can_see_map = true;
			}

			if ( $can_see_map ) {
				$user_stats = $this->get_user_stats( $user->ID );
				$emails[] = array(
					'id' => $user->ID,
					'email' => $user->user_email,
					'name' => $user->display_name ?: $user->user_login,
					'login' => $user->user_login,
					'stats' => $user_stats,
				);
			}
		}

		// Obnovit původního uživatele
		wp_set_current_user( $original_user_id );

		return $emails;
	}

	/**
	 * Získá statistiky pro uživatele
	 */
	private function get_user_stats( $user_id ) {
		global $wpdb;
		
		$stats = array(
			'registered' => null,
			'feedback_count' => 0,
			'last_feedback' => null,
			'favorites_count' => 0,
			'role' => '',
		);

		// Datum registrace
		$user = get_userdata( $user_id );
		if ( $user && isset( $user->user_registered ) ) {
			$stats['registered'] = $user->user_registered;
			$stats['role'] = ! empty( $user->roles ) ? implode( ', ', $user->roles ) : '';
		}

		// Počet feedbacků a poslední feedback
		$feedback_table = $wpdb->prefix . 'db_feedback';
		if ( $wpdb->get_var( "SHOW TABLES LIKE '{$feedback_table}'" ) === $feedback_table ) {
			$feedback_stats = $wpdb->get_row( $wpdb->prepare(
				"SELECT COUNT(*) as count, MAX(created_at) as last_feedback 
				FROM {$feedback_table} 
				WHERE user_id = %d",
				$user_id
			), ARRAY_A );
			
			if ( $feedback_stats ) {
				$stats['feedback_count'] = (int) $feedback_stats['count'];
				$stats['last_feedback'] = $feedback_stats['last_feedback'] ?: null;
			}
		}

		// Počet oblíbených míst
		if ( class_exists( 'DB\Favorites_Manager' ) ) {
			try {
				$favorites_manager = \DB\Favorites_Manager::get_instance();
				$state = $favorites_manager->get_state( $user_id );
				$stats['favorites_count'] = count( $state['assignments'] ?? array() );
			} catch ( \Exception $e ) {
				// Ignorovat chyby
			}
		}

		return $stats;
	}

	/**
	 * Formátuje emaily pro zobrazení (různé formáty)
	 */
	private function format_emails( $emails, $format = 'comma_separated' ) {
		$result = '';

		switch ( $format ) {
			case 'comma_separated':
				// Jednoduchý seznam emailů oddělených čárkami
				$result = implode( ', ', array_column( $emails, 'email' ) );
				break;

			case 'semicolon_separated':
				// Oddělené středníkem (pro některé emaily klienty)
				$result = implode( '; ', array_column( $emails, 'email' ) );
				break;

			case 'name_email':
				// Formát: "Jméno <email@example.com>"
				$formatted = array();
				foreach ( $emails as $item ) {
					$name = ! empty( $item['name'] ) ? $item['name'] : $item['login'];
					$formatted[] = sprintf( '%s <%s>', $name, $item['email'] );
				}
				$result = implode( ', ', $formatted );
				break;

			case 'one_per_line':
				// Jeden email na řádek
				$result = implode( "\n", array_column( $emails, 'email' ) );
				break;

			case 'bcc_format':
				// Pro BCC v mailu (čárky + mezery)
				$result = implode( ', ', array_column( $emails, 'email' ) );
				break;

			default:
				$result = implode( ', ', array_column( $emails, 'email' ) );
		}

		return $result;
	}

	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$emails = $this->get_early_adopters_emails();
		$count = count( $emails );
		?>
		<div class="wrap">
			<h1>Early Adopters Mailing List</h1>
			<p>Seznam emailů uživatelů s přístupem k mapové aplikaci (early adopters).</p>
			
			<?php if ( empty( $emails ) ) : ?>
				<div class="notice notice-warning">
					<p><strong>Žádní early adopters nenalezeni.</strong></p>
					<p>Early adopters jsou uživatelé s <code>access_app</code> capability nebo admin/editor role.</p>
					<?php
					// Debug informace
					if ( function_exists( 'is_plugin_active' ) && is_plugin_active( 'members/members.php' ) ) {
						$cap = function_exists( 'db_required_capability' ) ? db_required_capability() : 'access_app';
						echo '<p><strong>Debug:</strong> Members plugin je aktivní. Hledáme uživatele s capability: <code>' . esc_html( $cap ) . '</code></p>';
					} else {
						echo '<p><strong>Debug:</strong> Members plugin není aktivní. Měli by být zobrazeni všichni uživatelé.</p>';
					}
					$all_users = get_users( array( 'count_total' => true ) );
					echo '<p><strong>Celkem uživatelů v systému:</strong> ' . count( $all_users ) . '</p>';
					?>
				</div>
			<?php else : ?>
				<div class="notice notice-info">
					<p><strong>Celkem early adopters: <?php echo esc_html( $count ); ?></strong></p>
				</div>

				<div style="margin: 20px 0;">
					<h2>Formáty pro kopírování</h2>
					
					<h3>1. Jednoduchý seznam (čárky) - doporučeno pro Gmail/Outlook</h3>
					<textarea 
						id="emails-comma" 
						readonly 
						style="width: 100%; min-height: 100px; font-family: monospace; padding: 10px;"
						onclick="this.select(); document.execCommand('copy'); alert('Zkopírováno!');"
					><?php echo esc_textarea( $this->format_emails( $emails, 'comma_separated' ) ); ?></textarea>
					<p class="description">Klikněte do pole a stiskněte Ctrl+A (Cmd+A na Mac), pak Ctrl+C (Cmd+C) pro kopírování.</p>

					<h3>2. Seznam se středníkem</h3>
					<textarea 
						id="emails-semicolon" 
						readonly 
						style="width: 100%; min-height: 100px; font-family: monospace; padding: 10px;"
						onclick="this.select(); document.execCommand('copy'); alert('Zkopírováno!');"
					><?php echo esc_textarea( $this->format_emails( $emails, 'semicolon_separated' ) ); ?></textarea>

					<h3>3. Jméno + Email (formát mailu)</h3>
					<textarea 
						id="emails-name-email" 
						readonly 
						style="width: 100%; min-height: 150px; font-family: monospace; padding: 10px;"
						onclick="this.select(); document.execCommand('copy'); alert('Zkopírováno!');"
					><?php echo esc_textarea( $this->format_emails( $emails, 'name_email' ) ); ?></textarea>

					<h3>4. Jeden email na řádek</h3>
					<textarea 
						id="emails-one-per-line" 
						readonly 
						style="width: 100%; min-height: 200px; font-family: monospace; padding: 10px;"
						onclick="this.select(); document.execCommand('copy'); alert('Zkopírováno!');"
					><?php echo esc_textarea( $this->format_emails( $emails, 'one_per_line' ) ); ?></textarea>

					<h3>5. BCC formát (pro skryté kopie)</h3>
					<textarea 
						id="emails-bcc" 
						readonly 
						style="width: 100%; min-height: 100px; font-family: monospace; padding: 10px;"
						onclick="this.select(); document.execCommand('copy'); alert('Zkopírováno!');"
					><?php echo esc_textarea( $this->format_emails( $emails, 'bcc_format' ) ); ?></textarea>
				</div>

				<div style="margin: 30px 0; padding: 20px; background: #f0f0f0; border-left: 4px solid #0073aa;">
					<h3>📋 Detailní seznam (s informacemi a statistikami)</h3>
					<table class="wp-list-table widefat fixed striped" style="table-layout: auto;">
						<thead>
							<tr>
								<th style="width: 20%;">Email</th>
								<th style="width: 15%;">Jméno</th>
								<th style="width: 12%;">Login</th>
								<th style="width: 12%;">Registrován</th>
								<th style="width: 10%;">Role</th>
								<th style="width: 8%; text-align: center;">Feedbacky</th>
								<th style="width: 8%; text-align: center;">Oblíbené</th>
								<th style="width: 15%;">Poslední aktivita</th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $emails as $item ) : 
								$stats = $item['stats'] ?? array();
								$registered = $stats['registered'] ?? null;
								$registered_formatted = $registered ? date_i18n( 'd.m.Y', strtotime( $registered ) ) : '-';
								$days_since_registered = $registered ? floor( ( time() - strtotime( $registered ) ) / DAY_IN_SECONDS ) : null;
								
								$last_feedback = $stats['last_feedback'] ?? null;
								$last_feedback_formatted = $last_feedback ? date_i18n( 'd.m.Y', strtotime( $last_feedback ) ) : 'Nikdy';
								$days_since_feedback = $last_feedback ? floor( ( time() - strtotime( $last_feedback ) ) / DAY_IN_SECONDS ) : null;
								
								// Aktivita badge
								$activity_class = 'activity-none';
								$activity_text = 'Nikdy';
								if ( $days_since_feedback !== null ) {
									if ( $days_since_feedback <= 7 ) {
										$activity_class = 'activity-recent';
										$activity_text = $days_since_feedback === 0 ? 'Dnes' : ( $days_since_feedback === 1 ? 'Včera' : sprintf( 'Před %d dny', $days_since_feedback ) );
									} elseif ( $days_since_feedback <= 30 ) {
										$activity_class = 'activity-active';
										$activity_text = sprintf( 'Před %d dny', $days_since_feedback );
									} elseif ( $days_since_feedback <= 90 ) {
										$activity_class = 'activity-inactive';
										$activity_text = sprintf( 'Před %d dny', $days_since_feedback );
									} else {
										$activity_class = 'activity-inactive';
										$activity_text = sprintf( 'Před %d+ dny', $days_since_feedback );
									}
								}
							?>
								<tr>
									<td><strong><?php echo esc_html( $item['email'] ); ?></strong></td>
									<td><?php echo esc_html( $item['name'] ); ?></td>
									<td><code><?php echo esc_html( $item['login'] ); ?></code></td>
									<td>
										<?php echo esc_html( $registered_formatted ); ?>
										<?php if ( $days_since_registered !== null ) : ?>
											<br><small style="color: #666;"><?php printf( esc_html__( 'před %d dny', 'dobity-baterky' ), $days_since_registered ); ?></small>
										<?php endif; ?>
									</td>
									<td><small><?php echo esc_html( $stats['role'] ?? '-' ); ?></small></td>
									<td style="text-align: center;">
										<?php if ( ( $stats['feedback_count'] ?? 0 ) > 0 ) : ?>
											<strong style="color: #0073aa;"><?php echo esc_html( $stats['feedback_count'] ); ?></strong>
										<?php else : ?>
											<span style="color: #999;">0</span>
										<?php endif; ?>
									</td>
									<td style="text-align: center;">
										<?php if ( ( $stats['favorites_count'] ?? 0 ) > 0 ) : ?>
											<strong style="color: #f59e0b;"><?php echo esc_html( $stats['favorites_count'] ); ?></strong>
										<?php else : ?>
											<span style="color: #999;">0</span>
										<?php endif; ?>
									</td>
									<td>
										<span class="activity-badge activity-<?php echo esc_attr( $activity_class ); ?>" style="
											padding: 4px 8px;
											border-radius: 4px;
											font-size: 12px;
											<?php if ( $activity_class === 'activity-recent' ) : ?>
												background: #d4edda;
												color: #155724;
											<?php elseif ( $activity_class === 'activity-active' ) : ?>
												background: #d1ecf1;
												color: #0c5460;
											<?php elseif ( $activity_class === 'activity-inactive' ) : ?>
												background: #f8d7da;
												color: #721c24;
											<?php else : ?>
												background: #e2e3e5;
												color: #383d41;
											<?php endif; ?>
										">
											<?php echo esc_html( $last_feedback_formatted ); ?>
										</span>
										<?php if ( $days_since_feedback !== null && $days_since_feedback > 0 ) : ?>
											<br><small style="color: #666;"><?php printf( esc_html__( '(%d dní)', 'dobity-baterky' ), $days_since_feedback ); ?></small>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					
					<div style="margin-top: 20px; padding: 15px; background: #fff; border: 1px solid #ddd; border-radius: 4px;">
						<h4>📊 Souhrnné statistiky</h4>
						<?php
						$total_feedback = array_sum( array_column( array_column( $emails, 'stats' ), 'feedback_count' ) );
						$total_favorites = array_sum( array_column( array_column( $emails, 'stats' ), 'favorites_count' ) );
						$active_users = count( array_filter( $emails, function( $item ) {
							$last_feedback = $item['stats']['last_feedback'] ?? null;
							if ( ! $last_feedback ) return false;
							$days = floor( ( time() - strtotime( $last_feedback ) ) / DAY_IN_SECONDS );
							return $days <= 30;
						} ) );
						$recent_users = count( array_filter( $emails, function( $item ) {
							$last_feedback = $item['stats']['last_feedback'] ?? null;
							if ( ! $last_feedback ) return false;
							$days = floor( ( time() - strtotime( $last_feedback ) ) / DAY_IN_SECONDS );
							return $days <= 7;
						} ) );
						?>
						<ul style="list-style: none; padding: 0; display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px;">
							<li><strong>Celkem early adopters:</strong> <?php echo esc_html( $count ); ?></li>
							<li><strong>Aktivní (posledních 30 dní):</strong> <?php echo esc_html( $active_users ); ?></li>
							<li><strong>Velmi aktivní (posledních 7 dní):</strong> <?php echo esc_html( $recent_users ); ?></li>
							<li><strong>Celkem feedbacků:</strong> <?php echo esc_html( $total_feedback ); ?></li>
							<li><strong>Celkem oblíbených míst:</strong> <?php echo esc_html( $total_favorites ); ?></li>
							<li><strong>Průměr feedbacků/člověk:</strong> <?php echo esc_html( $count > 0 ? round( $total_feedback / $count, 1 ) : 0 ); ?></li>
						</ul>
					</div>
				</div>

				<script>
				// Přidat tlačítko "Kopírovat vše" pro každé textové pole
				document.addEventListener('DOMContentLoaded', function() {
					const textareas = document.querySelectorAll('textarea[readonly]');
					textareas.forEach(function(textarea) {
						const button = document.createElement('button');
						button.type = 'button';
						button.className = 'button button-secondary';
						button.textContent = '📋 Kopírovat';
						button.style.marginTop = '5px';
						button.onclick = function() {
							textarea.select();
							document.execCommand('copy');
							const originalText = button.textContent;
							button.textContent = '✓ Zkopírováno!';
							button.disabled = true;
							setTimeout(function() {
								button.textContent = originalText;
								button.disabled = false;
							}, 2000);
						};
						textarea.parentNode.insertBefore(button, textarea.nextSibling);
					});
				});
				</script>
			<?php endif; ?>
		</div>
		<?php
	}
}

