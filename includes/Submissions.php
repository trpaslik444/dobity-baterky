<?php
/**
 * User Submissions – CPT a pomocné funkce pro podání uživatelů
 * @package DobityBaterky
 */

namespace DB;

if (!defined('ABSPATH')) exit;

class Submissions {
	private static $instance = null;

	public static function get_instance() {
		if (self::$instance === null) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	public function register() {
		add_action('init', array($this, 'register_cpt'));
		add_action('add_meta_boxes', array($this, 'register_meta_boxes'));
		add_action('save_post', array($this, 'save_submission_meta'), 10, 2);
	}

	public function register_cpt() {
		$labels = array(
			'name' => __('Uživatelská podání', 'dobity-baterky'),
			'singular_name' => __('Uživatelské podání', 'dobity-baterky'),
			'menu_name' => __('Podání', 'dobity-baterky'),
			'add_new' => __('Přidat podání', 'dobity-baterky'),
			'add_new_item' => __('Přidat nové podání', 'dobity-baterky'),
			'edit_item' => __('Upravit podání', 'dobity-baterky'),
			'new_item' => __('Nové podání', 'dobity-baterky'),
			'view_item' => __('Zobrazit podání', 'dobity-baterky'),
			'search_items' => __('Hledat podání', 'dobity-baterky'),
			'not_found' => __('Nenalezeno', 'dobity-baterky'),
			'not_found_in_trash' => __('V koši nenalezeno', 'dobity-baterky'),
		);

		$args = array(
			'labels' => $labels,
			'public' => false,
			'show_ui' => true,
			'show_in_menu' => false,
			'supports' => array('title', 'editor', 'author'),
			'capability_type' => 'post',
			'map_meta_cap' => true,
			'rewrite' => false,
		);

		register_post_type('user_submission', $args);
	}

	public function register_meta_boxes() {
		add_meta_box(
			'user_submission_details',
			__('Detaily podání', 'dobity-baterky'),
			array($this, 'render_meta_box'),
			'user_submission',
			'normal',
			'high'
		);
	}

	public function render_meta_box($post) {
		wp_nonce_field('user_submission_save', 'user_submission_nonce');
		$target_type = get_post_meta($post->ID, '_target_post_type', true);
		$lat = get_post_meta($post->ID, '_lat', true);
		$lng = get_post_meta($post->ID, '_lng', true);
		$address = get_post_meta($post->ID, '_address', true);
		$rating = get_post_meta($post->ID, '_rating', true);
		$comment = get_post_meta($post->ID, '_comment', true);
		$status = get_post_meta($post->ID, '_submission_status', true);
		?>
		<p>
			<label for="_target_post_type"><strong><?php esc_html_e('Typ cílového záznamu', 'dobity-baterky'); ?></strong></label><br/>
			<select name="_target_post_type" id="_target_post_type">
				<option value="charging_location" <?php selected($target_type, 'charging_location'); ?>><?php esc_html_e('Nabíjecí místo', 'dobity-baterky'); ?></option>
				<option value="poi" <?php selected($target_type, 'poi'); ?>><?php esc_html_e('POI', 'dobity-baterky'); ?></option>
				<option value="rv_spot" <?php selected($target_type, 'rv_spot'); ?>><?php esc_html_e('RV místo', 'dobity-baterky'); ?></option>
			</select>
		</p>
		<p>
			<label for="_address"><strong><?php esc_html_e('Adresa', 'dobity-baterky'); ?></strong></label><br/>
			<input type="text" class="regular-text" id="_address" name="_address" value="<?php echo esc_attr($address); ?>" />
		</p>
		<p style="display:flex; gap:10px; align-items:center;">
			<span><label for="_lat"><strong><?php esc_html_e('Lat', 'dobity-baterky'); ?></strong></label><br/>
			<input type="text" id="_lat" name="_lat" value="<?php echo esc_attr($lat); ?>" style="width:150px;" /></span>
			<span><label for="_lng"><strong><?php esc_html_e('Lng', 'dobity-baterky'); ?></strong></label><br/>
			<input type="text" id="_lng" name="_lng" value="<?php echo esc_attr($lng); ?>" style="width:150px;" /></span>
		</p>
		<p style="display:flex; gap:10px; align-items:center;">
			<span><label for="_rating"><strong><?php esc_html_e('Hodnocení (0–5)', 'dobity-baterky'); ?></strong></label><br/>
			<input type="number" step="1" min="0" max="5" id="_rating" name="_rating" value="<?php echo esc_attr($rating); ?>" style="width:100px;" /></span>
			<span style="flex:1;"><label for="_comment"><strong><?php esc_html_e('Komentář', 'dobity-baterky'); ?></strong></label><br/>
			<input type="text" id="_comment" name="_comment" value="<?php echo esc_attr($comment); ?>" class="regular-text" /></span>
		</p>
		<p>
			<label for="_submission_status"><strong><?php esc_html_e('Stav podání', 'dobity-baterky'); ?></strong></label><br/>
			<select name="_submission_status" id="_submission_status">
				<?php $statuses = array('draft','pending_review','validated','approved','rejected');
				foreach ($statuses as $s) {
					printf('<option value="%1$s" %2$s>%1$s</option>', esc_attr($s), selected($status, $s, false));
				}
				?>
			</select>
		</p>
		<?php
	}

	public function save_submission_meta($post_id, $post) {
		if ($post->post_type !== 'user_submission') return;
		if (!isset($_POST['user_submission_nonce']) || !wp_verify_nonce($_POST['user_submission_nonce'], 'user_submission_save')) return;
		if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) return;
		if (!current_user_can('edit_post', $post_id)) return;

		$fields = array(
			'_target_post_type' => 'sanitize_text_field',
			'_address' => 'sanitize_text_field',
			'_lat' => 'sanitize_text_field',
			'_lng' => 'sanitize_text_field',
			'_rating' => 'intval',
			'_comment' => 'sanitize_text_field',
			'_submission_status' => 'sanitize_text_field',
		);

		foreach ($fields as $key => $cb) {
			if (isset($_POST[$key])) {
				$value = call_user_func($cb, $_POST[$key]);
				update_post_meta($post_id, $key, $value);
			}
		}
	}
}

