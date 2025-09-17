<?php
/**
 * Admin Moderace uživatelských podání
 * @package DobityBaterky
 */

namespace DB\Admin;

if (!defined('ABSPATH')) exit;

class Submissions_Admin {
	public function __construct() {
		add_action('admin_menu', array($this, 'add_admin_menu'));
		add_action('admin_post_db_submission_approve', array($this, 'handle_approve'));
		add_action('admin_post_db_submission_reject', array($this, 'handle_reject'));
		add_action('admin_post_db_submission_validate', array($this, 'handle_validate'));
		add_action('admin_post_db_submission_apply_suggestion', array($this, 'handle_apply_suggestion'));
	}

	public function add_admin_menu() {
		add_submenu_page(
			'tools.php',
			'Podání uživatelů',
			'Podání uživatelů',
			'manage_options',
			'db-user-submissions',
            array($this, 'render_page')
		);
	}

	public function render_page() {
		if (!current_user_can('manage_options')) return;
        if (isset($_GET['view']) && $_GET['view'] === 'detail' && !empty($_GET['submission_id'])) {
            $this->render_detail_page(intval($_GET['submission_id']));
            return;
        }
		$filter_status = isset($_GET['status']) ? sanitize_text_field($_GET['status']) : '';
		$args = array(
			'post_type' => 'user_submission',
			'posts_per_page' => 50,
			'post_status' => array('publish'),
			'orderby' => 'date',
			'order' => 'DESC',
		);
		$q = new \WP_Query($args);
		?>
		<div class="wrap">
			<h1>Moderace podání</h1>
			<p>Schvalujte či zamítejte podání uživatelů. Stav „validated“ vzniká po předkontrole.</p>
			<table class="widefat fixed striped">
				<thead>
					<tr>
						<th>ID</th>
						<th>Titulek</th>
						<th>Typ</th>
						<th>Poloha</th>
						<th>Stav</th>
                        <th>Validace</th>
						<th>Akce</th>
					</tr>
				</thead>
				<tbody>
					<?php if ($q->have_posts()): foreach ($q->posts as $p):
						$pid = $p->ID;
						$type = get_post_meta($pid, '_target_post_type', true);
						$lat = get_post_meta($pid, '_lat', true);
						$lng = get_post_meta($pid, '_lng', true);
						$status = get_post_meta($pid, '_submission_status', true) ?: 'pending_review';
						$approve_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_approve&submission_id='.$pid), 'db_submission_action_'.$pid);
						$reject_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_reject&submission_id='.$pid), 'db_submission_action_'.$pid);
						$validate_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_validate&submission_id='.$pid), 'db_submission_action_'.$pid);
						$validation = get_post_meta($pid, '_validation_result', true);
						$first = is_array($validation) && !empty($validation['suggestions']) ? $validation['suggestions'][0] : null;
					?>
                    <tr>
						<td><?php echo esc_html($pid); ?></td>
                        <td>
                            <a href="<?php echo esc_url( admin_url('tools.php?page=db-user-submissions&view=detail&submission_id='.$pid) ); ?>">
                                <?php echo esc_html(get_the_title($p)); ?>
                            </a>
                        </td>
						<td><?php echo esc_html($type); ?></td>
						<td><?php echo esc_html($lat.', '.$lng); ?></td>
						<td><?php echo esc_html($status); ?></td>
						<td>
							<?php if ($first): ?>
								<div><strong>Návrh:</strong> <?php echo esc_html($first['label'] ?? ''); ?></div>
								<div>(<?php echo esc_html(($first['lat'] ?? '').', '.($first['lng'] ?? '')); ?>)</div>
								<?php $apply_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_apply_suggestion&submission_id='.$pid.'&idx=0'), 'db_submission_action_'.$pid); ?>
								<a class="button" href="<?php echo esc_url($apply_url); ?>">Použít návrh</a>
							<?php else: ?>
								<a class="button" href="<?php echo esc_url($validate_url); ?>">Validovat ORS</a>
							<?php endif; ?>
						</td>
						<td>
							<a class="button button-primary" href="<?php echo esc_url($approve_url); ?>">Schválit</a>
							<a class="button" href="<?php echo esc_url($reject_url); ?>">Zamítnout</a>
						</td>
					</tr>
					<?php endforeach; else: ?>
					<tr><td colspan="6">Žádná podání.</td></tr>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}

    private function render_detail_page(int $submission_id) {
        $p = get_post($submission_id);
        if (!$p || $p->post_type !== 'user_submission') {
            echo '<div class="wrap"><h1>Podání nenalezeno</h1></div>';
            return;
        }
        $type = get_post_meta($submission_id, '_target_post_type', true);
        $lat = get_post_meta($submission_id, '_lat', true);
        $lng = get_post_meta($submission_id, '_lng', true);
        $address = get_post_meta($submission_id, '_address', true);
        $status = get_post_meta($submission_id, '_submission_status', true) ?: 'pending_review';
        $validation = get_post_meta($submission_id, '_validation_result', true);
        $approve_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_approve&submission_id='.$submission_id), 'db_submission_action_'.$submission_id);
        $reject_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_reject&submission_id='.$submission_id), 'db_submission_action_'.$submission_id);
        $validate_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_validate&submission_id='.$submission_id), 'db_submission_action_'.$submission_id);
        ?>
        <div class="wrap">
            <h1>Detail podání #<?php echo esc_html($submission_id); ?></h1>
            <p><a href="<?php echo esc_url( admin_url('tools.php?page=db-user-submissions') ); ?>">← Zpět na seznam</a></p>
            <div style="display:flex; gap:20px; align-items:flex-start; flex-wrap:wrap;">
                <div style="flex:1; min-width:320px;">
                    <table class="widefat fixed striped" style="margin-top:0;">
                        <tbody>
                            <tr><th>Titulek</th><td><?php echo esc_html(get_the_title($p)); ?></td></tr>
                            <tr><th>Typ</th><td><?php echo esc_html($type); ?></td></tr>
                            <tr><th>Adresa</th><td><?php echo esc_html($address); ?></td></tr>
                            <tr><th>Souřadnice</th><td><?php echo esc_html($lat.', '.$lng); ?></td></tr>
                            <tr><th>Stav</th><td><?php echo esc_html($status); ?></td></tr>
                        </tbody>
                    </table>
                    <div style="margin-top:12px; display:flex; gap:8px;">
                        <a class="button" href="<?php echo esc_url($validate_url); ?>">Validovat ORS</a>
                        <a class="button button-primary" href="<?php echo esc_url($approve_url); ?>">Schválit</a>
                        <a class="button" href="<?php echo esc_url($reject_url); ?>">Zamítnout</a>
                    </div>
                </div>
                <div style="flex:1.5; min-width:360px;">
                    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
                    <div id="db-subm-map" style="height:420px; border:1px solid #ddd; border-radius:6px;"></div>
                    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
                    <script>
                        (function(){
                            const lat = <?php echo is_numeric($lat)?json_encode((float)$lat):'null'; ?>;
                            const lng = <?php echo is_numeric($lng)?json_encode((float)$lng):'null'; ?>;
                            const map = L.map('db-subm-map');
                            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap', maxZoom: 19 }).addTo(map);
                            const center = (lat && lng) ? [lat, lng] : [50.08, 14.42];
                            map.setView(center, (lat && lng) ? 14 : 6);
                            if (lat && lng) { L.marker([lat, lng]).addTo(map).bindPopup('Podání').openPopup(); }
                        })();
                    </script>
                    <?php if (is_array($validation) && !empty($validation['suggestions'])): ?>
                        <div style="margin-top:12px;">
                            <h3>Návrhy z ORS</h3>
                            <?php foreach (array_slice($validation['suggestions'], 0, 5) as $i => $s): $apply_url = wp_nonce_url(admin_url('admin-post.php?action=db_submission_apply_suggestion&submission_id='.$submission_id.'&idx='.$i), 'db_submission_action_'.$submission_id); ?>
                                <div style="padding:8px; border:1px solid #eee; border-radius:6px; margin-bottom:8px;">
                                    <div><strong><?php echo esc_html($s['label'] ?? ''); ?></strong></div>
                                    <div><?php echo esc_html(($s['lat'] ?? '').', '.($s['lng'] ?? '')); ?><?php if (isset($s['confidence'])) echo ' – důvěra: '.esc_html($s['confidence']); ?></div>
                                    <div style="margin-top:6px; display:flex; gap:8px;">
                                        <a class="button" href="<?php echo esc_url($apply_url); ?>">Použít návrh</a>
                                        <button type="button" class="button" onclick="(function(){ var m = window['db_subm_map']; if (!m) { m = L.map('db-subm-map'); } m.setView([<?php echo esc_js($s['lat']); ?>, <?php echo esc_js($s['lng']); ?>], 15); L.marker([<?php echo esc_js($s['lat']); ?>, <?php echo esc_js($s['lng']); ?>]).addTo(m).bindPopup('Návrh ORS').openPopup(); })()">Vycentrovat</button>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <?php
    }

	public function handle_approve() {
		$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
		if (!$submission_id || !current_user_can('manage_options')) wp_die('Forbidden');
		check_admin_referer('db_submission_action_'.$submission_id);
		$this->approve_submission($submission_id);
		wp_redirect(admin_url('tools.php?page=db-user-submissions&approved=1'));
		exit;
	}

	public function handle_reject() {
		$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
		if (!$submission_id || !current_user_can('manage_options')) wp_die('Forbidden');
		check_admin_referer('db_submission_action_'.$submission_id);
		update_post_meta($submission_id, '_submission_status', 'rejected');
		wp_redirect(admin_url('tools.php?page=db-user-submissions&rejected=1'));
		exit;
	}

	public function handle_validate() {
		$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
		if (!$submission_id || !current_user_can('manage_options')) wp_die('Forbidden');
		check_admin_referer('db_submission_action_'.$submission_id);
		$validator = new \DB\Jobs\Submissions_Validator();
		$validator->validate($submission_id);
		wp_redirect(admin_url('tools.php?page=db-user-submissions&validated=1'));
		exit;
	}

	public function handle_apply_suggestion() {
		$submission_id = isset($_GET['submission_id']) ? intval($_GET['submission_id']) : 0;
		$idx = isset($_GET['idx']) ? intval($_GET['idx']) : 0;
		if (!$submission_id || !current_user_can('manage_options')) wp_die('Forbidden');
		check_admin_referer('db_submission_action_'.$submission_id);
		$validation = get_post_meta($submission_id, '_validation_result', true);
		if (is_array($validation) && !empty($validation['suggestions']) && isset($validation['suggestions'][$idx])) {
			$s = $validation['suggestions'][$idx];
			if (isset($s['lat']) && isset($s['lng'])) {
				update_post_meta($submission_id, '_lat', (float)$s['lat']);
				update_post_meta($submission_id, '_lng', (float)$s['lng']);
			}
			if (!empty($s['label'])) {
				update_post_meta($submission_id, '_address', sanitize_text_field($s['label']));
			}
		}
		wp_redirect(admin_url('tools.php?page=db-user-submissions&applied=1'));
		exit;
	}

	private function approve_submission($submission_id) {
		$post = get_post($submission_id);
		if (!$post || $post->post_type !== 'user_submission') return;
		$target_type = get_post_meta($submission_id, '_target_post_type', true);
		$lat = get_post_meta($submission_id, '_lat', true);
		$lng = get_post_meta($submission_id, '_lng', true);
		$address = get_post_meta($submission_id, '_address', true);
		$rating = get_post_meta($submission_id, '_rating', true);
		$comment = get_post_meta($submission_id, '_comment', true);

		$target_post_id = wp_insert_post(array(
			'post_type' => $target_type,
			'post_title' => $post->post_title ?: __('Nový bod', 'dobity-baterky'),
			'post_content' => $post->post_content ?: '',
			'post_status' => 'publish',
		));
		if (is_wp_error($target_post_id) || !$target_post_id) return;

		// Mapování meta klíčů dle typu CPT
		$meta_map = array(
			'charging_location' => array('lat' => '_db_lat', 'lng' => '_db_lng', 'address' => '_db_address'),
			'rv_spot' => array('lat' => '_rv_lat', 'lng' => '_rv_lng', 'address' => '_rv_address'),
			'poi' => array('lat' => '_poi_lat', 'lng' => '_poi_lng', 'address' => '_poi_address'),
		);
		$keys = isset($meta_map[$target_type]) ? $meta_map[$target_type] : $meta_map['charging_location'];

		// Základní meta (adresa/lat/lng)
		if ($address !== '') update_post_meta($target_post_id, $keys['address'], sanitize_text_field($address));
		if ($lat !== '') update_post_meta($target_post_id, $keys['lat'], (float)$lat);
		if ($lng !== '') update_post_meta($target_post_id, $keys['lng'], (float)$lng);

		// Vytvořit komentář s ratingem, pokud je hodnocení
		if ($rating !== '') {
			$commentdata = array(
				'comment_post_ID' => $target_post_id,
				'comment_content' => (string)$comment,
				'comment_approved' => 1,
			);
			$cid = wp_insert_comment($commentdata);
			if ($cid) {
				update_comment_meta($cid, 'db_rating', (int)$rating);
			}
		}

		update_post_meta($submission_id, '_submission_status', 'approved');
		update_post_meta($submission_id, '_approved_post_id', $target_post_id);
	}
}

