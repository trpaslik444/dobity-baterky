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

        $headers = fgetcsv($handle);
        error_log('[POI Import] CSV hlavičky: ' . print_r($headers, true));
        
        if (empty($headers)) {
            fclose($handle);
            wp_send_json_error('CSV soubor je prázdný nebo neplatný');
        }

        // Připravit flexibilní mapování hlaviček
        $normalize = function(string $s): string {
            $s = trim(mb_strtolower($s));
            // odstranění diakritiky (pokud dostupné)
            $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
            if ($trans !== false && $trans !== null) {
                $s = strtolower(preg_replace('/[^a-z0-9_\- ]+/','',$trans));
            }
            $s = str_replace(['\t'], ' ', $s);
            $s = preg_replace('/\s+/', ' ', $s);
            return trim($s);
        };

        // Map synonym → interní klíč
        $synonymToInternal = [
            // Název
            'nazev' => 'Název',
            'name' => 'Název',
            'cafe name' => 'Název',
            'title' => 'Název',
            // Popis
            'popis' => 'Popis',
            'description' => 'Popis',
            'address' => 'Popis',
            // Typ
            'typ' => 'Typ',
            'type' => 'Typ',
            // Latitude/Longitude
            'latitude' => 'Latitude',
            'lat' => 'Latitude',
            'y' => 'Latitude',
            'longitude' => 'Longitude',
            'lng' => 'Longitude',
            'lon' => 'Longitude',
            'x' => 'Longitude',
            // Ikona / Barva
            'ikona' => 'Ikona',
            'icon' => 'Ikona',
            'barva' => 'Barva',
            'color' => 'Barva',
            // ID
            'id' => 'ID',
        ];

        // Vytvořit mapu indexu sloupce -> interní klíč
        $columnIndexToInternal = [];
        foreach ($headers as $idx => $rawHeader) {
            $key = $normalize((string)$rawHeader);
            if (isset($synonymToInternal[$key])) {
                $columnIndexToInternal[$idx] = $synonymToInternal[$key];
            } else {
                // Pokud neznámé, ponecháme původní hlavičku (pro případ původního CSV s českými názvy)
                $columnIndexToInternal[$idx] = (string)$rawHeader;
            }
        }

        $imported = 0;
        $updated = 0;
        $errors = [];
        $row_count = 0;

        while (($data = fgetcsv($handle)) !== false) {
            $row_count++;
            error_log("[POI Import] Řádek {$row_count}: " . print_r($data, true));
            
            if (count($data) < 2) {
                $errors[] = "Řádek {$row_count}: Nedostatečný počet sloupců (" . count($data) . ")";
                continue;
            }

            try {
                // Sloučit data s interními klíči
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

                // Připrava koordinát pro matching
                $latInput = isset($poi_data['Latitude']) ? floatval($poi_data['Latitude']) : null;
                $lngInput = isset($poi_data['Longitude']) ? floatval($poi_data['Longitude']) : null;

                // Upsert strategie: 1) ID, 2) Title+Coords (tolerance), 3) vytvořit nový
                $poi_id = 0;

                // 1) Update podle ID, pokud je v CSV
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

                // 2) Pokud nebylo nalezeno dle ID, zkus match Title+Coords (tolerance 1e-5)
                if (!$poi_id && $latInput !== null && $lngInput !== null) {
                    $tolerance = 1e-5; // ~1 m
                    global $wpdb;
                    // Najdeme kandidáty se stejným názvem
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
                            // Update existujícího
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
                                continue 2; // další CSV řádek
                            }
                            break;
                        }
                    }
                }

                // 2b) Pokud stále nic, zkus Title-only pokud je výskyt jednoznačný (řeší dříve importované bez koordinát)
                if (!$poi_id) {
                    global $wpdb;
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
                            continue;
                        }
                    }
                }

                // 2c) Pokud máme koordináty a Title nebyl jednoznačný, zkus čistě podle blízkých koordinát (unikátní v okolí)
                if (!$poi_id && $latInput !== null && $lngInput !== null) {
                    $tolerance = 1e-5; // ~1 m
                    global $wpdb;
                    // Získat posledních 1000 POI (rychlý odhad) a filtrovat v PHP (
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
                            continue;
                        }
                    }
                }

                // 3) Vytvořit nový, pokud se nenašel existující
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

                // Typ POI z CSV => vždy název termu (nikdy term_id), číselné hodnoty ignorovat
                try {
                    $type_name = db_normalize_poi_type_from_csv($poi_data, 'kavárna'); // fallback = kavárna
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

                // Koordináty
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

                // Ikona a barva
                if (!empty($poi_data['Ikona'])) {
                    update_post_meta($poi_id, '_poi_icon', sanitize_text_field($poi_data['Ikona']));
                }
                if (!empty($poi_data['Barva'])) {
                    $color = $poi_data['Barva'];
                    // Vlastní validace hex barvy
                    if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
                        update_post_meta($poi_id, '_poi_color', $color);
                    } else {
                        $errors[] = "Řádek {$row_count}: Neplatná hex barva: {$color}";
                    }
                }

                error_log("[POI Import] POI {$poi_id} úspěšně importován/aktualizován");
                
            } catch (Exception $e) {
                $errors[] = "Řádek {$row_count}: Exception: " . $e->getMessage();
                error_log("[POI Import] Exception v řádku {$row_count}: " . $e->getMessage());
            } catch (Error $e) {
                $errors[] = "Řádek {$row_count}: Fatal Error: " . $e->getMessage();
                error_log("[POI Import] Fatal Error v řádku {$row_count}: " . $e->getMessage());
            }
        }

        fclose($handle);

        error_log("[POI Import] Import dokončen. Importováno: {$imported}, Chyby: " . count($errors));

        wp_send_json_success([
            'message' => "Úspěšně importováno {$imported} POI z {$row_count} řádků",
            'imported' => $imported,
            'total_rows' => $row_count,
            'errors' => $errors
        ]);
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
