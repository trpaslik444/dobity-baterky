<?php
/**
 * Charging Icon Manager
 * Správa SVG ikon pro typy konektorů
 */

namespace DB;

class Charging_Icon_Manager {
    
    /**
     * Standardní SVG ikony pro typy konektorů
     */
    const STANDARD_ICONS = [
        'type-2' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M12 2C6.48 2 2 6.48 2 12s4.48 10 10 10 10-4.48 10-10S17.52 2 12 2zm0 18c-4.41 0-8-3.59-8-8s3.59-8 8-8 8 3.59 8 8-3.59 8-8 8z" fill="currentColor"/>
            <circle cx="12" cy="12" r="3" fill="currentColor"/>
            <path d="M12 7c-2.76 0-5 2.24-5 5s2.24 5 5 5 5-2.24 5-5-2.24-5-5-5z" fill="currentColor"/>
        </svg>',
        
        'ccs2' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="4" y="8" width="16" height="8" rx="2" fill="currentColor"/>
            <circle cx="8" cy="12" r="1.5" fill="white"/>
            <circle cx="16" cy="12" r="1.5" fill="white"/>
            <rect x="6" y="10" width="2" height="4" fill="white"/>
            <rect x="16" y="10" width="2" height="4" fill="white"/>
        </svg>',
        
        'chademo' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="6" y="6" width="12" height="12" rx="2" fill="currentColor"/>
            <circle cx="12" cy="12" r="4" fill="white"/>
            <path d="M12 8v8M8 12h8" stroke="currentColor" stroke-width="2"/>
        </svg>',
        
        'schuko' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="8" y="6" width="8" height="12" rx="1" fill="currentColor"/>
            <circle cx="10" cy="10" r="1" fill="white"/>
            <circle cx="14" cy="10" r="1" fill="white"/>
            <rect x="9" y="14" width="6" height="2" fill="white"/>
        </svg>',
        
        'gb-t' => '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
            <rect x="6" y="8" width="12" height="8" rx="1" fill="currentColor"/>
            <circle cx="9" cy="12" r="1" fill="white"/>
            <circle cx="15" cy="12" r="1" fill="white"/>
            <rect x="8" y="10" width="8" height="4" fill="white"/>
        </svg>'
    ];
    
    /**
     * Inicializace - registrace akcí
     */
    public static function init() {
        add_action('init', [__CLASS__, 'register_hooks']);
    }
    
    /**
     * Registrace WordPress hooků
     */
    public static function register_hooks() {
        // Přidat meta box pro ikony v admin
        add_action('charger_type_edit_form_fields', [__CLASS__, 'add_icon_field']);
        add_action('charger_type_add_form_fields', [__CLASS__, 'add_icon_field']);
        add_action('edited_charger_type', [__CLASS__, 'save_icon']);
        add_action('created_charger_type', [__CLASS__, 'save_icon']);
        
        // REST API endpoint pro ikony
        add_action('rest_api_init', [__CLASS__, 'register_rest_routes']);
    }
    
    /**
     * Přidat pole pro ikonu v admin formuláři
     */
    public static function add_icon_field($term) {
        $icon_svg = '';
        $use_standard = true;
        
        if (is_object($term)) {
            $icon_svg = get_term_meta($term->term_id, 'charger_icon_svg', true);
            $use_standard = get_term_meta($term->term_id, 'charger_icon_use_standard', true) !== '0';
        }
        
        $term_slug = is_object($term) ? $term->slug : '';
        $standard_icon = isset(self::STANDARD_ICONS[$term_slug]) ? self::STANDARD_ICONS[$term_slug] : '';
        
        ?>
        <tr class="form-field">
            <th scope="row">
                <label for="charger_icon_use_standard"><?php _e('Použít standardní ikonu', 'dobity-baterky'); ?></label>
            </th>
            <td>
                <input type="checkbox" id="charger_icon_use_standard" name="charger_icon_use_standard" value="1" <?php checked($use_standard); ?>>
                <label for="charger_icon_use_standard"><?php _e('Použít přednastavenou SVG ikonu', 'dobity-baterky'); ?></label>
                <p class="description">
                    <?php if ($standard_icon): ?>
                        <strong>Dostupná standardní ikona:</strong>
                        <div style="margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">
                            <?php echo $standard_icon; ?>
                        </div>
                    <?php else: ?>
                        <em>Pro tento typ konektoru není k dispozici standardní ikona.</em>
                    <?php endif; ?>
                </p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row">
                <label for="charger_icon_svg"><?php _e('Vlastní SVG ikona', 'dobity-baterky'); ?></label>
            </th>
            <td>
                <textarea id="charger_icon_svg" name="charger_icon_svg" rows="10" cols="50" style="width: 100%; font-family: monospace;"><?php echo esc_textarea($icon_svg); ?></textarea>
                <p class="description">
                    <?php _e('Vložte vlastní SVG kód ikony. Pokud je prázdné, použije se standardní ikona (pokud je k dispozici).', 'dobity-baterky'); ?>
                </p>
                <?php if ($icon_svg): ?>
                    <div style="margin: 10px 0; padding: 10px; border: 1px solid #ddd; background: #f9f9f9;">
                        <strong>Náhled vlastní ikony:</strong>
                        <div style="margin-top: 10px;">
                            <?php echo $icon_svg; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
    
    /**
     * Uložit ikonu při ukládání termu
     */
    public static function save_icon($term_id) {
        if (isset($_POST['charger_icon_use_standard'])) {
            update_term_meta($term_id, 'charger_icon_use_standard', '1');
        } else {
            update_term_meta($term_id, 'charger_icon_use_standard', '0');
        }
        
        if (isset($_POST['charger_icon_svg'])) {
            $svg = sanitize_textarea_field($_POST['charger_icon_svg']);
            update_term_meta($term_id, 'charger_icon_svg', $svg);
        }
    }
    
    /**
     * Získat SVG ikonu pro typ konektoru
     */
    public static function get_connector_icon_svg($term_id) {
        $use_standard = get_term_meta($term_id, 'charger_icon_use_standard', true) !== '0';
        
        if ($use_standard) {
            $term = get_term($term_id);
            if ($term && !is_wp_error($term) && isset(self::STANDARD_ICONS[$term->slug])) {
                return self::STANDARD_ICONS[$term->slug];
            }
        }
        
        // Fallback na vlastní SVG
        $custom_svg = get_term_meta($term_id, 'charger_icon_svg', true);
        if ($custom_svg) {
            return $custom_svg;
        }
        
        // Fallback na standardní ikonu
        $term = get_term($term_id);
        if ($term && !is_wp_error($term) && isset(self::STANDARD_ICONS[$term->slug])) {
            return self::STANDARD_ICONS[$term->slug];
        }
        
        return null;
    }
    
    /**
     * Registrace REST API routes
     */
    public static function register_rest_routes() {
        register_rest_route('db/v1', '/connector-icons', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_all_icons'],
            'permission_callback' => '__return_true'
        ]);
        
        register_rest_route('db/v1', '/connector-icons/(?P<term_id>\d+)', [
            'methods' => 'GET',
            'callback' => [__CLASS__, 'get_icon_by_term_id'],
            'permission_callback' => '__return_true'
        ]);
    }
    
    /**
     * REST API: Všechny ikony konektorů
     */
    public static function get_all_icons($request) {
        $terms = get_terms([
            'taxonomy' => 'charger_type',
            'hide_empty' => false,
        ]);
        
        $icons = [];
        foreach ($terms as $term) {
            $svg = self::get_connector_icon_svg($term->term_id);
            if ($svg) {
                $icons[$term->slug] = [
                    'term_id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'svg' => $svg
                ];
            }
        }
        
        return rest_ensure_response($icons);
    }
    
    /**
     * REST API: Ikona podle term ID
     */
    public static function get_icon_by_term_id($request) {
        $term_id = (int) $request['term_id'];
        $svg = self::get_connector_icon_svg($term_id);
        
        if (!$svg) {
            return new \WP_Error('icon_not_found', 'Ikona nebyla nalezena', ['status' => 404]);
        }
        
        $term = get_term($term_id);
        if (is_wp_error($term)) {
            return new \WP_Error('term_not_found', 'Typ konektoru nebyl nalezen', ['status' => 404]);
        }
        
        return rest_ensure_response([
            'term_id' => $term->term_id,
            'name' => $term->name,
            'slug' => $term->slug,
            'svg' => $svg
        ]);
    }
    
    /**
     * Inicializovat standardní ikony pro existující typy
     */
    public static function init_standard_icons() {
        $terms = get_terms([
            'taxonomy' => 'charger_type',
            'hide_empty' => false,
        ]);
        
        foreach ($terms as $term) {
            if (isset(self::STANDARD_ICONS[$term->slug])) {
                // Nastavit jako standardní, pokud ještě není nastaveno
                $current_use_standard = get_term_meta($term->term_id, 'charger_icon_use_standard', true);
                if ($current_use_standard === '') {
                    update_term_meta($term->term_id, 'charger_icon_use_standard', '1');
                }
            }
        }
    }
}
