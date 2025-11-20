<?php
/**
 * Pokročilé admin rozhraní pro Body zájmu (POI)
 * @package DobityBaterky
 */

namespace DB;

/**
 * Normalizace hodnoty typu z CSV na název termu v taxonomii poi_type.
 * - Podpora hlaviček "Typ" i "Type"
 * - Odstranění whitespace, lowercasing, aliasy
 * - Ignorování čistě číselných hodnot => fallback
 * - Nikdy nevrací číslo jako název termu
 */
function db_normalize_poi_type_from_csv(array $poi_data, string $fallback = 'kavárna'): string {
    $raw = null;
    if (isset($poi_data['Typ']))   $raw = $poi_data['Typ'];
    if (isset($poi_data['Type']))  $raw = $poi_data['Type']; // preferuj "Type" pokud existuje

    $raw = isset($raw) ? trim((string)$raw) : '';
    if ($raw === '') {
        return $fallback;
    }
    if (is_numeric($raw)) {
        return $fallback;
    }
    $raw = trim($raw, " \t\n\r\0\x0B\"'");

    $aliases = [
        'cafe'        => 'kavárna',
        'coffee'      => 'kavárna',
        'café'        => 'kavárna',
        'kavarna'     => 'kavárna',
        'kavárna'     => 'kavárna',
        'restaurace'  => 'restaurace',
        'restaurant'  => 'restaurace',
        'bar'         => 'bar',
        'hotel'       => 'hotel',
        'camp'        => 'kemp',
        'kemp'        => 'kemp',
        'supermarket' => 'supermarket',
        'obchod'      => 'obchod',
        'pekárna'     => 'pekárna',
        'pekarna'     => 'pekárna',
        'parkoviště'  => 'parkoviště',
        'parkoviste'  => 'parkoviště',
    ];

    $normalized = mb_strtolower($raw);
    $normalized = preg_split('/[\(\)\-\|,;\/]+/u', $normalized, 2)[0];
    $normalized = trim($normalized);

    if ($normalized === '' || is_numeric($normalized)) {
        return $fallback;
    }
    if (isset($aliases[$normalized])) {
        return $aliases[$normalized];
    }
    return $normalized;
}

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Helper funkce pro kontrolu, zda právě probíhá import POI z CSV
 */
function db_is_poi_import_running(): bool {
    return (bool)get_transient('db_poi_import_running');
}

/**
 * Nastavit flag, že právě probíhá import POI z CSV
 */
function db_set_poi_import_running(bool $running): void {
    if ($running) {
        set_transient('db_poi_import_running', true, 300); // 5 minut timeout
    } else {
        delete_transient('db_poi_import_running');
    }
}

class POI_Admin {
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
        add_action('wp_ajax_db_load_poi_by_filters', [$this, 'handle_load_poi_by_filters']);
        add_action('wp_ajax_db_batch_update_poi', [$this, 'handle_batch_update']);
        add_action('wp_ajax_db_bulk_delete_poi', [$this, 'handle_bulk_delete']);
        add_action('wp_ajax_db_export_poi_csv', [$this, 'handle_export_csv']);
        add_action('wp_ajax_db_import_poi_csv', [$this, 'handle_import_csv']);
        add_action('wp_ajax_db_import_poi_csv_chunk', [$this, 'handle_import_csv_chunk']);
        add_action('wp_ajax_db_update_poi_type_icon', [$this, 'handle_update_type_icon']);
        add_action('wp_ajax_db_update_all_poi_icons', [$this, 'handle_update_all_icons']);
    }

    public function add_admin_menu(): void {
        add_submenu_page(
            'edit.php?post_type=poi',
            'Pokročilá správa POI',
            'Pokročilá správa',
            'manage_options',
            'db-poi-admin',
            [$this, 'admin_page']
        );
    }

    public function enqueue_admin_scripts(string $hook): void {
        if ($hook !== 'poi_page_db-poi-admin') {
            return;
        }

        wp_enqueue_style(
            'db-poi-admin',
            DB_PLUGIN_URL . 'assets/admin.css',
            [],
            DB_PLUGIN_VERSION
        );

        wp_enqueue_script(
            'db-poi-admin',
            DB_PLUGIN_URL . 'assets/poi-admin.js',
            ['jquery'],
            DB_PLUGIN_VERSION,
            true
        );

        wp_localize_script('db-poi-admin', 'dbPoiAdmin', [
            'ajaxUrl' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('db_poi_admin_nonce'),
            'strings' => [
                'confirmDelete' => 'Opravdu chcete smazat vybrané POI?',
                'confirmUpdate' => 'Opravdu chcete aktualizovat vybrané POI?',
                'updating' => 'Aktualizuji...',
                'deleting' => 'Mažu...',
                'success' => 'Operace dokončena úspěšně!',
                'error' => 'Chyba: ',
                'selectItems' => 'Vyberte alespoň jeden záznam'
            ]
        ]);
    }

    public function admin_page(): void {
        $poi_types = get_terms([
            'taxonomy' => 'poi_type',
            'hide_empty' => false,
        ]);

        if (is_wp_error($poi_types)) {
            $poi_types = [];
        }

        $total_poi = wp_count_posts('poi')->publish;
        $poi_with_coords = $this->count_poi_with_coordinates();
        $poi_with_icons = $this->count_poi_with_icons();

        ?>
        <div class="wrap">
            <h1>Pokročilá správa Body zájmu (POI)</h1>

            <!-- Statistiky -->
            <div class="db-poi-stats">
                <h2>📊 Přehled</h2>
                <div class="db-stats-grid">
                    <div class="db-stat-item">
                        <h3><?php echo $total_poi; ?></h3>
                        <p>Celkem POI</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo $poi_with_coords; ?></h3>
                        <p>S koordináty</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo $poi_with_icons; ?></h3>
                        <p>S ikonami</p>
                    </div>
                    <div class="db-stat-item">
                        <h3><?php echo count($poi_types); ?></h3>
                        <p>Typů POI</p>
                    </div>
                </div>
            </div>

            <!-- Batch operace -->
            <div class="db-batch-operations">
                <h2>🔄 Hromadné operace</h2>

                <!-- Filtry -->
                <div class="db-filters">
                    <h3>Filtry pro výběr POI</h3>
                    <form id="db-poi-filters">
                        <div class="db-filter-row">
                            <label>Typ POI:</label>
                            <select name="poi_type" id="poi_type_filter">
                                <option value="">Všechny typy</option>
                                <?php foreach ($poi_types as $type): ?>
                                    <option value="<?php echo esc_attr($type->slug); ?>">
                                        <?php echo esc_html($type->name); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <label>Stav koordinát:</label>
                            <select name="coords_status" id="coords_status_filter">
                                <option value="">Všechny</option>
                                <option value="with_coords">S koordináty</option>
                                <option value="without_coords">Bez koordinát</option>
                            </select>

                            <label>Limit:</label>
                            <input type="number" name="limit" id="limit_filter" value="100" min="1" max="1000">
                        </div>

                        <button type="button" id="load-poi-btn" class="button button-primary">
                            Načíst POI podle filtrů
                        </button>
                    </form>
                </div>

                <!-- Seznam POI -->
                <div class="db-poi-list" id="db-poi-list" style="display: none;">
                    <h3>Vybrané POI pro úpravu</h3>
                    <div class="db-poi-table-container">
                        <table class="wp-list-table widefat fixed striped">
                            <thead>
                                <tr>
                                    <th><input type="checkbox" id="select-all-poi"></th>
                                    <th>Název</th>
                                    <th>Typ</th>
                                    <th>DB doporučuje</th>
                                    <th>Koordináty</th>
                                    <th>Ikona</th>
                                    <th>Akce</th>
                                </tr>
                            </thead>
                            <tbody id="db-poi-table-body">
                                <!-- POI budou načtena JavaScriptem -->
                            </tbody>
                        </table>
                    </div>
                </div>

                <!-- Batch edit formulář -->
                <div class="db-batch-edit" id="db-batch-edit" style="display: none;">
                    <h3>Hromadná úprava vybraných POI</h3>
                    <form id="db-batch-edit-form">
                        <div class="db-batch-fields">
                            <div class="db-field-group">
                                <label>Typ POI:</label>
                                <select name="batch_poi_type">
                                    <option value="">Nezměňovat</option>
                                    <?php foreach ($poi_types as $type): ?>
                                        <option value="<?php echo esc_attr($type->term_id); ?>">
                                            <?php echo esc_html($type->name); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="db-field-group">
                                <label>Ikona:</label>
                                <input type="text" name="batch_icon" placeholder="Název SVG souboru nebo URL">
                                <p class="description">Název souboru z assets/icons/ nebo URL k ikoně</p>
                            </div>

                            <div class="db-field-group">
                                <label>Barva:</label>
                                <input type="color" name="batch_color" value="#007cba">
                                <p class="description">Barva pro zobrazení na mapě</p>
                            </div>

                            <div class="db-field-group">
                                <label>DB doporučuje:</label>
                                <select name="batch_recommended">
                                    <option value="">Nezměňovat</option>
                                    <option value="1">Zapnout</option>
                                    <option value="0">Vypnout</option>
                                </select>
                                <p class="description">Označí podnik jako doporučený DB</p>
                            </div>
                        </div>

                        <div class="db-batch-actions">
                            <button type="submit" class="button button-primary">
                                Aktualizovat vybrané POI
                            </button>
                            <button type="button" id="bulk-delete-btn" class="button button-secondary">
                                Smazat vybrané POI
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            <!-- Import/Export -->
            <div class="db-import-export">
                <h2>📁 Import/Export</h2>
                
                <div class="db-export-section">
                    <h3>Export POI do CSV</h3>
                    <p>Exportujte všechna POI nebo vybraná podle filtrů</p>
                    <button type="button" id="export-csv-btn" class="button">
                        Exportovat do CSV
                    </button>
                </div>

                <div class="db-import-section">
                    <h3>Import POI z CSV</h3>
                    <p>Importujte POI z CSV souboru (přepíše existující)</p>
                    <form id="db-import-form" enctype="multipart/form-data">
                        <input type="file" name="poi_csv" accept=".csv" required>
                        <button type="submit" class="button button-primary">
                            Importovat CSV
                        </button>
                    </form>
                    <div id="db-import-log-section" style="display: none; margin-top: 20px;">
                        <h4>Průběh importu a logy:</h4>
                        <div id="db-import-progress-container" style="margin-bottom: 15px; display: none;">
                            <div style="display: flex; justify-content: space-between; margin-bottom: 5px;">
                                <span id="db-import-progress-text">Zpracovává se...</span>
                                <span id="db-import-progress-percent">0%</span>
                            </div>
                            <div style="width: 100%; height: 25px; background: #f0f0f0; border: 1px solid #ddd; border-radius: 3px; overflow: hidden;">
                                <div id="db-import-progress-bar" style="width: 0%; height: 100%; background: #0073aa; transition: width 0.3s ease;"></div>
                            </div>
                            <div id="db-import-time-estimate" style="margin-top: 5px; font-size: 12px; color: #666;"></div>
                        </div>
                        <textarea id="db-import-log" readonly style="width: 100%; height: 400px; font-family: monospace; font-size: 12px; padding: 10px; background: #f5f5f5; border: 1px solid #ddd; overflow-y: auto;"></textarea>
                        <button type="button" id="db-copy-log-btn" class="button button-secondary" style="margin-top: 10px;">
                            Zkopírovat logy
                        </button>
                        <button type="button" id="db-clear-log-btn" class="button button-secondary" style="margin-top: 10px;">
                            Vymazat logy
                        </button>
                    </div>
                </div>
            </div>

            <!-- Hromadná aktualizace ikon -->
            <div class="db-icon-update">
                <h2>🎨 Hromadná aktualizace ikon</h2>
                <p>Automaticky aktualizujte ikony pro všechny POI podle typu</p>
                
                <div class="db-icon-mapping">
                    <h3>Mapování typů na ikony</h3>
                    <div id="db-icon-mapping-list">
                        <?php foreach ($poi_types as $type): ?>
                            <div class="db-icon-mapping-item">
                                <label><?php echo esc_html($type->name); ?>:</label>
                                <input type="text" 
                                       name="icon_<?php echo esc_attr($type->slug); ?>" 
                                       value="<?php echo esc_attr(get_term_meta($type->term_id, 'poi_icon', true)); ?>"
                                       placeholder="Název SVG souboru">
                                <button type="button" class="button button-small update-type-icon" 
                                        data-type="<?php echo esc_attr($type->term_id); ?>">
                                    Aktualizovat
                                </button>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    
                    <button type="button" id="update-all-icons-btn" class="button button-primary">
                        Aktualizovat všechny ikony
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
            min-width: 100px;
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
            min-width: 150px;
            font-weight: bold;
        }
        .db-icon-mapping-item input {
            flex: 1;
        }
        </style>
        <?php
    }

    private function count_poi_with_coordinates(): int {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'poi' 
            AND p.post_status = 'publish'
            AND pm.meta_key IN ('_poi_lat', '_poi_lng')
            AND pm.meta_value != ''
        ");
        return (int) $count;
    }

    private function count_poi_with_icons(): int {
        global $wpdb;
        $count = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} pm
            JOIN {$wpdb->posts} p ON p.ID = pm.post_id
            WHERE p.post_type = 'poi' 
            AND p.post_status = 'publish'
            AND pm.meta_key = '_poi_icon'
            AND pm.meta_value != ''
        ");
        return (int) $count;
    }

    public function handle_batch_update(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $poi_ids = sanitize_text_field($_POST['poi_ids']);
        $updates = $_POST['updates'];

        if (empty($poi_ids)) {
            wp_send_json_error('Nebyla vybrána žádná POI');
        }

        $poi_ids = explode(',', $poi_ids);
        $updated = 0;
        $errors = [];

        foreach ($poi_ids as $poi_id) {
            $poi_id = (int) $poi_id;
            
            try {
                if (!empty($updates['poi_type'])) {
                    wp_set_object_terms($poi_id, (int) $updates['poi_type'], 'poi_type');
                }

                if (!empty($updates['icon'])) {
                    update_post_meta($poi_id, '_poi_icon', sanitize_text_field($updates['icon']));
                }

                if (!empty($updates['color'])) {
                    update_post_meta($poi_id, '_poi_color', sanitize_hex_color($updates['color']));
                }

                if (isset($updates['recommended']) && $updates['recommended'] !== '') {
                    $flag = (int)$updates['recommended'] ? '1' : '0';
                    update_post_meta($poi_id, '_db_recommended', $flag);
                }

                $updated++;
            } catch (Exception $e) {
                $errors[] = "POI ID {$poi_id}: " . $e->getMessage();
            }
        }

        wp_send_json_success([
            'message' => "Úspěšně aktualizováno {$updated} POI",
            'errors' => $errors
        ]);
    }

    public function handle_bulk_delete(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $poi_ids = sanitize_text_field($_POST['poi_ids']);
        
        if (empty($poi_ids)) {
            wp_send_json_error('Nebyla vybrána žádná POI');
        }

        $poi_ids = explode(',', $poi_ids);
        $deleted = 0;

        foreach ($poi_ids as $poi_id) {
            $poi_id = (int) $poi_id;
            if (wp_delete_post($poi_id, true)) {
                $deleted++;
            }
        }

        wp_send_json_success([
            'message' => "Úspěšně smazáno {$deleted} POI"
        ]);
    }

    public function handle_export_csv(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $filters = $_POST['filters'] ?? [];
        
        $args = [
            'post_type' => 'poi',
            'post_status' => 'publish',
            'posts_per_page' => -1,
        ];

        if (!empty($filters['poi_type'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'poi_type',
                    'field' => 'slug',
                    'terms' => $filters['poi_type']
                ]
            ];
        }

        $poi_posts = get_posts($args);
        
        $filename = 'poi_export_' . date('Y-m-d_H-i-s') . '.csv';
        
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        
        $output = fopen('php://output', 'w');
        
        // CSV header
        fputcsv($output, [
            'ID', 'Název', 'Typ', 'Latitude', 'Longitude', 'Ikona', 'Barva', 'Popis'
        ]);
        
        foreach ($poi_posts as $poi) {
            $poi_type = wp_get_post_terms($poi->ID, 'poi_type', ['fields' => 'names']);
            $poi_type = !empty($poi_type) ? $poi_type[0] : '';
            
            fputcsv($output, [
                $poi->ID,
                $poi->post_title,
                $poi_type,
                get_post_meta($poi->ID, '_poi_lat', true),
                get_post_meta($poi->ID, '_poi_lng', true),
                get_post_meta($poi->ID, '_poi_icon', true),
                get_post_meta($poi->ID, '_poi_color', true),
                $poi->post_content
            ]);
        }
        
        fclose($output);
        exit;
    }

    private function read_csv_headers($handle): array {
        while (($row = fgetcsv($handle)) !== false) {
            if ($this->is_csv_row_empty($row)) {
                continue;
            }
            if (isset($row[0])) {
                $row[0] = preg_replace('/^\xEF\xBB\xBF/', '', (string)$row[0]);
            }
            return $row;
        }
        return [];
    }

    private function is_csv_row_empty(array $row): bool {
        foreach ($row as $value) {
            if (trim((string)$value) !== '') {
                return false;
            }
        }
        return true;
    }

    public function import_from_stream($handle, array $context = []): array {
        if (!is_resource($handle)) {
            throw new \InvalidArgumentException('Neplatný zdroj CSV.');
        }

        if (function_exists('set_time_limit')) {
            @set_time_limit(0);
        }

        $logCallback = $context['log_callback'] ?? null;
        $logEvery = isset($context['log_every']) ? max(1, (int)$context['log_every']) : 500;
        $maxRows = isset($context['max_rows']) ? max(0, (int)$context['max_rows']) : 0;

        $headers = $this->read_csv_headers($handle);
        if (empty($headers)) {
            throw new \RuntimeException('CSV soubor je prázdný nebo neobsahuje hlavičku.');
        }

        $normalize = function(string $s): string {
            $s = trim(mb_strtolower($s));
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            if ($trans !== false && $trans !== null) {
                $s = strtolower(preg_replace('/[^a-z0-9_\- ]+/','',$trans));
            }
            $s = str_replace(['\t'], ' ', $s);
            $s = preg_replace('/\s+/', ' ', $s);
            return trim($s);
        };

        $synonymToInternal = [
            'nazev' => 'Název',
            'name' => 'Název',
            'cafe name' => 'Název',
            'title' => 'Název',
            'popis' => 'Popis',
            'description' => 'Popis',
            'address' => 'Popis',
            'typ' => 'Typ',
            'type' => 'Typ',
            'latitude' => 'Latitude',
            'lat' => 'Latitude',
            'y' => 'Latitude',
            'longitude' => 'Longitude',
            'lng' => 'Longitude',
            'lon' => 'Longitude',
            'x' => 'Longitude',
            'ikona' => 'Ikona',
            'icon' => 'Ikona',
            'barva' => 'Barva',
            'color' => 'Barva',
            'id' => 'ID',
        ];

        $columnIndexToInternal = [];
        foreach ($headers as $idx => $rawHeader) {
            $key = $normalize((string)$rawHeader);
            if (isset($synonymToInternal[$key])) {
                $columnIndexToInternal[$idx] = $synonymToInternal[$key];
            } else {
                $columnIndexToInternal[$idx] = (string)$rawHeader;
            }
        }

        $imported = 0;
        $updated = 0;
        $errors = [];
        $row_count = 0;
        $raw_rows = 0;
        $skipped_empty = 0;
        $processed_poi_ids = []; // ID všech POI, které byly vytvořeny nebo aktualizovány

        global $wpdb;

        while (($data = fgetcsv($handle)) !== false) {
            $raw_rows++;

            if ($this->is_csv_row_empty($data)) {
                $skipped_empty++;
                continue;
            }

            $row_count++;
            error_log("[POI Import] Řádek {$row_count}: " . print_r($data, true));

            if (count($data) < 2) {
                $errors[] = "Řádek {$row_count}: Nedostatečný počet sloupců (" . count($data) . ")";
                continue;
            }

            try {
                $poi_data = [];
                foreach ($data as $i => $val) {
                    $key = $columnIndexToInternal[$i] ?? ($headers[$i] ?? (string)$i);
                    $poi_data[$key] = $val;
                }
                error_log("[POI Import] Zpracovávám data: " . print_r($poi_data, true));
                if (isset($poi_data['Typ'])) {
                    error_log('[POI Import][DEBUG] Typ vstup: ' . print_r($poi_data['Typ'], true));
                }
                if (isset($poi_data['Název'])) {
                    error_log('[POI Import][DEBUG] Název vstup: ' . print_r($poi_data['Název'], true));
                }

                $post_title = sanitize_text_field($poi_data['Název'] ?? '');
                $post_content = sanitize_textarea_field($poi_data['Popis'] ?? '');

                if (empty($post_title)) {
                    $errors[] = "Řádek {$row_count}: Prázdný název POI";
                    continue;
                }

                $latInput = isset($poi_data['Latitude']) ? floatval($poi_data['Latitude']) : null;
                $lngInput = isset($poi_data['Longitude']) ? floatval($poi_data['Longitude']) : null;

                $poi_id = 0;
                $rowAborted = false;

                if (!empty($poi_data['ID']) && is_numeric($poi_data['ID'])) {
                    $candidate_id = (int)$poi_data['ID'];
                    $candidate_post = get_post($candidate_id);
                    if ($candidate_post && $candidate_post->post_type === 'poi') {
                        $update_post = [
                            'ID' => $candidate_id,
                            'post_title' => $post_title,
                            'post_content' => $post_content,
                        ];
                        $result = wp_update_post($update_post, true);
                        if (!is_wp_error($result)) {
                            $poi_id = $candidate_id;
                            $updated++;
                            error_log("[POI Import] Aktualizuji existující POI dle ID: {$candidate_id}");
                        } else {
                            $errors[] = "Řádek {$row_count}: Chyba při aktualizaci POI {$candidate_id}: " . $result->get_error_message();
                            continue;
                        }
                    }
                }

                if (!$poi_id && $latInput !== null && $lngInput !== null) {
                    $tolerance = 1e-5;
                    $candidates = $wpdb->get_col($wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'poi' AND post_status = 'publish' AND post_title = %s",
                        $post_title
                    ));
                    foreach ($candidates as $cid) {
                        $clat = get_post_meta((int)$cid, '_poi_lat', true);
                        $clng = get_post_meta((int)$cid, '_poi_lng', true);
                        if ($clat === '' || $clng === '') continue;
                        $clat = floatval($clat);
                        $clng = floatval($clng);
                        if (abs($clat - $latInput) <= $tolerance && abs($clng - $lngInput) <= $tolerance) {
                            $update_post = [
                                'ID' => (int)$cid,
                                'post_title' => $post_title,
                                'post_content' => $post_content,
                            ];
                            $result = wp_update_post($update_post, true);
                            if (!is_wp_error($result)) {
                                $poi_id = (int)$cid;
                                $updated++;
                                error_log("[POI Import] Aktualizuji existující POI dle Title+Coords: {$cid}");
                            } else {
                                $errors[] = "Řádek {$row_count}: Chyba při aktualizaci POI {$cid}: " . $result->get_error_message();
                                $rowAborted = true;
                                break;
                            }
                            break;
                        }
                    }
                    if ($rowAborted) {
                        continue;
                    }
                }

                if (!$poi_id) {
                    $candidates = $wpdb->get_col($wpdb->prepare(
                        "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'poi' AND post_status = 'publish' AND post_title = %s",
                        $post_title
                    ));
                    if (count($candidates) === 1) {
                        $cid = (int)$candidates[0];
                        $update_post = [
                            'ID' => $cid,
                            'post_title' => $post_title,
                            'post_content' => $post_content,
                        ];
                        $result = wp_update_post($update_post, true);
                        if (!is_wp_error($result)) {
                            $poi_id = $cid;
                            $updated++;
                            error_log("[POI Import] Aktualizuji existující POI dle Title-only: {$cid}");
                        } else {
                            $errors[] = "Řádek {$row_count}: Chyba při aktualizaci POI {$cid}: " . $result->get_error_message();
                            continue; // přeskočit celý řádek, aby se nevytvářely duplicitní POI
                        }
                    }
                }

                if (!$poi_id && $latInput !== null && $lngInput !== null) {
                    $tolerance = 1e-5;
                    $ids = $wpdb->get_col("SELECT ID FROM {$wpdb->posts} WHERE post_type = 'poi' AND post_status = 'publish' ORDER BY ID DESC LIMIT 1000");
                    $within = [];
                    foreach ($ids as $cid) {
                        $clat = get_post_meta((int)$cid, '_poi_lat', true);
                        $clng = get_post_meta((int)$cid, '_poi_lng', true);
                        if ($clat === '' || $clng === '') continue;
                        $clat = floatval($clat);
                        $clng = floatval($clng);
                        if (abs($clat - $latInput) <= $tolerance && abs($clng - $lngInput) <= $tolerance) {
                            $within[] = (int)$cid;
                        }
                    }
                    if (count($within) === 1) {
                        $cid = $within[0];
                        $update_post = [
                            'ID' => $cid,
                            'post_title' => $post_title,
                            'post_content' => $post_content,
                        ];
                        $result = wp_update_post($update_post, true);
                        if (!is_wp_error($result)) {
                            $poi_id = $cid;
                            $updated++;
                            error_log("[POI Import] Aktualizuji existující POI dle Coords-only: {$cid}");
                        } else {
                            $errors[] = "Řádek {$row_count}: Chyba při aktualizaci POI {$cid}: " . $result->get_error_message();
                            $rowAborted = true;
                        }
                    }
                    if ($rowAborted) {
                        continue;
                    }
                }

                if (!$poi_id) {
                    $post_data = [
                        'post_title' => $post_title,
                        'post_content' => $post_content,
                        'post_type' => 'poi',
                        'post_status' => 'publish'
                    ];
                    error_log("[POI Import] Vytvářím POI: " . $post_title);
                    $poi_id = wp_insert_post($post_data);
                    if (is_wp_error($poi_id)) {
                        $errors[] = "Řádek {$row_count}: Chyba při vytváření POI: " . $poi_id->get_error_message();
                        continue;
                    }
                    error_log("[POI Import] POI vytvořen s ID: {$poi_id}");
                    $imported++;
                }

                try {
                    $type_name = db_normalize_poi_type_from_csv($poi_data, 'kavárna');
                    if ($type_name !== '') {
                        if (is_numeric($type_name)) {
                            $type_name = 'kavárna';
                        }
                        $term = term_exists($type_name, 'poi_type');
                        if (!$term) {
                            $term = wp_insert_term($type_name, 'poi_type');
                        }
                        if (!is_wp_error($term)) {
                            $term_id = is_array($term) ? ($term['term_id'] ?? 0) : (int)$term;
                            if ($term_id) {
                                wp_set_object_terms($poi_id, (int)$term_id, 'poi_type', false);
                            }
                        }
                    }
                } catch (\Throwable $e) {
                    if (defined('WP_DEBUG') && WP_DEBUG) {
                        error_log('[DB POI import] Chyba při nastavování typu: ' . $e->getMessage());
                    }
                }

                if ($latInput !== null) {
                    $lat = $latInput;
                    if ($lat >= -90 && $lat <= 90) {
                        update_post_meta($poi_id, '_poi_lat', $lat);
                    } else {
                        $errors[] = "Řádek {$row_count}: Neplatná latitude: {$poi_data['Latitude']}";
                    }
                }
                if ($lngInput !== null) {
                    $lng = $lngInput;
                    if ($lng >= -180 && $lng <= 180) {
                        update_post_meta($poi_id, '_poi_lng', $lng);
                    } else {
                        $errors[] = "Řádek {$row_count}: Neplatná longitude: {$poi_data['Longitude']}";
                    }
                }

                if (!empty($poi_data['Ikona'])) {
                    update_post_meta($poi_id, '_poi_icon', sanitize_text_field($poi_data['Ikona']));
                }
                if (!empty($poi_data['Barva'])) {
                    $color = $poi_data['Barva'];
                    if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
                        update_post_meta($poi_id, '_poi_color', $color);
                    } else {
                        $errors[] = "Řádek {$row_count}: Neplatná hex barva: {$color}";
                    }
                }

                error_log("[POI Import] POI {$poi_id} úspěšně importován/aktualizován");
                
                // Přidat ID do seznamu pro pozdější nearby recompute
                if ($poi_id > 0 && !$rowAborted) {
                    $processed_poi_ids[] = $poi_id;
                }
            } catch (\Exception $e) {
                $errors[] = "Řádek {$row_count}: Exception: " . $e->getMessage();
                error_log("[POI Import] Exception v řádku {$row_count}: " . $e->getMessage());
            } catch (\Error $e) {
                $errors[] = "Řádek {$row_count}: Fatal Error: " . $e->getMessage();
                error_log("[POI Import] Fatal Error v řádku {$row_count}: " . $e->getMessage());
            }

            if ($logCallback && is_callable($logCallback) && ($row_count % $logEvery === 0)) {
                call_user_func($logCallback, [
                    'row' => $row_count,
                    'imported' => $imported,
                    'updated' => $updated,
                    'errors' => count($errors),
                    'skipped' => $skipped_empty,
                ]);
            }

            if ($maxRows > 0 && $row_count >= $maxRows) {
                break;
            }
        }

        return [
            'imported' => $imported,
            'updated' => $updated,
            'errors' => $errors,
            'total_rows' => $row_count,
            'skipped_rows' => $skipped_empty,
            'processed_poi_ids' => array_unique($processed_poi_ids), // Unikátní ID všech zpracovaných POI
        ];
    }

    public function handle_import_csv(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nedostatečná oprávnění');
        }

        if (!isset($_FILES['poi_csv'])) {
            wp_send_json_error('Nebyl nahrán žádný soubor');
        }

        $file = $_FILES['poi_csv'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            wp_send_json_error('Chyba při nahrávání souboru: ' . $this->get_upload_error_message($file['error']));
        }

        // Debug info
        error_log('[POI Import] Soubor nahrán: ' . $file['name'] . ', velikost: ' . $file['size'] . ' bytes');
        error_log('[POI Import] MIME typ: ' . $file['type']);

        $handle = fopen($file['tmp_name'], 'r');
        if (!$handle) {
            wp_send_json_error('Nelze otevřít CSV soubor');
        }

        // Nastavit flag, že probíhá import (zabrání spuštění nearby recompute)
        db_set_poi_import_running(true);
        $flagSet = true;

        try {
            $result = $this->import_from_stream($handle);
        } catch (\Throwable $e) {
            fclose($handle);
            // Vymazat flag před wp_send_json_error(), protože wp_send_json_error() ukončí vykonávání
            if ($flagSet) {
                db_set_poi_import_running(false);
            }
            wp_send_json_error($e->getMessage());
            return;
        } finally {
            // Vždy vymazat flag, i když došlo k chybě (pro případ, že by se výjimka zachytila jinak)
            if ($flagSet) {
                db_set_poi_import_running(false);
            }
        }

        fclose($handle);

        error_log("[POI Import] Import dokončen. Importováno: {$result['imported']}, Chyby: " . count($result['errors']));

        // Zařadit všechna importovaná/aktualizovaná POI do fronty pro nearby recompute
        $enqueued_count = 0;
        $affected_count = 0;
        if (!empty($result['processed_poi_ids']) && class_exists('\DB\Jobs\Nearby_Queue_Manager')) {
            $queue_manager = new \DB\Jobs\Nearby_Queue_Manager();
            foreach ($result['processed_poi_ids'] as $poi_id) {
                // POI potřebuje najít nearby charging locations
                if ($queue_manager->enqueue($poi_id, 'charging_location', 1)) {
                    $enqueued_count++;
                }
                // Zařadit charging locations v okruhu pro aktualizaci jejich nearby POI seznamů
                $affected_count += $queue_manager->enqueue_affected_points($poi_id, 'poi');
            }
            error_log("[POI Import] Zařazeno {$enqueued_count} POI do fronty pro nearby recompute, {$affected_count} affected charging locations");
        }

        wp_send_json_success([
            'message' => sprintf(
                'Úspěšně importováno %d POI (řádky: %d, aktualizováno: %d)',
                $result['imported'],
                $result['total_rows'],
                $result['updated']
            ),
            'imported' => $result['imported'],
            'updated' => $result['updated'],
            'total_rows' => $result['total_rows'],
            'skipped_rows' => $result['skipped_rows'],
            'errors' => $result['errors'],
            'processed_poi_ids' => $result['processed_poi_ids'] ?? [],
            'enqueued_count' => $enqueued_count,
            'affected_count' => $affected_count
        ]);
    }

    /**
     * Zpracovat jeden chunk CSV importu
     */
    public function handle_import_csv_chunk(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error('Nedostatečná oprávnění');
        }

        $chunk_data = isset($_POST['chunk_data']) ? wp_unslash($_POST['chunk_data']) : '';
        $is_first = isset($_POST['is_first']) && $_POST['is_first'] === '1';
        $is_last = isset($_POST['is_last']) && $_POST['is_last'] === '1';
        $chunk_index = isset($_POST['chunk_index']) ? (int)$_POST['chunk_index'] : 0;
        $total_chunks = isset($_POST['total_chunks']) ? (int)$_POST['total_chunks'] : 1;

        if (empty($chunk_data)) {
            wp_send_json_error('Chunk data je prázdné');
        }

        // Pro první chunk nastavit flag a vymazat předchozí stav
        if ($is_first) {
            // Kontrola, zda už neprobíhá jiný import (ochrana před souběžnými importy)
            if (db_is_poi_import_running()) {
                wp_send_json_error('Import již probíhá. Počkejte, až se dokončí současný import, nebo zkuste znovu za chvíli.');
                return;
            }
            
            db_set_poi_import_running(true);
            // Vymazat předchozí stav (pokud existuje)
            delete_transient('db_poi_import_processed_ids');
            delete_transient('db_poi_import_total_stats');
            delete_transient('db_poi_import_header');
        } else {
            // Pro další chunky obnovit flag (aby nevypršel během dlouhého importu)
            db_set_poi_import_running(true);
        }

        // Zpracovat hlavičku
        if ($is_first) {
            // Pro první chunk uložit hlavičku
            $lines = explode("\n", $chunk_data);
            if (!empty($lines[0])) {
                $header = $lines[0];
                set_transient('db_poi_import_header', $header, 1800); // 30 minut TTL
            }
        } else {
            // Pro další chunky načíst hlavičku a přidat ji
            $header = get_transient('db_poi_import_header');
            if (!$header) {
                // Pokud hlavička chybí (vypršela nebo byla smazána), import nemůže pokračovat
                db_set_poi_import_running(false);
                delete_transient('db_poi_import_processed_ids');
                delete_transient('db_poi_import_total_stats');
                delete_transient('db_poi_import_header');
                wp_send_json_error('Hlavička CSV souboru není dostupná. Import byl přerušen. Zkuste import znovu od začátku.');
                return;
            }
            $chunk_data = $header . "\n" . $chunk_data;
            // Obnovit TTL hlavičky (aby nevypršela během dlouhého importu)
            set_transient('db_poi_import_header', $header, 1800);
        }

        // Vytvořit dočasný soubor s chunk daty
        $temp_file = tmpfile();
        if (!$temp_file) {
            // Vymazat flag a transienty při chybě
            db_set_poi_import_running(false);
            delete_transient('db_poi_import_processed_ids');
            delete_transient('db_poi_import_total_stats');
            delete_transient('db_poi_import_header');
            wp_send_json_error('Nelze vytvořit dočasný soubor');
        }

        // Zapsat chunk data do dočasného souboru
        fwrite($temp_file, $chunk_data);
        rewind($temp_file);

        try {
            // Zpracovat chunk
            $result = $this->import_from_stream($temp_file, [
                'log_every' => 1000, // Méně logování pro chunky
            ]);

            // Načíst a aktualizovat celkové statistiky
            $total_stats = get_transient('db_poi_import_total_stats');
            if (!$total_stats) {
                $total_stats = [
                    'imported' => 0,
                    'updated' => 0,
                    'total_rows' => 0,
                    'skipped_rows' => 0,
                    'errors' => [],
                ];
            }

            $total_stats['imported'] += $result['imported'];
            $total_stats['updated'] += $result['updated'];
            $total_stats['total_rows'] += $result['total_rows'];
            $total_stats['skipped_rows'] += $result['skipped_rows'];
            $total_stats['errors'] = array_merge($total_stats['errors'], $result['errors']);

            // Uložit processed_poi_ids
            $processed_ids = get_transient('db_poi_import_processed_ids');
            if (!is_array($processed_ids)) {
                $processed_ids = [];
            }
            $processed_ids = array_merge($processed_ids, $result['processed_poi_ids'] ?? []);
            set_transient('db_poi_import_processed_ids', $processed_ids, 600); // 10 minut
            set_transient('db_poi_import_total_stats', $total_stats, 600);

            // Pro poslední chunk zařadit do fronty a vymazat flag
            if ($is_last) {
                $enqueued_count = 0;
                $affected_count = 0;
                if (!empty($processed_ids) && class_exists('\DB\Jobs\Nearby_Queue_Manager')) {
                    $queue_manager = new \DB\Jobs\Nearby_Queue_Manager();
                    foreach (array_unique($processed_ids) as $poi_id) {
                        if ($queue_manager->enqueue($poi_id, 'charging_location', 1)) {
                            $enqueued_count++;
                        }
                        $affected_count += $queue_manager->enqueue_affected_points($poi_id, 'poi');
                    }
                }
                // Vymazat flag a transienty
                db_set_poi_import_running(false);
                delete_transient('db_poi_import_processed_ids');
                delete_transient('db_poi_import_total_stats');
                delete_transient('db_poi_import_header');

                wp_send_json_success([
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                    'is_last' => true,
                    'chunk_result' => $result,
                    'total_stats' => $total_stats,
                    'enqueued_count' => $enqueued_count,
                    'affected_count' => $affected_count,
                ]);
            } else {
                wp_send_json_success([
                    'chunk_index' => $chunk_index,
                    'total_chunks' => $total_chunks,
                    'is_last' => false,
                    'chunk_result' => $result,
                    'total_stats' => $total_stats,
                    'progress' => round(($chunk_index + 1) / $total_chunks * 100, 1),
                ]);
            }
        } catch (\Throwable $e) {
            // Vymazat flag při jakékoli chybě (ne jen první/poslední chunk)
            db_set_poi_import_running(false);
            delete_transient('db_poi_import_processed_ids');
            delete_transient('db_poi_import_total_stats');
            delete_transient('db_poi_import_header');
            wp_send_json_error('Chyba při zpracování chunku: ' . $e->getMessage());
        } finally {
            fclose($temp_file);
        }
    }

    private function get_upload_error_message(int $error_code): string {
        switch ($error_code) {
            case UPLOAD_ERR_INI_SIZE:
                return 'Soubor je příliš velký (překročen upload_max_filesize)';
            case UPLOAD_ERR_FORM_SIZE:
                return 'Soubor je příliš velký (překročen MAX_FILE_SIZE)';
            case UPLOAD_ERR_PARTIAL:
                return 'Soubor byl nahrán pouze částečně';
            case UPLOAD_ERR_NO_FILE:
                return 'Nebyl nahrán žádný soubor';
            case UPLOAD_ERR_NO_TMP_DIR:
                return 'Chybí dočasný adresář';
            case UPLOAD_ERR_CANT_WRITE:
                return 'Chyba při zápisu na disk';
            case UPLOAD_ERR_EXTENSION:
                return 'Nahrávání zastaveno rozšířením';
            default:
                return 'Neznámá chyba nahrávání';
        }
    }

    public function handle_load_poi_by_filters(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $filters = $_POST['filters'] ?? [];
        
        $args = [
            'post_type' => 'poi',
            'post_status' => 'publish',
            'posts_per_page' => intval($filters['limit'] ?? 100),
        ];

        if (!empty($filters['poi_type'])) {
            $args['tax_query'] = [
                [
                    'taxonomy' => 'poi_type',
                    'field' => 'slug',
                    'terms' => $filters['poi_type']
                ]
            ];
        }

        $poi_posts = get_posts($args);
        $poi_data = [];

        foreach ($poi_posts as $poi) {
            $poi_type = wp_get_post_terms($poi->ID, 'poi_type', ['fields' => 'names']);
            $poi_type = !empty($poi_type) ? $poi_type[0] : '';
            
            $poi_data[] = [
                'id' => $poi->ID,
                'title' => $poi->post_title,
                'type' => $poi_type,
                'lat' => get_post_meta($poi->ID, '_poi_lat', true),
                'lng' => get_post_meta($poi->ID, '_poi_lng', true),
                'icon' => get_post_meta($poi->ID, '_poi_icon', true),
                'color' => get_post_meta($poi->ID, '_poi_color', true),
                'db_recommended' => ( get_post_meta($poi->ID, '_db_recommended', true) === '1' ),
                'url' => get_permalink($poi->ID)
            ];
        }

        // Filtrovat podle koordinátů
        if (!empty($filters['coords_status'])) {
            if ($filters['coords_status'] === 'with_coords') {
                $poi_data = array_filter($poi_data, function($poi) {
                    return !empty($poi['lat']) && !empty($poi['lng']);
                });
            } elseif ($filters['coords_status'] === 'without_coords') {
                $poi_data = array_filter($poi_data, function($poi) {
                    return empty($poi['lat']) || empty($poi['lng']);
                });
            }
        }

        wp_send_json_success([
            'poi' => array_values($poi_data)
        ]);
    }

    public function handle_update_type_icon(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $type_id = (int) $_POST['type_id'];
        $icon = sanitize_text_field($_POST['icon']);

        if (!$type_id || !$icon) {
            wp_send_json_error('Chybí povinné parametry');
        }

        update_term_meta($type_id, 'poi_icon', $icon);
        
        wp_send_json_success([
            'message' => 'Ikona typu aktualizována'
        ]);
    }

    public function handle_update_all_poi_icons(): void {
        check_ajax_referer('db_poi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_die('Nedostatečná oprávnění');
        }

        $poi_types = get_terms([
            'taxonomy' => 'poi_type',
            'hide_empty' => false,
        ]);

        if (is_wp_error($poi_types)) {
            wp_send_json_error('Chyba při načítání typů POI');
        }

        $updated = 0;
        $errors = [];

        foreach ($poi_types as $type) {
            $icon = get_term_meta($type->term_id, 'poi_icon', true);
            if (empty($icon)) continue;

            // Najít všechny POI tohoto typu
            $poi_posts = get_posts([
                'post_type' => 'poi',
                'post_status' => 'publish',
                'posts_per_page' => -1,
                'tax_query' => [
                    [
                        'taxonomy' => 'poi_type',
                        'field' => 'term_id',
                        'terms' => $type->term_id
                    ]
                ]
            ]);

            foreach ($poi_posts as $poi) {
                try {
                    update_post_meta($poi->ID, '_poi_icon', $icon);
                    $updated++;
                } catch (Exception $e) {
                    $errors[] = "POI ID {$poi->ID}: " . $e->getMessage();
                }
            }
        }

        wp_send_json_success([
            'message' => "Úspěšně aktualizováno {$updated} POI",
            'errors' => $errors
        ]);
    }
}
