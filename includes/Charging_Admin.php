<?php
/**
 * Pokročilé admin rozhraní pro Nabíjecí lokality
 * @package DobityBaterky
 */

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

class Charging_Admin {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        add_action('admin_menu', [$this, 'add_admin_menu']);
        add_action('admin_enqueue_scripts', [$this, 'enqueue_admin_scripts']);
        add_action('wp_ajax_db_load_charging_by_filters', [$this, 'handle_load_charging_by_filters']);
        add_action('wp_ajax_db_batch_update_charging', [$this, 'handle_batch_update']);
        add_action('wp_ajax_db_bulk_delete_charging', [$this, 'handle_bulk_delete']);
        add_action('wp_ajax_db_export_charging_csv', [$this, 'handle_export_csv']);
        add_action('wp_ajax_db_import_charging_csv', [$this, 'handle_import_csv']);
        add_action('wp_ajax_db_update_charger_type_icon', [$this, 'handle_update_type_icon']);
        add_action('wp_ajax_db_update_all_charger_icons', [$this, 'handle_update_all_icons']);
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=charging_location',
            'Pokročilá správa nabíjecích stanic',
            'Pokročilá správa',
            'manage_options',
            'db-charging-admin',
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'charging_location_page_db-charging-admin') {
            return;
        }

        wp_enqueue_style(
            'db-charging-admin',
            DB_PLUGIN_URL . 'assets/admin.css',
            [],
            DB_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'db-charging-admin',
            DB_PLUGIN_URL . 'assets/charging-admin.js',
            ['jquery'],
            DB_PLUGIN_VERSION,
            true
        );

        wp_localize_script('db-charging-admin', 'dbChargingAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('db_charging_admin_nonce'),
            'strings' => [
                'confirmDelete' => 'Opravdu chcete smazat vybrané nabíjecí lokality?',
                'confirmUpdate' => 'Opravdu chcete aktualizovat vybrané nabíjecí lokality?',
                'updating' => 'Aktualizuji...',
                'deleting' => 'Mažu...',
                'success' => 'Operace dokončena úspěšně!',
                'error' => 'Chyba: ',
                'selectItems' => 'Vyberte alespoň jeden záznam'
            ]
        ]);
    }

    public function admin_page(): void {
        $charger_types = get_terms([
            'taxonomy' => 'charger_type',
            'hide_empty' => false,
        ]);

        $providers = get_terms([
            'taxonomy' => 'provider',
            'hide_empty' => false,
        ]);

        if (is_wp_error($charger_types)) {
            $charger_types = [];
        }
        if (is_wp_error($providers)) {
            $providers = [];
        }

        $total_locations = wp_count_posts('charging_location')->publish;
        $locations_with_coords = $this->count_locations_with_coordinates();
        $locations_with_icons = $this->count_locations_with_icons();

        ?>
        <div class="wrap">
            <h1>Pokročilá správa nabíjecích lokalit</h1>

            <!-- Statistiky -->
            <div class="db-charging-stats">
                <h2>📊 Přehled</h2>
                <div class="db-stats-grid">
                    <div class="db-stat-item">
                        <h3><?php echo $total_locations; ?></h3>
                        <p>Celkem lokalit</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo $locations_with_coords; ?></h3>
                        <p>S koordináty</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo $locations_with_icons; ?></h3>
                        <p>S ikonami</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo count($charger_types); ?></h3>
                        <p>Typů nabíječek</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo count($providers); ?></h3>
                        <p>Poskytovatelů</p>
                    </div>
                </div>
            </div>

            <!-- Batch operace -->
            <div class="db-batch-operations">
                <h2>🔄 Hromadné operace</h2>

                <!-- Filtry -->
                <div class="db-filters">
                    <h3>Filtry pro výběr nabíjecích lokalit</h3>
                    <form id="db-charging-filters">
                        <div class="db-filter-row">
                            <label>Typ nabíječky:</label>
                            <select name="charger_type" id="charger_type_filter">
                                <option value="">Všechny typy</option>
                                <?php foreach ($charger_types as $type): ?>
                                    <option value="<?php echo esc_attr($type->slug); ?>">
                                        <?php echo esc_html($type->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label>Poskytovatel:</label>
                            <select name="provider" id="provider_filter">
                                <option value="">Všichni poskytovatelé</option>
                                <?php foreach ($providers as $provider): ?>
                                    <option value="<?php echo esc_attr($provider->slug); ?>">
                                        <?php echo esc_html($provider->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label>Stav koordinát:</label>
                            <select name="coords_status" id="coords_status_filter">
                                <option value="">Všechny</option>
                                <option value="with_coords">S koordináty</option>
                                <option value="without_coords">Bez koordinát</option>
                            </select>

                            <label>Stav ikon:</label>
                            <select name="icon_status" id="icon_status_filter">
                                <option value="">Všechny</option>
                                <option value="with_icons">S ikonami</option>
                                <option value="without_icons">Bez ikon</option>
                            </select>

                            <label>Limit:</label>
                            <input type="number" name="limit" id="limit_filter" value="100" min="1" max="1000">
                        </div>

                        <button type="button" id="load-charging-btn" class="button button-primary">
                            Načíst nabíjecí lokality podle filtrů
                        </button>
                    </form>
                </div>

                <!-- Seznam nabíjecích lokalit -->
                <div class="db-charging-list" id="db-charging-list" style="display: none;">
                    <h3>Vybrané nabíjecí lokality pro úpravu</h3>
                    <div class="db-charging-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all-charging"></th>
                                    <th>Název</th>
                                    <th>Typ nabíječky</th>
                                    <th>Poskytovatel</th>
                                    <th>Koordináty</th>
                                    <th>Ikona</th>
                                    <th>Výkon (kW)</th>
                                    <th>Akce</th>
                                </tr>
                            </thead>
                            <tbody id="db-charging-table-body">
                                <!-- Nabíjecí lokality budou načteny JavaScriptem -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Batch edit formulář -->
                <div class="db-batch-edit" id="db-batch-edit" style="display: none;">
                    <h3>Hromadná úprava vybraných nabíjecích lokalit</h3>
                    <form id="db-batch-edit-form">
                        <div class="db-batch-fields">
                            <div class="db-field-group">
                                <label>Typ nabíječky:</label>
                                <select name="batch_charger_type">
                                    <option value="">Nezměňovat</option>
                                    <?php foreach ($charger_types as $type): ?>
                                        <option value="<?php echo esc_attr($type->term_id); ?>">
                                            <?php echo esc_html($type->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="db-field-group">
                                <label>Poskytovatel:</label>
                                <select name="batch_provider">
                                    <option value="">Nezměňovat</option>
                                    <?php foreach ($providers as $provider): ?>
                                        <option value="<?php echo esc_attr($provider->term_id); ?>">
                                            <?php echo esc_html($provider->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="db-field-group">
                                <label>Ikona konektoru:</label>
                                <input type="text" name="batch_icon" placeholder="Název SVG souboru nebo URL">
                                <p class="description">Název souboru z assets/icons/ nebo URL k ikoně</p>
                            </div>

                            <div class="db-field-group">
                                <label>Výkon (kW):</label>
                                <input type="number" name="batch_power" placeholder="např. 22" min="0" max="1000" step="0.1">
                                <p class="description">Výkon nabíjecího bodu v kilowattech</p>
                            </div>

                            <div class="db-field-group">
                                <label>Stav nabíjecího bodu:</label>
                                <select name="batch_status">
                                    <option value="">Nezměňovat</option>
                                    <option value="functional">Funkční</option>
                                    <option value="out_of_order">Mimo provoz</option>
                                    <option value="maintenance">Údržba</option>
                                    <option value="unknown">Neznámý</option>
                                </select>
                            </div>

                            <div class="db-field-group">
                                <label>Dostupnost:</label>
                                <select name="batch_availability">
                                    <option value="">Nezměňovat</option>
                                    <option value="24_7">24/7</option>
                                    <option value="business_hours">Pracovní doba</option>
                                    <option value="restricted">Omezená</option>
                                    <option value="private">Soukromá</option>
                                </select>
                            </div>

                            <div class="db-field-group">
                                <label>Cena za kWh (Kč):</label>
                                <input type="number" name="batch_price" placeholder="např. 8.5" min="0" max="100" step="0.1">
                                <p class="description">Cena za kilowatthodinu v českých korunách</p>
                            </div>
                        </div>

                        <div class="db-batch-actions">
                            <button type="submit" class="button button-primary">
                                Aktualizovat vybrané nabíjecí lokality
                            </button>
                            <button type="button" id="bulk-delete-btn" class="button button-secondary">
                                Smazat vybrané nabíjecí lokality
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Import/Export -->
            <div class="db-import-export">
                <h2>📁 Import/Export</h2>
                
                <div class="db-export-section">
                    <h3>Export nabíjecích lokalit do CSV</h3>
                    <p>Exportujte všechny nabíjecí lokality nebo vybrané podle filtrů</p>
                    <button type="button" id="export-csv-btn" class="button">
                        Exportovat do CSV
                    </button>
                </div>

                <div class="db-import-section">
                    <h3>Import nabíjecích lokalit z CSV</h3>
                    <p>Importujte nabíjecí lokality z CSV souboru (přepíše existující)</p>
                    <form id="db-import-form" enctype="multipart/form-data">
                        <input type="file" name="charging_csv" accept=".csv" required>
                        <button type="submit" class="button button-primary">
                            Importovat CSV
                        </button>
                    </form>
                </div>
            </div>

            <!-- Hromadná aktualizace ikon -->
            <div class="db-icon-update">
                <h2>🎨 Hromadná aktualizace ikon konektorů</h2>
                <p>Automaticky aktualizujte ikony pro všechny nabíjecí lokality podle typu konektoru</p>
                
                <div class="db-icon-mapping">
                    <h3>Mapování typů konektorů na ikony</h3>
                    <div id="db-icon-mapping-list">
                        <?php foreach ($charger_types as $type): ?>
                            <div class="db-icon-mapping-item">
                                <label><?php echo esc_html($type->name); ?>:</label>
                                <input type="text" 
                                       name="icon_<?php echo esc_attr($type->slug); ?>" 
                                       value="<?php echo esc_attr(get_term_meta($type->term_id, 'charger_icon', true)); ?>"
                                       placeholder="Název SVG souboru (např. charger_type-11.svg)">
                                <button type="button" class="button button-small update-type-icon" 
                                        data-type="<?php echo esc_attr($type->term_id); ?>">
                                    Aktualizovat
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="button" id="update-all-icons-btn" class="button button-primary">
                        Aktualizovat všechny ikony konektorů
                    </button>
                </div>
            </div>

            <!-- Rychlé akce -->
            <div class="db-quick-actions">
                <h2>⚡ Rychlé akce</h2>
                <div class="db-quick-actions-grid">
                    <button type="button" id="fix-missing-coords" class="button">
                        Opravit chybějící koordináty
                    </button>
                    <button type="button" id="normalize-power-values" class="button">
                        Normalizovat hodnoty výkonu
                    </button>
                    <button type="button" id="update-ocm-data" class="button">
                        Aktualizovat OCM data
                    </button>
                    <button type="button" id="cleanup-duplicates" class="button">
                        Vyčistit duplicity
                    </button>
                </div>
            </div>
        </div>

        <style>
        .db-stats-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 20px;
            margin: 20px 0;
        }
        .db-stat-item {
            background: #fff;
            padding: 20px;
            border: 1px solid #ddd;
            border-radius: 5px;
            text-align: center;
        }
        .db-stat-item h3 {
            font-size: 2em;
            margin: 0 0 10px 0;
            color: #007cba;
        }
        .db-filter-row {
            display: flex;
            gap: 15px;
            align-items: center;
            margin: 15px 0;
            flex-wrap: wrap;
        }
        .db-filter-row label {
            font-weight: bold;
            min-width: 120px;
        }
        .db-field-group {
            margin: 15px 0;
        }
        .db-field-group label {
            display: block;
            font-weight: bold;
            margin-bottom: 5px;
        }
        .db-icon-mapping-item {
            display: flex;
            align-items: center;
            gap: 10px;
            margin: 10px 0;
            padding: 10px;
            background: #f9f9f9;
            border-radius: 3px;
        }
        .db-icon-mapping-item label {
            min-width: 200px;
            font-weight: bold;
        }
        .db-icon-mapping-item input {
            flex: 1;
        }
        .db-quick-actions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }
        .db-quick-actions-grid button {
            padding: 15px;
            font-weight: bold;
        }
        .db-charging-table-container {
            max-height: 600px;
            overflow-y: auto;
            border: 1px solid #ddd;
        }
        </style>
        <?php
    }

    private function count_locations_with_coordinates(): int {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'charging_location' 
            AND p.post_status = 'publish'
            AND pm.meta_key IN ('_db_lat', '_db_lng')
            AND pm.meta_value != ''
        ");
        return (int) $count;
    }

    private function count_locations_with_icons(): int {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'charging_location' 
            AND p.post_status = 'publish'
            AND pm.meta_key = '_db_charger_icon'
            AND pm.meta_value != ''
        ");
        return (int) $count;
    }

    public function handle_load_charging_by_filters(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $filters = $_POST['filters'] ?? [];
        
        $args = [
            'post_type' => 'charging_location',
            'post_status' => 'publish',
            'posts_per_page' => intval($filters['limit'] ?? 100),
        ];

        $tax_query = [];

        if (!empty($filters['charger_type'])) {
            $tax_query[] = [
                'taxonomy' => 'charger_type',
                'field' => 'slug',
                'terms' => $filters['charger_type']
            ];
        }

        if (!empty($filters['provider'])) {
            $tax_query[] = [
                'taxonomy' => 'provider',
                'field' => 'slug',
                'terms' => $filters['provider']
            ];
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $charging_posts = get_posts($args);
        $charging_data = [];

        foreach ($charging_posts as $charging) {
            $charger_type = wp_get_post_terms($charging->ID, 'charger_type', ['fields' => 'names']);
            $charger_type = !empty($charger_type) ? $charger_type[0] : '';
            
            $provider = wp_get_post_terms($charging->ID, 'provider', ['fields' => 'names']);
            $provider = !empty($provider) ? $provider[0] : '';
            
            $charging_data[] = [
                'id' => $charging->ID,
                'title' => $charging->post_title,
                'charger_type' => $charger_type,
                'provider' => $provider,
                'lat' => get_post_meta($charging->ID, '_db_lat', true),
                'lng' => get_post_meta($charging->ID, '_db_lng', true),
                'icon' => get_post_meta($charging->ID, '_db_charger_icon', true),
                'power' => get_post_meta($charging->ID, '_db_power_kw', true),
                'status' => get_post_meta($charging->ID, '_db_status', true),
                'availability' => get_post_meta($charging->ID, '_db_availability', true),
                'price' => get_post_meta($charging->ID, '_db_price_kwh', true),
                'url' => get_permalink($charging->ID)
            ];
        }

        // Filtrovat podle koordinátů
        if (!empty($filters['coords_status'])) {
            if ($filters['coords_status'] === 'with_coords') {
                $charging_data = array_filter($charging_data, function($location) {
                    return !empty($location['lat']) && !empty($location['lng']);
                });
            } elseif ($filters['coords_status'] === 'without_coords') {
                $charging_data = array_filter($charging_data, function($location) {
                    return empty($location['lat']) || empty($location['lng']);
                });
            }
        }

        // Filtrovat podle ikon
        if (!empty($filters['icon_status'])) {
            if ($filters['icon_status'] === 'with_icons') {
                $charging_data = array_filter($charging_data, function($location) {
                    return !empty($location['icon']);
                });
            } elseif ($filters['icon_status'] === 'without_icons') {
                $charging_data = array_filter($charging_data, function($location) {
                    return empty($location['icon']);
                });
            }
        }

        wp_send_json_success([
            'charging' => array_values($charging_data)
        ]);
    }

    public function handle_batch_update(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $charging_ids = sanitize_text_field($_POST['charging_ids']);
        $updates = $_POST['updates'];

        if (empty($charging_ids)) {
            wp_send_json_error('Nebyla vybrána žádná nabíjecí lokalita');
        }

        $charging_ids = explode(',', $charging_ids);
        $updated = 0;
        $errors = [];

        foreach ($charging_ids as $charging_id) {
            $charging_id = (int) $charging_id;
            
            try {
                if (!empty($updates['charger_type'])) {
                    wp_set_object_terms($charging_id, (int) $updates['charger_type'], 'charger_type');
                }

                if (!empty($updates['provider'])) {
                    wp_set_object_terms($charging_id, (int) $updates['provider'], 'provider');
                }

                if (!empty($updates['icon'])) {
                    update_post_meta($charging_id, '_db_charger_icon', sanitize_text_field($updates['icon']));
                }

                if (!empty($updates['power'])) {
                    update_post_meta($charging_id, '_db_power_kw', floatval($updates['power']));
                }

                if (!empty($updates['status'])) {
                    update_post_meta($charging_id, '_db_status', sanitize_text_field($updates['status']));
                }

                if (!empty($updates['availability'])) {
                    update_post_meta($charging_id, '_db_availability', sanitize_text_field($updates['availability']));
                }

                if (!empty($updates['price'])) {
                    update_post_meta($charging_id, '_db_price_kwh', floatval($updates['price']));
                }

                $updated++;
            } catch (Exception $e) {
                $errors[] = "Nabíjecí lokalita ID {$charging_id}: " . $e->getMessage();
            }
        }

        wp_send_json_success([
            'message' => "Úspěšně aktualizováno {$updated} nabíjecích lokalit",
            'errors' => $errors
        ]);
    }

    public function handle_bulk_delete(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $charging_ids = sanitize_text_field($_POST['charging_ids']);
        
        if (empty($charging_ids)) {
            wp_send_json_error('Nebyla vybrána žádná nabíjecí lokalita');
        }

        $charging_ids = explode(',', $charging_ids);
        $deleted = 0;

        foreach ($charging_ids as $charging_id) {
            $charging_id = (int) $charging_id;
            if (wp_delete_post($charging_id, true)) {
                $deleted++;
            }
        }

        wp_send_json_success([
            'message' => "Úspěšně smazáno {$deleted} nabíjecích lokalit"
        ]);
    }

    public function handle_export_csv(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $filters = $_POST['filters'] ?? [];
        
        $args = [
            'post_type' => 'charging_location',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ];

        $tax_query = [];

        if (!empty($filters['charger_type'])) {
            $tax_query[] = [
                'taxonomy' => 'charger_type',
                'field' => 'slug',
                'terms' => $filters['charger_type']
            ];
        }

        if (!empty($filters['provider'])) {
            $tax_query[] = [
                'taxonomy' => 'provider',
                'field' => 'slug',
                'terms' => $filters['provider']
            ];
        }

        if (!empty($tax_query)) {
            $args['tax_query'] = $tax_query;
        }

        $charging_posts = get_posts($args);
        
        $filename = 'charging_locations_export_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // CSV header
        fputcsv($output, [
            'ID', 'Název', 'Typ nabíječky', 'Poskytovatel', 'Latitude', 'Longitude', 
            'Ikona', 'Výkon (kW)', 'Stav', 'Dostupnost', 'Cena/kWh', 'Popis'
        ]);
        
        foreach ($charging_posts as $charging) {
            $charger_type = wp_get_post_terms($charging->ID, 'charger_type', ['fields' => 'names']);
            $charger_type = !empty($charger_type) ? $charger_type[0] : '';
            
            $provider = wp_get_post_terms($charging->ID, 'provider', ['fields' => 'names']);
            $provider = !empty($provider) ? $provider[0] : '';
            
            fputcsv($output, [
                $charging->ID,
                $charging->post_title,
                $charger_type,
                $provider,
                get_post_meta($charging->ID, '_db_lat', true),
                get_post_meta($charging->ID, '_db_lng', true),
                get_post_meta($charging->ID, '_db_charger_icon', true),
                get_post_meta($charging->ID, '_db_power_kw', true),
                get_post_meta($charging->ID, '_db_status', true),
                get_post_meta($charging->ID, '_db_availability', true),
                get_post_meta($charging->ID, '_db_price_kwh', true),
                $charging->post_content
            ]);
        }
        
        fclose($output);
        exit;
    }

    public function handle_import_csv(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nedostatečná oprávnění');
        }

        if (!isset($_FILES['charging_csv'])) {
            wp_send_json_error('Nebyl nahrán žádný soubor');
        }

        $file = $_FILES['charging_csv'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Chyba při nahrávání souboru');
        }

        // Debug info
        error_log('[Charging Import] Soubor nahrán: ' . $file['name'] . ', velikost: ' . $file['size'] . ' bytes');
        error_log('[Charging Import] MIME typ: ' . $file['type']);

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error('Nelze otevřít CSV soubor');
        }

        $headers = fgetcsv($handle);
        error_log('[Charging Import] CSV hlavičky: ' . print_r($headers, true));
        
        if (empty($headers)) {
            fclose($handle);
            wp_send_json_error('CSV soubor je prázdný nebo neplatný');
        }

        $imported = 0;
        $errors = [];
        $row_count = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $row_count++;
            error_log("[Charging Import] Řádek {$row_count}: " . print_r($data, true));
            
            if (count($data) < 3) {
                $errors[] = "Řádek {$row_count}: Nedostatečný počet sloupců (" . count($data) . ")";
                continue;
            }

            try {
                $charging_data = array_combine($headers, $data);
                
                $post_data = [
                    'post_title' => sanitize_text_field($charging_data['Název'] ?? ''),
                    'post_content' => sanitize_textarea_field($charging_data['Popis'] ?? ''),
                    'post_type' => 'charging_location',
                    'post_status' => 'publish'
                ];

                if (empty($post_data['post_title'])) continue;

                $charging_id = wp_insert_post($post_data);
                
                if (!is_wp_error($charging_id)) {
                    // Typ nabíječky
                    if (!empty($charging_data['Typ nabíječky'])) {
                        $term = term_exists($charging_data['Typ nabíječky'], 'charger_type');
                        if (!$term) {
                            $term = wp_insert_term($charging_data['Typ nabíječky'], 'charger_type');
                        }
                        if (!is_wp_error($term)) {
                            wp_set_object_terms($charging_id, $term['term_id'], 'charger_type');
                        }
                    }

                    // Poskytovatel
                    if (!empty($charging_data['Poskytovatel'])) {
                        $term = term_exists($charging_data['Poskytovatel'], 'provider');
                        if (!$term) {
                            $term = wp_insert_term($charging_data['Poskytovatel'], 'provider');
                        }
                        if (!is_wp_error($term)) {
                            wp_set_object_terms($charging_id, $term['term_id'], 'provider');
                        }
                    }

                    // Meta data
                    $meta_fields = [
                        'Latitude' => '_db_lat',
                        'Longitude' => '_db_lng',
                        'Ikona' => '_db_charger_icon',
                        'Výkon (kW)' => '_db_power_kw',
                        'Stav' => '_db_status',
                        'Dostupnost' => '_db_availability',
                        'Cena/kWh' => '_db_price_kwh'
                    ];

                    foreach ($meta_fields as $csv_field => $meta_key) {
                        if (!empty($charging_data[$csv_field])) {
                            $value = sanitize_text_field($charging_data[$csv_field]);
                            if (in_array($meta_key, ['_db_power_kw', '_db_price_kwh'])) {
                                $value = floatval($value);
                            }
                            update_post_meta($charging_id, $meta_key, $value);
                        }
                    }

                    $imported++;
                }
            } catch (Exception $e) {
                $errors[] = "Řádek " . ($imported + 1) . ": " . $e->getMessage();
            }
        }

        fclose($handle);

        error_log("[Charging Import] Import dokončen. Importováno: {$imported}, Chyby: " . count($errors));

        wp_send_json_success([
            'message' => "Úspěšně importováno {$imported} nabíjecích lokalit z {$row_count} řádků",
            'imported' => $imported,
            'total_rows' => $row_count,
            'errors' => $errors
        ]);
    }

    public function handle_update_type_icon(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $type_id = (int) $_POST['type_id'];
        $icon = sanitize_text_field($_POST['icon']);

        if (!$type_id || !$icon) {
            wp_send_json_error('Chybí povinné parametry');
        }

        update_term_meta($type_id, 'charger_icon', $icon);
        
        wp_send_json_success([
            'message' => 'Ikona typu aktualizována'
        ]);
    }

    public function handle_update_all_icons(): void {
        check_ajax_referer('db_charging_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $charger_types = get_terms([
            'taxonomy' => 'charger_type',
            'hide_empty' => false,
        ]);

        if (is_wp_error($charger_types)) {
            wp_send_json_error('Chyba při načítání typů nabíječek');
        }

        $updated = 0;
        $errors = [];

        foreach ($charger_types as $type) {
            $icon = get_term_meta($type->term_id, 'charger_icon', true);
            if (empty($icon)) continue;

            // Najít všechny nabíjecí lokality tohoto typu
            $charging_posts = get_posts([
                'post_type' => 'charging_location',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'tax_query' => [
                    [
                        'taxonomy' => 'charger_type',
                        'field' => 'term_id',
                        'terms' => $type->term_id
                    ]
                ]
            ]);

            foreach ($charging_posts as $charging) {
                try {
                    update_post_meta($charging->ID, '_db_charger_icon', $icon);
                    $updated++;
                } catch (Exception $e) {
                    $errors[] = "Nabíjecí lokalita ID {$charging->ID}: " . $e->getMessage();
                }
            }
        }

        wp_send_json_success([
            'message' => "Úspěšně aktualizováno {$updated} nabíjecích lokalit",
            'errors' => $errors
        ]);
    }
}
