<?php
/**
 * Přepracovaný formulář pro charging_location s kompletní OCM integrací
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Přepracovaný formulář pro nabíjecí lokality
 */
class Charging_Location_Form {

    /**
     * Instance třídy
     */
    private static $instance = null;

    /**
     * Získání instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
    }

    /**
     * Inicializace
     */
    public function init() {
        error_log('[CHARGING DEBUG] Charging_Location_Form init');
        
        // Meta boxy se přidávají s vysokou prioritou, aby se přidaly po registraci post type
        add_action('add_meta_boxes', array($this, 'add_meta_boxes'), 999);
        add_action('save_post', array($this, 'save_meta_boxes'));
        
        // AJAX handlery se registrují v admin_init hooku
        add_action('admin_init', array($this, 'register_ajax_handlers'));
        
        // MPO testovací stránku již neregistrujeme (odstraněno)
        
        // REST API endpoint pro nahrávání obrázků
        add_action('rest_api_init', array($this, 'register_rest_routes'));
        
        // Načtení JavaScript souboru pro Gutenberg integraci
        add_action('admin_enqueue_scripts', array($this, 'enqueue_scripts'));
        
        error_log('[CHARGING DEBUG] Charging_Location_Form init dokončen');
    }

    /**
     * Registrace REST API routes
     */
    public function register_rest_routes() {
        error_log('[CHARGING DEBUG] Registruji REST API routes');
        
        register_rest_route('charging/v1', '/upload-image', array(
            'methods' => 'POST',
            'callback' => array($this, 'rest_upload_image'),
            'permission_callback' => function() {
                return current_user_can('upload_files');
            },
            'args' => array(
                'photo_url' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'esc_url_raw'
                ),
                'post_title' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'post_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                )
            )
        ));
        
        error_log('[CHARGING DEBUG] REST API routes registrovány');
    }

    /**
     * REST API handler pro nahrávání obrázků
     */
    public function rest_upload_image($request) {
        error_log('[CHARGING DEBUG] REST API handler volán');
        
        $photo_url = $request->get_param('photo_url');
        $post_title = $request->get_param('post_title');
        $post_id = $request->get_param('post_id');
        
        error_log('[CHARGING DEBUG] Parametry: photo_url=' . $photo_url . ', post_title=' . $post_title . ', post_id=' . $post_id);
        
        if (empty($photo_url) || empty($post_title) || empty($post_id)) {
            error_log('[CHARGING DEBUG] Chybí povinné parametry');
            return new \WP_Error('missing_params', 'Chybí povinné parametry', array('status' => 400));
        }
        
        try {
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            
            $tmp = media_sideload_image($photo_url, $post_id, $post_title, 'src');
            
            if (is_wp_error($tmp)) {
                return new \WP_Error('upload_failed', 'Chyba při nahrávání: ' . $tmp->get_error_message(), array('status' => 500));
            }
            
            $attachments = get_posts(array(
                'numberposts' => 1,
                'post_type' => 'attachment',
                'post_parent' => $post_id,
                'orderby' => 'date',
                'order' => 'DESC',
            ));
            
            if (!empty($attachments)) {
                $attachment_id = $attachments[0]->ID;
                $attachment_url = wp_get_attachment_url($attachment_id);
                
                set_post_thumbnail($post_id, $attachment_id);
                
                return array(
                    'success' => true,
                    'attachment_id' => $attachment_id,
                    'url' => $attachment_url
                );
            } else {
                return new \WP_Error('no_attachment', 'Nepodařilo se najít nahraný obrázek', array('status' => 500));
            }
            
        } catch (Exception $e) {
            return new \WP_Error('exception', 'Exception: ' . $e->getMessage(), array('status' => 500));
        }
    }

    /**
     * Načtení JavaScript souborů
     */
    public function enqueue_scripts($hook) {
        global $post;
        
        error_log('[CHARGING DEBUG] enqueue_scripts volán s hook: ' . $hook);
        
        // Načíst na všech admin stránkách pro debugging
        if (is_admin()) {
            // WordPress Media Library
            wp_enqueue_media();
            
            // CSS styly pro notifikace
            wp_enqueue_style(
                'db-map-styles',
                DB_PLUGIN_URL . 'assets/db-map.css',
                array(),
                DB_PLUGIN_VERSION
            );
            
            wp_enqueue_script(
                'charging-ocm-fill',
                DB_PLUGIN_URL . 'assets/charging-ocm-fill.js',
                array('jquery', 'wp-data', 'wp-api-fetch'),
                DB_PLUGIN_VERSION,
                true
            );
            
            wp_localize_script('charging-ocm-fill', 'chargingOcmFill', array(
                'nonce' => wp_create_nonce('upload_ocm_image'),
                'postId' => $post ? $post->ID : 0,
                'ajaxurl' => admin_url('admin-ajax.php')
            ));
            
            error_log('[CHARGING DEBUG] Script načten: ' . DB_PLUGIN_URL . 'assets/charging-ocm-fill.js');
            error_log('[CHARGING DEBUG] CSS načten: ' . DB_PLUGIN_URL . 'assets/db-map.css');
        }
    }

    /**
     * Přidání meta boxů
     */
    public function add_meta_boxes() {
        error_log('[CHARGING DEBUG] add_meta_boxes volán');
        
        // Kontrola, zda je post type registrován
        if (!post_type_exists('charging_location')) {
            error_log('[CHARGING DEBUG] Post type charging_location neexistuje');
            return;
        }
        
        error_log('[CHARGING DEBUG] Přidávám meta boxy pro charging_location');
        
        add_meta_box(
            'db_charging_ocm_search',
            'OpenChargeMap - Vyhledávání a import dat',
            array($this, 'render_ocm_search_box'),
            'charging_location',
            'normal',
            'high'
        );
        
        add_meta_box(
            'db_charging_basic_info',
            'Základní informace',
            array($this, 'render_basic_info_box'),
            'charging_location',
            'normal',
            'default'
        );
        
        add_meta_box(
            'db_charging_operator_info',
            'Informace o operátorovi a provozu',
            array($this, 'render_operator_info_box'),
            'charging_location',
            'normal',
            'default'
        );
        
        add_meta_box(
            'db_charging_technical',
            'Technické údaje',
            array($this, 'render_technical_box'),
            'charging_location',
            'normal',
            'default'
        );
        
        add_meta_box(
            'db_charging_media',
            'Média a obrázky',
            array($this, 'render_media_box'),
            'charging_location',
            'side',
            'default'
        );
        
        // MPO import meta box odstraněn – používáme samostatný EV – MPO Importer
        
        error_log('[CHARGING DEBUG] Meta boxy přidány');
    }

    /**
     * Render meta boxu pro MPO import
     * (odstraněno – nepoužívá se)
     */
    public function render_mpo_import_box($post) {
        $mpo_last_update = get_option('db_mpo_last_update', 'Nikdy');
        $mpo_stations_count = get_option('db_mpo_stations_count', '0');
        $mpo_points_count = get_option('db_mpo_points_count', '0');
        
        // Generování aktuálního MPO odkazu
        $current_year = date('Y');
        $current_month = date('n'); // 1-12
        $mpo_url = "https://mpo.gov.cz/assets/cz/energetika/statistika/statistika-a-evidence-cerpacich-a-dobijecich-stanic/{$current_year}/{$current_month}/";
        ?>
        
        <div class="mpo-import-container">
            <div class="mpo-status">
                <h5>📊 Aktuální stav MPO dat</h5>
                <p><strong>Poslední aktualizace:</strong> <span id="mpo-last-update"><?php echo esc_html($mpo_last_update); ?></span></p>
                <p><strong>Počet stanic:</strong> <span id="mpo-stations-count"><?php echo esc_html($mpo_stations_count); ?></span></p>
                <p><strong>Počet dobíjecích bodů:</strong> <span id="mpo-points-count"><?php echo esc_html($mpo_points_count); ?></span></p>
            </div>
            
            <div class="mpo-import">
                <h5>📥 Import nových dat</h5>
                <form method="post" enctype="multipart/form-data" id="mpo-import-form">
                    <?php wp_nonce_field('db_mpo_import', 'db_mpo_import_nonce'); ?>
                    <div class="form-group">
                        <label for="mpo_excel_file">Vyberte Excel soubor (.xlsx):</label>
                        <input type="file" id="mpo_excel_file" name="mpo_excel_file" accept=".xlsx,.xls" required />
                    </div>
                                    <button type="submit" name="import_mpo_data" class="button button-primary" id="mpo-import-btn">
                    📥 Importovat MPO data
                </button>
            </form>
            <div id="mpo-import-status" style="margin-top: 10px;"></div>
            
            <hr style="margin: 20px 0;">
            <h4>🧪 Test AJAX funkcionality</h4>
            <button type="button" class="button button-secondary" id="test-ajax-btn">
                🔍 Test AJAX handleru
            </button>
            <div id="test-ajax-status" style="margin-top: 10px;"></div>
            
            <h4>🔧 Debug informace</h4>
            <div style="background: #f0f0f0; padding: 10px; border-radius: 4px; font-family: monospace; font-size: 12px;">
                <strong>AJAX URL:</strong> <?php echo admin_url('admin-ajax.php'); ?><br>
                <strong>Plugin URL:</strong> <?php echo plugin_dir_url(__FILE__); ?><br>
                <strong>Plugin Path:</strong> <?php echo plugin_dir_path(__FILE__); ?><br>
                <strong>WordPress Version:</strong> <?php echo get_bloginfo('version'); ?><br>
                <strong>PHP Version:</strong> <?php echo PHP_VERSION; ?><br>
                <strong>Current User:</strong> <?php echo wp_get_current_user()->user_login; ?><br>
                <strong>User Capabilities:</strong> <?php echo current_user_can('manage_options') ? 'manage_options' : 'limited'; ?>
            </div>
            
            <h4>🧪 Přímý test AJAX</h4>
            <button type="button" class="button button-secondary" id="direct-test-btn">
                🔍 Přímý test AJAX
            </button>
            <div id="direct-test-status" style="margin-top: 10px;"></div>
            </div>
            
            <div class="mpo-link">
                <h5>🔗 Rychlý odkaz na MPO</h5>
                <p><a href="<?php echo esc_url($mpo_url); ?>" target="_blank" class="button button-secondary" style="width: 100%; text-align: center;">
                    🌐 Otevřít MPO portál
                </a></p>
                <p style="font-size: 12px; color: #666; margin-top: 5px;">
                    <strong>Tip:</strong> Odkaz se automaticky aktualizuje podle aktuálního měsíce a roku.<br>
                    Aktuálně: <strong><?php echo esc_html($current_month . '/' . $current_year); ?></strong>
                </p>
            </div>
            
            <div class="mpo-info">
                <h5>ℹ️ Informace o MPO datech</h5>
                <ul style="font-size: 12px; color: #666; margin: 0; padding-left: 20px;">
                    <li>Oficiální databáze všech dobíjecích stanic v ČR</li>
                    <li>Aktualizuje se měsíčně</li>
                    <li>Obsahuje přes 3,000 stanic a 5,600 dobíjecích bodů</li>
                    <li>GPS souřadnice, výkony, typy konektorů</li>
                    <li>Provozovatelé: ČEZ, PRE, E.ON, atd.</li>
                </ul>
            </div>
        </div>
        
        <style>
        .mpo-import-container {
            padding: 10px 0;
        }
        .mpo-status, .mpo-import, .mpo-link, .mpo-info {
            margin-bottom: 20px;
            padding: 15px;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            background: #fff;
        }
        .mpo-status h5, .mpo-import h5, .mpo-link h5, .mpo-info h5 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #23282d;
            font-size: 14px;
        }
        .form-group {
            margin-bottom: 15px;
        }
        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: bold;
        }
        .form-group input[type="file"] {
            width: 100%;
            padding: 5px;
        }
        #mpo-import-status {
            padding: 10px;
            border-radius: 4px;
            font-size: 13px;
        }
        .mpo-import-success {
            background: #d4edda;
            color: #155724;
            border: 1px solid #c3e6cb;
        }
        .mpo-import-error {
            background: #f8d7da;
            color: #721c24;
            border: 1px solid #f5c6cb;
        }
        .mpo-import-info {
            background: #d1ecf1;
            color: #0c5460;
            border: 1px solid #bee5eb;
        }
        </style>
        
        <script>
        jQuery(document).ready(function($) {
            // OCM Enrich – nedestruktivní doplnění chybějících dat
            $('#db-ocm-enrich-btn').on('click', function(){
                console.info('[DB][OCM][ENRICH] Klik – start');
                const lat = $('#_db_lat').val();
                const lng = $('#_db_lng').val();
                const $out = $('#db-ocm-enrich-results');
                $out.html('<em>Načítám návrhy z OCM…</em>');
                $.post(ajaxurl, {
                    action: 'db_ocm_enrich_suggestions',
                    nonce: '<?php echo wp_create_nonce('db_ocm_enrich'); ?>',
                    lat: lat,
                    lng: lng,
                    post_id: <?php echo (int)$post->ID; ?>
                }, function(resp){
                    console.info('[DB][OCM][ENRICH] Response:', resp);
                    if (!resp || !resp.success) {
                        $out.html('<span style="color:#a00;">Chyba, nelze získat návrhy.</span>');
                        return;
                    }
                    const sug = resp.data || {};
                    const rows = [];
                    if (sug.max_power_kw && !$('#_max_power_kw').val()) {
                        rows.push(`<tr><td>Max výkon (kW)</td><td><code>${sug.max_power_kw}</code></td><td><button type="button" class="button apply-enrich" data-target="_max_power_kw" data-value="${sug.max_power_kw}">Použít</button></td></tr>`);
                    }
                    if (Array.isArray(sug.connectors) && sug.connectors.length > 0) {
                        rows.push(`<tr><td>Konektory</td><td><code>${sug.connectors.map(c=>`${c.type} ${c.power_kw||''}kW`).join(', ')}</code></td><td><button type="button" class="button apply-enrich-connectors">Navrhnout do formuláře</button></td></tr>`);
                    }
                    if (sug.image_url && !$('#_ocm_image_url').val()) {
                        rows.push(`<tr><td>Obrázek</td><td><code>${sug.image_url}</code></td><td><button type="button" class="button apply-enrich" data-target="_ocm_image_url" data-value="${sug.image_url}">Použít</button></td></tr>`);
                    }
                    if (rows.length === 0) {
                        $out.html('<span>Nebyl nalezen žádný vhodný návrh ke doplnění (nepřepisuji existující hodnoty).</span>');
                        return;
                    }
                    $out.html(`<table class="widefat"><thead><tr><th>Položka</th><th>Návrh z OCM</th><th>Akce</th></tr></thead><tbody>${rows.join('')}</tbody></table>`);
                });
            });

            $(document).on('click', '.apply-enrich', function(){
                const target = $(this).data('target');
                const value = $(this).data('value');
                if (!target) return;
                const $el = $('#'+target);
                if ($el.length && !$el.val()) {
                    $el.val(value);
                    console.info('[DB][OCM][ENRICH] Applied ->', target, value);
                    window.showNotification('Hodnota doplněna z OCM: '+target, 'success');
                } else {
                    console.info('[DB][OCM][ENRICH] Skipped (already filled) ->', target);
                    window.showNotification('Pole již má hodnotu, nic nepřepsáno.', 'warning');
                }
            });

            $(document).on('click', '.apply-enrich-connectors', function(){
                console.info('[DB][OCM][ENRICH] Connectors suggestion clicked');
                window.showNotification('Návrh konektorů připraven – ručně potvrďte v technickém bloku dle potřeby.', 'info');
            });
            $('#mpo-import-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                var importBtn = $('#mpo-import-btn');
                var statusDiv = $('#mpo-import-status');
                
                // Zobrazit loading
                importBtn.prop('disabled', true).text('⏳ Importuji...');
                statusDiv.html('<div class="mpo-import-info">⏳ Importuji MPO data...</div>');
                
                // AJAX import
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        if (response.success) {
                            statusDiv.html('<div class="mpo-import-success">✅ ' + response.data.message + '</div>');
                            // Aktualizovat počty
                            $('#mpo-last-update').text(response.data.last_update);
                            $('#mpo-stations-count').text(response.data.stations_count);
                            $('#mpo-points-count').text(response.data.points_count);
                        } else {
                            statusDiv.html('<div class="mpo-import-error">❌ ' + response.data + '</div>');
                        }
                    },
                    error: function() {
                        statusDiv.html('<div class="mpo-import-error">❌ Chyba při importu</div>');
                    },
                    complete: function() {
                        importBtn.prop('disabled', false).text('📥 Importovat MPO data');
                    }
                });
            });
            
            // Testovací AJAX handler
            $('#test-ajax-btn').on('click', function() {
                var testBtn = $(this);
                var statusDiv = $('#test-ajax-status');
                
                testBtn.prop('disabled', true).text('⏳ Testuji...');
                statusDiv.html('<div class="notice notice-info">⏳ Testuji AJAX handler...</div>');
                
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'db_test_mpo',
                        nonce: '<?php echo wp_create_nonce('db_test_mpo'); ?>'
                    },
                    success: function(response) {
                        console.log('Test AJAX response:', response);
                        if (response.success) {
                            statusDiv.html('<div class="notice notice-success">✅ ' + response.data.message + '</div>');
                        } else {
                            statusDiv.html('<div class="notice notice-error">❌ ' + response.data + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('Test AJAX error:', {xhr: xhr, status: status, error: error});
                        statusDiv.html('<div class="notice notice-error">❌ Chyba při testu: ' + error + '</div>');
                    },
                    complete: function() {
                        testBtn.prop('disabled', false).text('🔍 Test AJAX handleru');
                    }
                });
            });
            
            // Přímý test AJAX bez WordPress hooks
            $('#direct-test-btn').on('click', function() {
                var testBtn = $(this);
                var statusDiv = $('#direct-test-status');
                
                testBtn.prop('disabled', true).text('⏳ Testuji...');
                statusDiv.html('<div class="notice notice-info">⏳ Testuji přímý AJAX...</div>');
                
                // Test 1: Základní AJAX request
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: {
                        action: 'test_direct_ajax'
                    },
                    success: function(response) {
                        console.log('Přímý test success:', response);
                        statusDiv.html('<div class="notice notice-success">✅ Přímý test funguje!</div>');
                    },
                    error: function(xhr, status, error) {
                        console.log('Přímý test error:', {xhr: xhr, status: status, error: error});
                        statusDiv.html('<div class="notice notice-error">❌ Přímý test selhal: ' + error + '</div>');
                    },
                    complete: function() {
                        testBtn.prop('disabled', false).text('🔍 Přímý test AJAX');
                    }
                });
            });
        });
        </script>
        <?php
    }

    /**
     * Render meta boxu pro OCM vyhledávání
     */
    public function render_ocm_search_box($post) {
        wp_nonce_field('db_save_charging_form', 'db_charging_form_nonce');
        error_log('[CHARGING DEBUG] Nonce generován: ' . wp_create_nonce('db_save_charging_form'));
        
        $selected_station = get_post_meta($post->ID, '_selected_station_data', true);
        ?>
        <div class="ocm-search-container">
            <h4>1. Objevování nabíjecích stanic</h4>
            
            <div class="search-section">
                <h5>Hledání podle názvu</h5>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" id="station_name_search" placeholder="Zadejte název stanice..." style="flex: 1;" />
                    <button type="button" class="button button-primary" onclick="searchStationsByName()">Hledat v OCM</button>
                </div>
                <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
                    <strong>💡 Tip:</strong> OCM (OpenChargeMap) poskytuje rozsáhlou databázi nabíjecích stanic z celého světa.
                </p>
            </div>
            
            <div class="search-section">
                <h5>Hledání podle souřadnic</h5>
                <div style="display: flex; gap: 10px; margin-bottom: 15px;">
                    <input type="text" id="coordinates_search" placeholder="50.0755, 14.4378 nebo 50.0755N, 14.4378E" style="flex: 1;" />
                    <button type="button" class="button button-primary" onclick="searchStationsByCoordinates()">Hledat v OCM</button>
                </div>
                <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
                    <strong>💡 Tip:</strong> OCM (OpenChargeMap) poskytuje rozsáhlou databázi nabíjecích stanic z celého světa.
                </p>
            </div>
            
            <div class="search-section">
                <h5>Nalezené stanice</h5>
                <div id="search_results" style="margin-bottom: 15px;">
                    <p style="color: #666;">Zadejte název nebo souřadnice pro objevení stanic...</p>
                </div>
            </div>
            
            <div class="search-section" id="selected_station_section" style="display: none;">
                <h5>Vybraná stanice</h5>
                <div id="selected_station_info" style="padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                    <!-- Zde se zobrazí informace o vybrané stanici -->
                </div>
                <div style="margin-top: 10px;">
                    <button type="button" class="button button-primary" onclick="applySelectedStation()">Použít vybranou stanici</button>
                    <button type="button" class="button" onclick="clearSelectedStation()">Zrušit výběr</button>
                </div>
            </div>
        </div>

        <style>
        .ocm-search-container {
            padding: 15px;
        }
        .search-section {
            margin-bottom: 25px;
            padding: 15px;
            border: 1px solid #e5e5e5;
            border-radius: 4px;
            background: #fff;
        }
        .search-section h5 {
            margin-top: 0;
            margin-bottom: 10px;
            color: #23282d;
        }
        .station-result {
            padding: 10px;
            margin: 5px 0;
            border: 1px solid #ddd;
            border-radius: 4px;
            cursor: pointer;
            background: #f9f9f9;
        }
        .station-result:hover {
            background: #e9e9e9;
        }
        .station-result.selected {
            background: #0073aa;
            color: white;
        }
        .station-details {
            font-size: 12px;
            color: #666;
            margin-top: 5px;
        }
        </style>

        <script>
        var selectedStationData = null;
        
        // Funkce showNotification je nyní definována globálně v render_ocm_search_box()
        
        function searchStationsByName() {
            var name = document.getElementById('station_name_search').value.trim();
            if (!name) {
                alert('Zadejte název stanice');
                return;
            }
            
            document.getElementById('search_results').innerHTML = '<p>Vyhledávám...</p>';
            
            jQuery.post(ajaxurl, {
                action: 'search_charging_stations_by_name',
                nonce: '<?php echo wp_create_nonce('db_charging_search'); ?>',
                name: name
            }, function(response) {
                if (response.success) {
                    displaySearchResults(response.data);
                } else {
                    document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba: ' + response.data + '</p>';
                }
            }).fail(function() {
                document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba při vyhledávání</p>';
            });
        }
        
        function searchStationsByCoordinates() {
            var coords = document.getElementById('coordinates_search').value.trim();
            
            if (!coords) {
                alert('Zadejte souřadnice');
                return;
            }
            
            var coordsData = parseCoordinates(coords);
            
            if (!coordsData) {
                alert('Neplatný formát souřadnic. Použijte: 50.0755, 14.4378 nebo 50.0755N, 14.4378E');
                return;
            }
            
            document.getElementById('search_results').innerHTML = '<p>Vyhledávám v OpenChargeMap...</p>';
            
            jQuery.post(ajaxurl, {
                action: 'search_charging_stations_by_coordinates',
                nonce: '<?php echo wp_create_nonce('db_charging_search'); ?>',
                lat: coordsData.lat,
                lng: coordsData.lng
            }, function(response) {
                if (response.success) {
                    if (response.data && response.data.length > 0) {
                        displaySearchResults(response.data);
                    } else {
                        document.getElementById('search_results').innerHTML = '<p>Nebyly nalezeny žádné stanice</p>';
                    }
                } else {
                    document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba: ' + response.data + '</p>';
                }
            }).fail(function() {
                document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba při vyhledávání</p>';
            });
        }
        
        function searchBothDatabases(searchType) {
            var coords = document.getElementById('coordinates_search').value.trim();
            var name = document.getElementById('station_name_search').value.trim();
            
            if (searchType === 'coordinates') {
                if (!coords) {
                    alert('Zadejte souřadnice');
                    return;
                }
                
                var coordsData = parseCoordinates(coords);
                if (!coordsData) {
                    alert('Neplatný formát souřadnic. Použijte: 50.0755, 14.4378 nebo 50.0755N, 14.4378E');
                    return;
                }
            } else if (searchType === 'name') {
                if (!name) {
                    alert('Zadejte název stanice');
                    return;
                }
            }
            
            document.getElementById('search_results').innerHTML = '<p>Vyhledávám v OCM + DATEX II...</p>';
            
            var postData = {
                action: 'search_both_databases',
                nonce: '<?php echo wp_create_nonce('db_charging_search'); ?>',
                search_type: searchType
            };
            
            if (searchType === 'coordinates') {
                postData.lat = coordsData.lat;
                postData.lng = coordsData.lng;
            } else {
                postData.name = name;
            }
            
            jQuery.post(ajaxurl, postData, function(response) {
                if (response.success) {
                    if (response.data && response.data.length > 0) {
                        displaySearchResults(response.data);
                    } else {
                        document.getElementById('search_results').innerHTML = '<p>Nebyly nalezeny žádné stanice</p>';
                    }
                } else {
                    document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba: ' + response.data + '</p>';
                }
            }).fail(function() {
                document.getElementById('search_results').innerHTML = '<p style="color: red;">Chyba při vyhledávání</p>';
            });
        }
        
        function parseCoordinates(coords) {
            var match = coords.match(/([0-9.-]+)[°\s]*([NS]?)[,\s]+([0-9.-]+)[°\s]*([EW]?)/i);
            if (match) {
                var lat = parseFloat(match[1]);
                var lng = parseFloat(match[3]);
                
                if (match[2].toUpperCase() === 'S') lat = -lat;
                if (match[4].toUpperCase() === 'W') lng = -lng;
                
                return { lat: lat, lng: lng };
            }
            
            var parts = coords.split(/[,\s]+/);
            if (parts.length === 2) {
                var lat = parseFloat(parts[0]);
                var lng = parseFloat(parts[1]);
                if (!isNaN(lat) && !isNaN(lng)) {
                    return { lat: lat, lng: lng };
                }
            }
            
            return null;
        }
        
        function displaySearchResults(stations) {
            var resultsDiv = document.getElementById('search_results');
            
            if (!stations || stations.length === 0) {
                resultsDiv.innerHTML = '<p>Nebyly nalezeny žádné stanice</p>';
                return;
            }
            
            var html = '<div class="station-results">';
            stations.forEach(function(station, index) {
                // Badge pro zdroj dat
                var sourceBadge = '';
                if (station.data_source_badge) {
                    var badgeColor = station.data_source_color || '#666';
                    sourceBadge = '<span style="background: ' + badgeColor + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 8px; font-weight: 600;">' + station.data_source_badge + '</span>';
                }
                
                html += '<div class="station-result" onclick="selectStation(' + index + ')">';
                html += '<div style="display: flex; align-items: center; justify-content: space-between;">';
                html += '<strong>' + station.name + '</strong>';
                html += sourceBadge;
                html += '</div>';
                html += '<div class="station-details">';
                html += 'Adresa: ' + (station.address || 'N/A') + '<br>';
                html += 'Souřadnice: ' + station.lat + ', ' + station.lng + '<br>';
                
                // ID podle zdroje dat
                if (station.data_source === 'openchargemap') {
                    html += 'OCM ID: ' + (station.openchargemap_id || 'N/A') + '<br>';
                } else if (station.data_source === 'datex') {
                    html += 'DATEX ID: ' + (station.datex_id || 'N/A') + '<br>';
                } else {
                    html += 'ID: ' + (station.openchargemap_id || station.datex_id || 'N/A') + '<br>';
                }
                
                if (station.connectors && station.connectors.length > 0) {
                    html += 'Konektory: ' + station.connectors.map(c => c.type + ' ' + c.power_kw + 'kW').join(', ');
                } else {
                    html += 'Konektory: N/A';
                }
                if (station.operator) {
                    html += '<br>Operátor: ' + (station.operator.Title || 'N/A');
                }
                if (station.media && station.media.length > 0) {
                    html += '<br>Obrázky: ' + station.media.length + ' ks';
                }
                html += '</div>';
                html += '</div>';
            });
            html += '</div>';
            
            resultsDiv.innerHTML = html;
            window.searchResults = stations;
        }
        
        function selectStation(index) {
            if (!window.searchResults || !window.searchResults[index]) {
                return;
            }
            
            var results = document.querySelectorAll('.station-result');
            results.forEach(function(result) {
                result.classList.remove('selected');
            });
            
            if (results[index]) {
                results[index].classList.add('selected');
            }
            
            selectedStationData = window.searchResults[index];
            displaySelectedStation();
        }
        
        function displaySelectedStation() {
            if (!selectedStationData) {
                return;
            }
            
            var infoDiv = document.getElementById('selected_station_info');
            
            // Badge pro zdroj dat
            var sourceBadge = '';
            if (selectedStationData.data_source_badge) {
                var badgeColor = selectedStationData.data_source_color || '#666';
                sourceBadge = '<span style="background: ' + badgeColor + '; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 8px; font-weight: 600;">' + selectedStationData.data_source_badge + '</span>';
            }
            
            var html = '<div style="display: flex; align-items: center; justify-content: space-between;">';
            html += '<h4>' + selectedStationData.name + '</h4>';
            html += sourceBadge;
            html += '</div>';
            html += '<p><strong>Adresa:</strong> ' + (selectedStationData.address || 'N/A') + '</p>';
            html += '<p><strong>Souřadnice:</strong> ' + selectedStationData.lat + ', ' + selectedStationData.lng + '</p>';
            
            // ID podle zdroje dat
            if (selectedStationData.data_source === 'openchargemap') {
                html += '<p><strong>OCM ID:</strong> ' + (selectedStationData.openchargemap_id || 'N/A') + '</p>';
            } else if (selectedStationData.data_source === 'datex') {
                html += '<p><strong>DATEX ID:</strong> ' + (selectedStationData.datex_id || 'N/A') + '</p>';
            } else {
                html += '<p><strong>ID:</strong> ' + (selectedStationData.openchargemap_id || selectedStationData.datex_id || 'N/A') + '</p>';
            }
            
            if (selectedStationData.operator) {
                html += '<p><strong>Operátor:</strong> ' + (selectedStationData.operator.Title || 'N/A') + '</p>';
            }
            
            if (selectedStationData.connectors && selectedStationData.connectors.length > 0) {
                html += '<p><strong>Konektory:</strong></p><ul>';
                selectedStationData.connectors.forEach(function(connector) {
                    html += '<li>' + connector.type + ' - ' + connector.power_kw + ' kW (množství: ' + connector.quantity + ')</li>';
                });
                html += '</ul>';
            } else {
                html += '<p><strong>Konektory:</strong> N/A</p>';
            }
            
            if (selectedStationData.media && selectedStationData.media.length > 0) {
                html += '<p><strong>Obrázky (' + selectedStationData.media.length + '):</strong></p>';
                html += '<div style="display: flex; flex-wrap: wrap; gap: 10px;">';
                selectedStationData.media.forEach(function(media) {
                    if (media.thumbnail_url) {
                        html += '<img src="' + media.thumbnail_url + '" style="width: 100px; height: 100px; object-fit: cover; border: 1px solid #ddd;" title="' + media.comment + '" />';
                    }
                });
                html += '</div>';
            }
            
            infoDiv.innerHTML = html;
            document.getElementById('selected_station_section').style.display = 'block';
        }
        
        function applySelectedStation() {
            if (!selectedStationData) {
                alert('Není vybraná žádná stanice');
                return;
            }
            
            // 1. Vyplnit název v hlavním formuláři
            if (document.getElementById('title')) {
                document.getElementById('title').value = selectedStationData.name;
            }
            
            // 2. Vyplnit skrytá pole pro OCM data
            if (document.getElementById('_ocm_title')) {
                document.getElementById('_ocm_title').value = selectedStationData.name;
            }
            
            // 3. Vyplnit adresu
            if (document.getElementById('_db_address')) {
                document.getElementById('_db_address').value = selectedStationData.address || '';
            }
            
            // 4. Vyplnit souřadnice
            if (document.getElementById('_db_lat')) {
                document.getElementById('_db_lat').value = selectedStationData.lat || '';
            }
            if (document.getElementById('_db_lng')) {
                document.getElementById('_db_lng').value = selectedStationData.lng || '';
            }
            
            // 5. Vyplnit operátora do poskytovatele (pokud existuje)
            if (selectedStationData.operator && selectedStationData.operator.Title) {
                var providerSelect = document.getElementById('_db_provider');
                if (providerSelect) {
                    // Pokus o nalezení odpovídajícího poskytovatele
                    var operatorName = selectedStationData.operator.Title.toLowerCase();
                    var foundProvider = false;
                    
                    for (var i = 0; i < providerSelect.options.length; i++) {
                        var option = providerSelect.options[i];
                        if (option.text.toLowerCase().includes(operatorName) || 
                            operatorName.includes(option.text.toLowerCase())) {
                            providerSelect.value = option.value;
                            foundProvider = true;
                            break;
                        }
                    }
                    
                    // Pokud nenajdeme odpovídajícího poskytovatele, vytvoříme nového
                    if (!foundProvider && operatorName.trim() !== '') {
                        console.log('[CHARGING DEBUG] Vytvářím nového poskytovatele z operátora:', selectedStationData.operator.Title);
                        
                        // Vytvořit nového poskytovatele z operátora
                        jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                            action: 'db_create_provider_from_operator',
                            nonce: '<?php echo wp_create_nonce('db_create_provider_from_operator'); ?>',
                            operator_name: selectedStationData.operator.Title
                        }, function(response) {
                            console.log('[CHARGING DEBUG] AJAX response:', response);
                            
                            if (response.success && response.data && response.data.term_id) {
                                console.log('[CHARGING DEBUG] Nový poskytovatel vytvořen:', response.data.term_id);
                                
                                // Přidat novou možnost do selectu
                                var newOption = new Option(selectedStationData.operator.Title, response.data.term_id);
                                providerSelect.add(newOption);
                                
                                // Vybrat nově vytvořeného poskytovatele
                                providerSelect.value = response.data.term_id;
                                
                                // Aktualizovat zobrazení poskytovatele
                                try {
                                    updateProviderDisplay(response.data.term_id);
                                } catch (error) {
                                    console.error('[CHARGING DEBUG] Chyba při aktualizaci zobrazení poskytovatele:', error);
                                }
                                
                                // Zobrazit notifikaci
                                showNotification('Nový poskytovatel "' + selectedStationData.operator.Title + '" byl automaticky vytvořen.', 'success');
                            } else {
                                var errorMsg = 'Neznámá chyba';
                                if (response && response.data) {
                                    errorMsg = response.data.message || response.data || errorMsg;
                                }
                                console.error('[CHARGING DEBUG] Chyba při vytváření poskytovatele:', errorMsg);
                                showNotification('Chyba při vytváření poskytovatele: ' + errorMsg, 'error');
                            }
                        }).fail(function(xhr, status, error) {
                            console.error('[CHARGING DEBUG] AJAX selhal při vytváření poskytovatele:', {
                                status: status,
                                error: error,
                                responseText: xhr.responseText,
                                statusCode: xhr.status
                            });
                            showNotification('Chyba při vytváření poskytovatele - AJAX selhal: ' + error, 'error');
                        });
                    }
                }
                
                // Vyplnit operátora do pole operátora
                if (document.getElementById('_operator')) {
                    document.getElementById('_operator').value = selectedStationData.operator.Title;
                }
            }
            
            // 6. Vyplnit další informace o operátorovi
            if (document.getElementById('_openchargemap_id')) {
                document.getElementById('_openchargemap_id').value = selectedStationData.openchargemap_id || '';
            }
            
            if (document.getElementById('_usage_type') && selectedStationData.usage_type) {
                document.getElementById('_usage_type').value = selectedStationData.usage_type.Title || '';
            }
            
            if (document.getElementById('_status_type') && selectedStationData.status) {
                document.getElementById('_status_type').value = selectedStationData.status.Title || '';
            }
            
            if (document.getElementById('_data_provider') && selectedStationData.data_provider) {
                document.getElementById('_data_provider').value = selectedStationData.data_provider.Title || '';
            }
            
            if (document.getElementById('_data_quality')) {
                document.getElementById('_data_quality').value = selectedStationData.data_quality || '';
            }
            
            if (document.getElementById('_date_created')) {
                document.getElementById('_date_created').value = selectedStationData.date_created || '';
            }
            
            if (document.getElementById('_last_status_update')) {
                document.getElementById('_last_status_update').value = selectedStationData.last_status_update || '';
            }
            
            if (document.getElementById('_submission_status') && selectedStationData.submission_status) {
                document.getElementById('_submission_status').value = selectedStationData.submission_status.Title || '';
            }
            
            // 5. Vyplnit obrázek z OCM
            if (document.getElementById('_ocm_image_url')) {
                document.getElementById('_ocm_image_url').value = '';
                if (selectedStationData.media && selectedStationData.media.length > 0) {
                    document.getElementById('_ocm_image_url').value = selectedStationData.media[0].url || '';
                }
            }
            
            if (document.getElementById('_ocm_image_comment')) {
                document.getElementById('_ocm_image_comment').value = '';
                if (selectedStationData.media && selectedStationData.media.length > 0) {
                    document.getElementById('_ocm_image_comment').value = selectedStationData.media[0].comment || '';
                }
            }
            
            // 7. Vyplnit konektory z OCM dat
            if (selectedStationData.connectors && selectedStationData.connectors.length > 0) {
                console.log('[CHARGING DEBUG] Vyplňuji konektory:', selectedStationData.connectors);
                
                // Konektory se vyplní po AJAX uložení pomocí updateConnectorDisplay()
                // Tím se vyhneme duplicitní logice
            }
            
            // 7. Integrace s Gutenberg editorem
            if (typeof window.chargingOcmFill !== 'undefined') {
                window.chargingOcmFill.selectStation(selectedStationData);
            }
            
            // 7. Uložit data pro pozdější použití
            jQuery.post(ajaxurl, {
                action: 'save_selected_station_data',
                nonce: '<?php echo wp_create_nonce('db_save_station_data'); ?>',
                post_id: <?php echo $post->ID; ?>,
                station_data: selectedStationData
            }, function(response) {
                if (response.success) {
                    console.log('Data úspěšně uložena přes AJAX');
                    
                    // Zobrazit notifikaci místo alert
                    showNotification('Stanice byla úspěšně aplikována! Konektory a technické specifikace byly vyplněny.', 'success');
                    
                    // Aktualizovat UI - zobrazit detaily konektorů
                    setTimeout(function() {
                        updateConnectorDisplay();
                    }, 100);
                } else {
                    console.error('Chyba při AJAX ukládání: ' + response.data);
                    showNotification('Chyba při ukládání: ' + response.data, 'error');
                }
            });
        }
        
        function clearSelectedStation() {
            selectedStationData = null;
            document.getElementById('selected_station_section').style.display = 'none';
            document.getElementById('selected_station_info').innerHTML = '';
            
            var results = document.querySelectorAll('.station-result');
            results.forEach(function(result) {
                result.classList.remove('selected');
            });
        }
        
        /**
         * Aktualizuje zobrazení konektorů po načtení OCM dat
         */
        function updateConnectorDisplay() {
            if (!selectedStationData || !selectedStationData.connectors) {
                return;
            }
            
            console.log('[CHARGING DEBUG] Aktualizuji zobrazení konektorů:', selectedStationData.connectors);
            
            // Nejdříve skrýt všechny existující konektory
            document.querySelectorAll('.connector-item').forEach(function(item) {
                item.style.display = 'none';
            });
            
            // Pro každý konektor z OCM najít nebo vytvořit odpovídající element
            selectedStationData.connectors.forEach(function(connector, index) {
                var connectorType = connector.type;
                var connectorPower = connector.power_kw;
                var connectorQuantity = connector.quantity || 1;
                var connectorVoltage = connector.voltage || '';
                var connectorAmperage = connector.amperage || '';
                var connectorPhase = connector.phase || '';
                
                console.log('[CHARGING DEBUG] Zpracovávám konektor:', connectorType, 'výkon:', connectorPower, 'počet:', connectorQuantity);
                
                // Najít existující konektor podle typu nebo vytvořit nový
                var existingConnector = findConnectorByType(connectorType);
                if (existingConnector) {
                    // Použít existující konektor
                    var connectorItem = existingConnector;
                    connectorItem.style.display = 'block';
                    
                    // Zaškrtnout checkbox
                    var checkbox = connectorItem.querySelector('input[name="charger_type[]"]');
                    if (checkbox) {
                        checkbox.checked = true;
                        checkbox.value = connectorType; // Použít název typu místo ID
                    }
                    
                    // Zobrazit detaily
                    var detailsDiv = connectorItem.querySelector('.connector-details');
                    if (detailsDiv) {
                        detailsDiv.style.display = 'block';
                    }
                    
                    // Vyplnit počet konektorů
                    var countInput = connectorItem.querySelector('input[name^="charger_count"]');
                    if (countInput) {
                        countInput.disabled = false;
                        countInput.value = connectorQuantity;
                        countInput.name = 'charger_count[' + connectorType + ']';
                    }
                    
                    // Vyplnit stav
                    var statusSelect = connectorItem.querySelector('select[name^="charger_status"]');
                    if (statusSelect) {
                        statusSelect.disabled = false;
                        statusSelect.name = 'charger_status[' + connectorType + ']';
                    }
                    
                    // Vyplnit technické specifikace
                    var powerInput = connectorItem.querySelector('input[name^="charger_power"]');
                    if (powerInput && connectorPower) {
                        powerInput.disabled = false;
                        powerInput.value = connectorPower;
                        powerInput.name = 'charger_power[' + connectorType + ']';
                    }
                    
                    var voltageInput = connectorItem.querySelector('input[name^="charger_voltage"]');
                    if (voltageInput && connectorVoltage) {
                        voltageInput.disabled = false;
                        voltageInput.value = connectorVoltage;
                        voltageInput.name = 'charger_voltage[' + connectorType + ']';
                    }
                    
                    var amperageInput = connectorItem.querySelector('input[name^="charger_amperage"]');
                    if (amperageInput && connectorAmperage) {
                        amperageInput.disabled = false;
                        amperageInput.value = connectorAmperage;
                        amperageInput.name = 'charger_amperage[' + connectorType + ']';
                    }
                    
                    var phaseSelect = connectorItem.querySelector('select[name^="charger_phase"]');
                    if (phaseSelect && connectorPhase) {
                        phaseSelect.disabled = false;
                        phaseSelect.value = connectorPhase;
                        phaseSelect.name = 'charger_phase[' + connectorType + ']';
                    }
                    
                    console.log('[CHARGING DEBUG] Konektor vyplněn:', connectorType, 'počet:', connectorQuantity, 'výkon:', connectorPower);
                } else {
                    // Vytvořit nový konektor dynamicky
                    createDynamicConnector(connector, index);
                }
            });
            
            // Aktualizovat celkový počet stanic
            updateTotalStationsFromOCM();
        }
        
        /**
         * Najde konektor podle typu
         */
        function findConnectorByType(connectorType) {
            var connectorItems = document.querySelectorAll('.connector-item');
            for (var i = 0; i < connectorItems.length; i++) {
                var item = connectorItems[i];
                var nameElement = item.querySelector('h5');
                if (nameElement && nameElement.textContent.toLowerCase().includes(connectorType.toLowerCase())) {
                    return item;
                }
            }
            return null;
        }
        
        /**
         * Vytvoří nový konektor dynamicky
         */
        function createDynamicConnector(connector, index) {
            var connectorType = connector.type;
            var connectorPower = connector.power_kw;
            var connectorQuantity = connector.quantity || 1;
            var connectorVoltage = connector.voltage || '';
            var connectorAmperage = connector.amperage || '';
            var connectorPhase = connector.phase || '';
            
            console.log('[CHARGING DEBUG] Vytvářím nový konektor:', connectorType);
            
            var connectorsList = document.querySelector('.connectors-list');
            if (!connectorsList) return;
            
            var connectorHTML = `
                <div class="connector-item" style="margin-bottom: 20px; padding: 20px; background: #fff; border: 1px solid #e1e5e9; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                    <div class="connector-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                        <div class="connector-icon" style="margin-right: 15px;">
                            <div style="width: 40px; height: 40px; border-radius: 50%; border: 2px solid #e1e5e9; background: #f8f9fa; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 20px;">
                                ⚡
                            </div>
                        </div>
                        <div class="connector-info" style="flex: 1;">
                            <h5 style="margin: 0 0 5px 0; font-size: 16px; color: #23282d;">
                                ${connectorType}
                            </h5>
                            <span style="color: #6c757d; font-size: 14px;">
                                ${connectorPower ? connectorPower + ' kW' : 'N/A'}
                            </span>
                        </div>
                        <div class="connector-checkbox" style="margin-left: 15px;">
                            <label style="display: flex; align-items: center; cursor: pointer;">
                                <input type="checkbox" name="charger_type[]" value="${connectorType}" 
                                       checked class="db-charger-type-checkbox"
                                       style="margin-right: 8px; transform: scale(1.2);" />
                                <span style="font-weight: 500; color: #23282d;">Vybrat</span>
                            </label>
                        </div>
                    </div>
                    <div class="connector-details" style="display: block; margin-left: 55px;">
                        <div class="basic-info" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                            <div class="form-group">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #23282d;">
                                    Počet konektorů
                                </label>
                                <input type="number" name="charger_count[${connectorType}]" 
                                       min="1" value="${connectorQuantity}" 
                                       style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" 
                                       placeholder="např. 2" />
                            </div>
                            <div class="form-group">
                                <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #23282d;">
                                    Stav
                                </label>
                                <select name="charger_status[${connectorType}]" 
                                        style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                    <option value="operational" selected>Operational</option>
                                    <option value="non_operational">Non-Operational</option>
                                    <option value="under_construction">Under Construction</option>
                                    <option value="planned">Planned</option>
                                </select>
                            </div>
                        </div>
                        <div class="technical-specs" style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef;">
                            <h6 style="margin: 0 0 15px 0; color: #495057; font-size: 14px; font-weight: 600;">
                                ⚡ Technické specifikace
                            </h6>
                            <div class="specs-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                                <div class="spec-item">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                        Výkon (kW)
                                    </label>
                                    <input type="number" name="charger_power[${connectorType}]" 
                                           min="1" step="1" value="${connectorPower || ''}" 
                                           style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;" 
                                           placeholder="např. 50" />
                                </div>
                                <div class="spec-item">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                        Napětí (V)
                                    </label>
                                    <input type="number" name="charger_voltage[${connectorType}]" 
                                           min="1" step="1" value="${connectorVoltage || ''}" 
                                           style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;" 
                                           placeholder="např. 400" />
                                </div>
                                <div class="spec-item">
                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                        Proud (A)
                                    </label>
                                    <input type="number" name="charger_amperage[${connectorType}]" 
                                           min="1" step="1" value="${connectorAmperage || ''}" 
                                           style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;" 
                                           placeholder="např. 63" />
                                </div>
                            </div>
                            <div style="margin-top: 15px; padding: 10px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; font-size: 12px; color: #004085;">
                                <strong>💡 Tip:</strong> Tyto hodnoty se automaticky vyplnily z OCM API. 
                                Můžete je upravit podle skutečného stavu na místě.
                            </div>
                        </div>
                    </div>
                </div>
            `;
            
            connectorsList.insertAdjacentHTML('beforeend', connectorHTML);
            
            // Přidat event listener pro nový checkbox
            var newCheckbox = connectorsList.lastElementChild.querySelector('.db-charger-type-checkbox');
            if (newCheckbox) {
                newCheckbox.addEventListener('change', function() {
                    handleConnectorCheckboxChange(this);
                });
            }
        }
        
        /**
         * Aktualizuje celkový počet stanic z OCM dat
         */
        function updateTotalStationsFromOCM() {
            if (!selectedStationData || !selectedStationData.connectors) {
                return;
            }
            
            var totalConnectors = selectedStationData.connectors.reduce(function(total, connector) {
                return total + (connector.quantity || 1);
            }, 0);
            
            var totalInput = document.getElementById('_db_total_stations');
            if (totalInput) {
                totalInput.value = totalConnectors;
                console.log('[CHARGING DEBUG] Celkový počet stanic aktualizován z OCM:', totalConnectors);
            }
        }
        
        /**
         * Handler pro změnu checkboxu konektoru
         */
        function handleConnectorCheckboxChange(checkbox) {
            var connectorItem = checkbox.closest('.connector-item');
            var detailsDiv = connectorItem.querySelector('.connector-details');
            var countInput = connectorItem.querySelector('input[name^="charger_count"]');
            var statusSelect = connectorItem.querySelector('select[name^="charger_status"]');
            
            if (checkbox.checked) {
                // Zobrazit detaily
                detailsDiv.style.display = 'block';
                
                // Povolit pole
                if (countInput) countInput.disabled = false;
                if (statusSelect) statusSelect.disabled = false;
                
                // Povolit všechna technická pole
                var techInputs = detailsDiv.querySelectorAll('input[type="number"], select');
                techInputs.forEach(function(input) {
                    input.disabled = false;
                });
            } else {
                // Skrýt detaily
                detailsDiv.style.display = 'none';
                
                // Zakázat a vyčistit pole
                if (countInput) {
                    countInput.disabled = true;
                    countInput.value = '';
                }
                if (statusSelect) {
                    statusSelect.disabled = true;
                    statusSelect.selectedIndex = 0;
                }
                
                // Zakázat a vyčistit technická pole
                var techInputs = detailsDiv.querySelectorAll('input[type="number"], select');
                techInputs.forEach(function(input) {
                    input.disabled = true;
                    if (input.type === 'number') {
                        input.value = '';
                    } else {
                        input.selectedIndex = 0;
                    }
                });
            }
        }
        
        // Funkce showNotification je nyní definována globálně v render_ocm_search_box()
        </script>
        <?php
    }

    /**
     * Render meta boxu pro základní informace
     */
    public function render_basic_info_box($post) {
        $address = get_post_meta($post->ID, '_db_address', true);
        $lat = get_post_meta($post->ID, '_db_lat', true);
        $lng = get_post_meta($post->ID, '_db_lng', true);
        $mpo_uniq_key = get_post_meta($post->ID, '_mpo_uniq_key', true);
        $mpo_opening_hours = get_post_meta($post->ID, '_mpo_opening_hours', true);
        $data_source_val = get_post_meta($post->ID, '_data_source', true);
        $mpo_connectors_raw = get_post_meta($post->ID, '_mpo_connectors', true);
        $mpo_connectors_json = is_array($mpo_connectors_raw) ? wp_json_encode($mpo_connectors_raw) : '';
        $provider_terms = wp_get_post_terms($post->ID, 'provider', array('fields'=>'ids'));
        $provider_selected = !empty($provider_terms) ? $provider_terms[0] : '';
        $price = get_post_meta($post->ID, '_db_price', true);
        $charger_note = get_post_meta($post->ID, '_db_charger_note', true);
        $db_recommended = get_post_meta($post->ID, '_db_recommended', true) === '1';
        
        $provider_terms_all = get_terms(array('taxonomy'=>'provider','hide_empty'=>false));
        $prices = array(
            'free' => __('Zdarma', 'dobity-baterky'),
            'paid' => __('Placené', 'dobity-baterky'),
        );
        ?>
        
        <!-- Skrytá pole pro OCM data -->
        <input type="hidden" name="_ocm_title" id="_ocm_title" value="<?php echo esc_attr(get_post_meta($post->ID, '_ocm_title', true)); ?>" />
        <input type="hidden" name="_ocm_image_url" id="_ocm_image_url" value="<?php echo esc_attr(get_post_meta($post->ID, '_ocm_image_url', true)); ?>" />
        <input type="hidden" name="_ocm_image_comment" id="_ocm_image_comment" value="<?php echo esc_attr(get_post_meta($post->ID, '_ocm_image_comment', true)); ?>" />
        
        <table class="form-table">
            <tr>
                <th><label for="_db_address"><?php esc_html_e('Adresa', 'dobity-baterky'); ?></label></th>
                <td><input type="text" name="_db_address" id="_db_address" value="<?php echo esc_attr($address); ?>" class="regular-text" /></td>
                <td rowspan="5" style="vertical-align:top;min-width:350px;">
                    <div id="db-admin-map-charging" style="width:350px;height:300px;margin-left:20px;"></div>
                    <div style="margin-top:10px;">
                        <button type="button" class="button" id="db-ocm-enrich-btn">Doplnit chybějící data z OCM</button>
                        <div id="db-ocm-enrich-results" style="margin-top:10px;"></div>
                    </div>
                </td>
            </tr>
            <tr>
                <th><label for="_db_recommended">DB doporučuje</label></th>
                <td>
                    <label><input type="checkbox" name="_db_recommended" id="_db_recommended" value="1" <?php checked($db_recommended); ?> /> Zobrazit na mapě jako doporučené (zvýraznit pin logem)</label>
                </td>
            </tr>
            <tr>
                <th><label for="_db_lat"><?php esc_html_e('Zeměpisná šířka', 'dobity-baterky'); ?></label></th>
                <td><input type="number" step="any" name="_db_lat" id="_db_lat" value="<?php echo esc_attr($lat); ?>" /></td>
            </tr>
            <tr>
                <th><label for="_db_lng"><?php esc_html_e('Zeměpisná délka', 'dobity-baterky'); ?></label></th>
                <td><input type="number" step="any" name="_db_lng" id="_db_lng" value="<?php echo esc_attr($lng); ?>" /></td>
            </tr>
            <tr>
                <th><label for="_mpo_opening_hours"><?php esc_html_e('Provozní doba (MPO)', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_mpo_opening_hours" id="_mpo_opening_hours" value="<?php echo esc_attr($mpo_opening_hours); ?>" class="regular-text" placeholder="např. 24/7 nebo Otevírací doba" />
                    <p class="description"><?php esc_html_e('Nahrává se z MPO JSON. Můžete ručně upravit.', 'dobity-baterky'); ?></p>
                </td>
            </tr>
            <tr>
                <th><label for="_mpo_uniq_key"><?php esc_html_e('MPO UID', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_mpo_uniq_key" id="_mpo_uniq_key" class="regular-text" value="<?php echo esc_attr($mpo_uniq_key); ?>" placeholder="např. op_key|49.12345|16.54321" />
                    <p class="description"><?php esc_html_e('Používá se pro párování s MPO JSON a prevenci duplicit při importu.', 'dobity-baterky'); ?></p>
                </td>
            </tr>
            <!-- Debug hidden fields -->
            <tr style="display:none;">
                <td colspan="2">
                    <input type="hidden" name="_data_source" id="_data_source" value="<?php echo esc_attr($data_source_val); ?>" />
                    <textarea id="_mpo_connectors_json" style="display:none;"><?php echo esc_textarea($mpo_connectors_json); ?></textarea>
                </td>
            </tr>
            <tr>
                <th><label for="_db_provider"><?php esc_html_e('Poskytovatel', 'dobity-baterky'); ?></label></th>
                <td>
                    <select name="_db_provider" id="_db_provider">
                        <option value=""><?php esc_html_e('-- Vyberte poskytovatele --', 'dobity-baterky'); ?></option>
                        <?php foreach ($provider_terms_all as $term) : 
                            $friendly_name = get_term_meta($term->term_id, 'provider_friendly_name', true);
                            $display_name = !empty($friendly_name) ? $friendly_name : $term->name;
                        ?>
                            <option value="<?php echo esc_attr($term->term_id); ?>" <?php selected($provider_selected, $term->term_id); ?>><?php echo esc_html($display_name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    
                    <!-- Přidání nového poskytovatele -->
                    <div style="margin-top: 10px;">
                        <button type="button" class="button" id="add_new_provider_btn">+ Přidat nového poskytovatele</button>
                    </div>
                    
                    <!-- Formulář pro nového poskytovatele (skrytý) -->
                    <div id="new_provider_form" style="display: none; margin-top: 15px; padding: 15px; background: #f9f9f9; border: 1px solid #ddd; border-radius: 4px;">
                        <h4><?php esc_html_e('Nový poskytovatel', 'dobity-baterky'); ?></h4>
                        <table class="form-table">
                            <tr>
                                <th><label for="new_provider_name"><?php esc_html_e('Název', 'dobity-baterky'); ?></label></th>
                                <td><input type="text" id="new_provider_name" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="new_provider_friendly"><?php esc_html_e('Friendly název', 'dobity-baterky'); ?></label></th>
                                <td><input type="text" id="new_provider_friendly" class="regular-text" /></td>
                            </tr>
                            <tr>
                                <th><label for="new_provider_logo"><?php esc_html_e('Logo', 'dobity-baterky'); ?></label></th>
                                <td>
                                    <input type="text" id="new_provider_logo" class="regular-text" />
                                    <button type="button" class="button" id="upload_new_logo_btn"><?php esc_html_e('Vybrat obrázek', 'dobity-baterky'); ?></button>
                                </td>
                            </tr>
                        </table>
                        <div style="margin-top: 10px;">
                            <button type="button" class="button button-primary" id="save_new_provider_btn"><?php esc_html_e('Uložit poskytovatele', 'dobity-baterky'); ?></button>
                            <button type="button" class="button" id="cancel_new_provider_btn"><?php esc_html_e('Zrušit', 'dobity-baterky'); ?></button>
                        </div>
                    </div>
                    
                    <!-- Zobrazení vybraného poskytovatele s logem -->
                    <?php if ($provider_selected) : 
                        $selected_provider = get_term($provider_selected, 'provider');
                        if ($selected_provider && !is_wp_error($selected_provider)) :
                            $friendly_name = get_term_meta($provider_selected, 'provider_friendly_name', true);
                            $logo = get_term_meta($provider_selected, 'provider_logo', true);
                            $display_name = !empty($friendly_name) ? $friendly_name : $selected_provider->name;
                    ?>
                        <div style="margin-top: 10px; padding: 10px; background: #f0f8ff; border: 1px solid #b3d9ff; border-radius: 4px;">
                            <strong><?php esc_html_e('Vybraný poskytovatel:', 'dobity-baterky'); ?></strong> <?php echo esc_html($display_name); ?>
                            <?php if ($logo) : ?>
                                <br><img src="<?php echo esc_url($logo); ?>" style="max-width: 50px; max-height: 50px; margin-top: 5px; border: 1px solid #ddd;" />
                            <?php endif; ?>
                        </div>
                    <?php 
                        endif;
                    endif; ?>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e('Cena', 'dobity-baterky'); ?></th>
                <td>
                    <?php foreach ($prices as $key => $label) : ?>
                        <label><input type="radio" name="_db_price" value="<?php echo esc_attr($key); ?>" <?php checked($price, $key); ?> /> <?php echo esc_html($label); ?></label><br />
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th><label for="_db_charger_note"><?php esc_html_e('Poznámky a tipy', 'dobity-baterky'); ?></label></th>
                <td><textarea name="_db_charger_note" id="_db_charger_note" rows="3" style="width:100%;" placeholder="Sdílejte své zkušenosti a tipy pro ostatní cestovatele..."><?php echo esc_textarea($charger_note); ?></textarea></td>
            </tr>
        </table>
        
        <!-- JavaScript pro správu poskytovatelů -->
        <script>
        jQuery(document).ready(function($) {
            
            // Helper funkce pro aktualizaci zobrazení poskytovatele
            function updateProviderDisplay(termId) {
                console.log('[CHARGING DEBUG] Aktualizuji zobrazení poskytovatele pro term_id:', termId);
                
                if (!termId || isNaN(termId)) {
                    console.error('[CHARGING DEBUG] Neplatné term_id:', termId);
                    return;
                }
                
                // Aktualizovat zobrazení vybraného poskytovatele
                var providerDisplay = jQuery('.postbox').find('[style*="background: #f0f8ff"]');
                if (providerDisplay.length === 0) {
                    console.warn('[CHARGING DEBUG] Nenalezen provider display element');
                    return;
                }
                
                // Získat informace o poskytovateli
                jQuery.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'db_get_provider_info',
                    nonce: '<?php echo wp_create_nonce('db_get_provider_info'); ?>',
                    term_id: termId
                }, function(response) {
                    console.log('[CHARGING DEBUG] Provider info response:', response);
                    
                    if (response.success && response.data) {
                        var provider = response.data;
                        var displayName = provider.friendly_name || provider.name;
                        
                        // Aktualizovat text
                        var textElement = providerDisplay.find('strong').next();
                        if (textElement.length > 0) {
                            textElement.text(displayName);
                        }
                        
                        // Aktualizovat logo
                        var logoImg = providerDisplay.find('img');
                        if (provider.logo) {
                            if (logoImg.length > 0) {
                                logoImg.attr('src', provider.logo);
                            } else {
                                providerDisplay.append('<br><img src="' + provider.logo + '" style="max-width: 50px; max-height: 50px; margin-top: 5px; border: 1px solid #ddd;" />');
                            }
                        } else {
                            logoImg.remove();
                        }
                    } else {
                        console.error('[CHARGING DEBUG] Chyba při získávání informací o poskytovateli:', response);
                    }
                }).fail(function(xhr, status, error) {
                    console.error('[CHARGING DEBUG] AJAX selhal při získávání informací o poskytovateli:', {
                        status: status,
                        error: error,
                        responseText: xhr.responseText,
                        statusCode: xhr.status
                    });
                });
            }
            
            // Helper funkce pro zobrazení notifikací
            function showNotification(message, type) {
                var notificationClass = type === 'success' ? 'notice notice-success' : 'notice notice-error';
                var notification = jQuery('<div class="' + notificationClass + ' is-dismissible"><p>' + message + '</p></div>');
                
                // Přidat notifikaci na začátek formuláře
                jQuery('.postbox:first').before(notification);
                
                // Automaticky skrýt po 5 sekundách
                setTimeout(function() {
                    notification.fadeOut();
                }, 5000);
                
                // Možnost manuálně zavřít
                notification.append('<button type="button" class="notice-dismiss"><span class="screen-reader-text">Zavřít toto oznámení.</span></button>');
                notification.find('.notice-dismiss').click(function() {
                    notification.fadeOut();
                });
            }
            // Tlačítko pro přidání nového poskytovatele
            $('#add_new_provider_btn').click(function() {
                $('#new_provider_form').show();
                $(this).hide();
            });
            
            // Tlačítko pro zrušení
            $('#cancel_new_provider_btn').click(function() {
                $('#new_provider_form').hide();
                $('#add_new_provider_btn').show();
                $('#new_provider_name, #new_provider_friendly, #new_provider_logo').val('');
            });
            
            // Nahrávání loga pro nového poskytovatele
            $('#upload_new_logo_btn').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'Vybrat logo poskytovatele',
                    multiple: false
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#new_provider_logo').val(image_url);
                });
            });
            
            // Uložení nového poskytovatele
            $('#save_new_provider_btn').click(function() {
                var name = $('#new_provider_name').val().trim();
                var friendly = $('#new_provider_friendly').val().trim();
                var logo = $('#new_provider_logo').val().trim();
                
                if (!name) {
                    alert('Název poskytovatele je povinný');
                    return;
                }
                
                // AJAX požadavek pro vytvoření poskytovatele
                $.post('<?php echo admin_url('admin-ajax.php'); ?>', {
                    action: 'db_add_provider',
                    nonce: '<?php echo wp_create_nonce('db_add_provider'); ?>',
                    name: name,
                    friendly_name: friendly,
                    logo: logo
                }, function(response) {
                    if (response.success) {
                        // Přidání nového poskytovatele do selectu
                        var option = new Option(friendly || name, response.data.term_id);
                        $('#_db_provider').append(option);
                        $('#_db_provider').val(response.data.term_id);
                        
                        // Skrytí formuláře
                        $('#new_provider_form').hide();
                        $('#add_new_provider_btn').show();
                        $('#new_provider_name, #new_provider_friendly, #new_provider_logo').val('');
                        
                        // Obnovení stránky pro zobrazení nového poskytovatele
                        location.reload();
                    } else {
                        alert('Chyba při vytváření poskytovatele: ' + response.data);
                    }
                }).fail(function() {
                    alert('Chyba při komunikaci se serverem');
                });
            });
        });
        </script>
        
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var latInput = document.getElementById('_db_lat');
            var lngInput = document.getElementById('_db_lng');
            if (!latInput || !lngInput) return;
            
            // Kontrola, zda mapa již není inicializována
            var mapContainer = document.getElementById('db-admin-map-charging');
            if (mapContainer._leaflet_id) {
                return; // Mapa již existuje
            }
            
            var map = L.map('db-admin-map-charging').setView([
                latInput.value ? parseFloat(latInput.value) : 50.08,
                lngInput.value ? parseFloat(lngInput.value) : 14.42
            ], 13);
            
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
            
            var marker = L.marker([
                latInput.value ? parseFloat(latInput.value) : 50.08,
                lngInput.value ? parseFloat(lngInput.value) : 14.42
            ], {draggable:true}).addTo(map);
            
            setTimeout(function() { map.invalidateSize(); }, 200);
            
            marker.on('dragend', function(e) {
                var pos = marker.getLatLng();
                latInput.value = pos.lat.toFixed(7);
                lngInput.value = pos.lng.toFixed(7);
            });
            
            function updateMarker() {
                var lat = parseFloat(latInput.value);
                var lng = parseFloat(lngInput.value);
                if (!isNaN(lat) && !isNaN(lng)) {
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng]);
                }
            }
            
            latInput.addEventListener('change', updateMarker);
            lngInput.addEventListener('change', updateMarker);
            
            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                latInput.value = e.latlng.lat.toFixed(7);
                lngInput.value = e.latlng.lng.toFixed(7);
            });
        });
        </script>
        <?php
    }

    /**
     * Render meta boxu pro informace o operátorovi
     */
    public function render_operator_info_box($post) {
        $operator = get_post_meta($post->ID, '_operator', true);
        $openchargemap_id = get_post_meta($post->ID, '_openchargemap_id', true);
        $data_provider = get_post_meta($post->ID, '_data_provider', true);
        $data_quality = get_post_meta($post->ID, '_data_quality', true);
        $date_created = get_post_meta($post->ID, '_date_created', true);
        $last_status_update = get_post_meta($post->ID, '_last_status_update', true);
        $usage_type = get_post_meta($post->ID, '_usage_type', true);
        $status_type = get_post_meta($post->ID, '_status_type', true);
        $submission_status = get_post_meta($post->ID, '_submission_status', true);
        ?>
        
        <table class="form-table">
            <tr>
                <th><label for="_operator"><?php esc_html_e('Operátor stanice', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_operator" id="_operator" value="<?php echo esc_attr($operator); ?>" class="regular-text" />
                    <p class="description">Název společnosti provozující nabíjecí stanici</p>
                </td>
            </tr>
            <tr>
                <th><label for="_openchargemap_id"><?php esc_html_e('OpenChargeMap ID', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_openchargemap_id" id="_openchargemap_id" value="<?php echo esc_attr($openchargemap_id); ?>" class="regular-text" readonly />
                    <p class="description">Unikátní identifikátor stanice v OpenChargeMap databázi</p>
                </td>
            </tr>
            <tr>
                <th><label for="_usage_type"><?php esc_html_e('Typ použití', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_usage_type" id="_usage_type" value="<?php echo esc_attr($usage_type); ?>" class="regular-text" />
                    <p class="description">Typ použití stanice (veřejná, soukromá, firemní, atd.)</p>
                </td>
            </tr>
            <tr>
                <th><label for="_status_type"><?php esc_html_e('Stav stanice', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_status_type" id="_status_type" value="<?php echo esc_attr($status_type); ?>" class="regular-text" />
                    <p class="description">Aktuální stav stanice (funkční, nefunkční, ve výstavbě, atd.)</p>
                </td>
            </tr>
            <tr>
                <th><label for="_data_provider"><?php esc_html_e('Zdroj dat', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_data_provider" id="_data_provider" value="<?php echo esc_attr($data_provider); ?>" class="regular-text" readonly />
                    <p class="description">Původní zdroj dat v OpenChargeMap</p>
                </td>
            </tr>
            <tr>
                <th><label for="_data_quality"><?php esc_html_e('Kvalita dat', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_data_quality" id="_data_quality" value="<?php echo esc_attr($data_quality); ?>" class="small-text" readonly />
                    <p class="description">Hodnocení kvality dat (1-5, kde 5 je nejlepší)</p>
                </td>
            </tr>
            <tr>
                <th><label for="_date_created"><?php esc_html_e('Datum vytvoření', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_date_created" id="_date_created" value="<?php echo esc_attr($date_created); ?>" class="regular-text" readonly />
                    <p class="description">Datum vytvoření záznamu v OpenChargeMap</p>
                </td>
            </tr>
            <tr>
                <th><label for="_last_status_update"><?php esc_html_e('Poslední aktualizace', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_last_status_update" id="_last_status_update" value="<?php echo esc_attr($last_status_update); ?>" class="regular-text" readonly />
                    <p class="description">Datum poslední aktualizace stavu stanice</p>
                </td>
            </tr>
            <tr>
                <th><label for="_submission_status"><?php esc_html_e('Stav schválení', 'dobity-baterky'); ?></label></th>
                <td>
                    <input type="text" name="_submission_status" id="_submission_status" value="<?php echo esc_attr($submission_status); ?>" class="regular-text" readonly />
                    <p class="description">Stav schválení záznamu v OpenChargeMap</p>
                </td>
            </tr>
        </table>
        <?php
    }

    /**
     * Render meta boxu pro technické údaje (OCM-style)
     */
    public function render_technical_box($post) {
        $selected_types = wp_get_post_terms($post->ID, 'charger_type', array('fields'=>'ids'));
        $terms = get_terms(array('taxonomy'=>'charger_type','hide_empty'=>false));
        $charger_counts = get_post_meta($post->ID, '_db_charger_counts', true);
        $charger_status = get_post_meta($post->ID, '_db_charger_status', true);
        $charger_power = get_post_meta($post->ID, '_db_charger_power', true);
        $charger_phase = get_post_meta($post->ID, '_db_charger_phase', true);
        $charger_voltage = get_post_meta($post->ID, '_db_charger_voltage', true);
        $charger_amperage = get_post_meta($post->ID, '_db_charger_amperage', true);
        $total_stations = get_post_meta($post->ID, '_db_total_stations', true);
        $ocm_connector_names = get_post_meta($post->ID, '_ocm_connector_names', true);
        $data_source = get_post_meta($post->ID, '_data_source', true);
        
        if (!is_array($charger_counts)) $charger_counts = array();
        if (!is_array($charger_status)) $charger_status = array();
        if (!is_array($charger_power)) $charger_power = array();
        if (!is_array($charger_phase)) $charger_phase = array();
        if (!is_array($charger_voltage)) $charger_voltage = array();
        if (!is_array($charger_amperage)) $charger_amperage = array();
        if (!is_array($ocm_connector_names)) $ocm_connector_names = array();
        
        // Kombinovat taxonomie a OCM konektory bez duplikátů
        $all_connectors = array();
        $used_names = array();
        
        // Přidat konektory z externího zdroje (OCM nebo MPO)
        if (!empty($ocm_connector_names)) {
            foreach ($ocm_connector_names as $connector_name) {
                // Pokusit se najít odpovídající taxonomii pro ikonu a typ proudu
                $matching_term = null;
                $connector_icon = '';
                $connector_current_type = 'N/A';
                
                if (!empty($terms) && !is_wp_error($terms)) {
                    foreach ($terms as $term) {
                        $term_name_lower = strtolower(trim($term->name));
                        $connector_name_lower = strtolower(trim($connector_name));
                        
                        // Kontrola shody názvů - pouze přesné nebo velmi podobné
                        $is_match = false;
                        
                        // 1. Přesná shoda (nejlepší)
                        if ($term_name_lower === $connector_name_lower) {
                            $is_match = true;
                            error_log('[CHARGING DEBUG] Přesná shoda: ' . $connector_name . ' = ' . $term->name);
                        }
                        // 2. OCM název je obsažen v taxonomii (např. "Type 2" v "Type 2 (Socket Only)")
                        elseif (strpos($term_name_lower, $connector_name_lower) !== false) {
                            $is_match = true;
                            error_log('[CHARGING DEBUG] OCM v taxonomii: ' . $connector_name . ' -> ' . $term->name);
                        }
                        // 3. Taxonomie je obsažena v OCM (např. "CCS" v "CCS (Type 2)")
                        elseif (strpos($connector_name_lower, $term_name_lower) !== false) {
                            $is_match = true;
                            error_log('[CHARGING DEBUG] Taxonomie v OCM: ' . $connector_name . ' -> ' . $term->name);
                        }
                        
                        // 4. Speciální případy - velmi podobné názvy
                        elseif (($term_name_lower === 'type 2' && $connector_name_lower === 'type 2 (socket only)') ||
                                 ($term_name_lower === 'type 2 (socket only)' && $connector_name_lower === 'type 2') ||
                                 ($term_name_lower === 'ccs' && $connector_name_lower === 'ccs (type 2)') ||
                                 ($term_name_lower === 'ccs (type 2)' && $connector_name_lower === 'ccs')) {
                            $is_match = true;
                            error_log('[CHARGING DEBUG] Speciální shoda: ' . $connector_name . ' -> ' . $term->name);
                        }
                        
                        if ($is_match) {
                            $matching_term = $term;
                            break;
                        }
                    }
                }
                
                // Pokud se našla shoda, použít ikonu a typ proudu z taxonomie
                if ($matching_term) {
                    $connector_icon = get_term_meta($matching_term->term_id, 'charger_icon', true);
                    $connector_current_type = get_term_meta($matching_term->term_id, 'charger_current_type', true);
                    error_log('[CHARGING DEBUG] Nalezena shoda pro OCM konektor: ' . $connector_name . ' -> ' . $matching_term->name);
                }
                
                $all_connectors[] = array(
                    'type' => ($data_source === 'mpo' ? 'mpo' : 'ocm'),
                    'id' => $connector_name,
                    'name' => $connector_name,
                    'current_type' => $connector_current_type,
                    'icon' => $connector_icon
                );
                $used_names[] = strtolower(trim($connector_name));
            }
        }
        
        // Přidat VŠECHNY dostupné taxonomie (ne jen ty přiřazené), aby bylo možné vytvářet místa manuálně
        // Pokud jsou přiřazené taxonomie, zobrazíme je jako zaškrtnuté, jinak všechny jako nezaškrtnuté
        if (!empty($terms) && !is_wp_error($terms)) {
            foreach ($terms as $term) {
                $term_name_lower = strtolower(trim($term->name));
                
                // Kontrola, jestli už není OCM konektor se stejným názvem
                $is_duplicate = false;
                foreach ($used_names as $used_name) {
                    // Přesnější kontrola duplikátů - konzistentní s logikou párování
                    if ($term_name_lower === $used_name) {
                        $is_duplicate = true;
                        error_log('[CHARGING DEBUG] Duplikát - přesná shoda: ' . $term->name . ' = ' . $used_name);
                        break;
                    }
                    // Kontrola, jestli je taxonomie obsažena v OCM názvu
                    elseif (strpos($used_name, $term_name_lower) !== false) {
                        $is_duplicate = true;
                        error_log('[CHARGING DEBUG] Duplikát - taxonomie v OCM: ' . $term->name . ' -> ' . $used_name);
                        break;
                    }
                    // Kontrola, jestli je OCM název obsažen v taxonomii
                    elseif (strpos($term_name_lower, $used_name) !== false) {
                        $is_duplicate = true;
                        error_log('[CHARGING DEBUG] Duplikát - OCM v taxonomii: ' . $term->name . ' -> ' . $used_name);
                        break;
                    }
                }
                
                // Přidat pouze pokud není duplikát
                if (!$is_duplicate) {
                    $all_connectors[] = array(
                        'type' => 'taxonomy',
                        'id' => $term->term_id,
                        'name' => $term->name,
                        'current_type' => get_term_meta($term->term_id, 'charger_current_type', true),
                        'icon' => get_term_meta($term->term_id, 'charger_icon', true)
                    );
                }
            }
        }
        
        error_log('[CHARGING DEBUG] Načteno konektorů: ' . count($all_connectors) . ' (OCM: ' . count($ocm_connector_names) . ', Taxonomie: ' . (count($all_connectors) - count($ocm_connector_names)) . ')');
        
        ?>
        
        <div class="technical-data-container">
            <!-- Hlavní nadpis -->
            <h3 style="margin: 0 0 20px 0; color: #23282d; font-size: 18px;">Technické údaje</h3>
            
            <!-- Celkový počet stanic -->
            <div class="total-stations-section" style="margin-bottom: 25px;">
                <label for="_db_total_stations" style="display: block; font-weight: bold; margin-bottom: 8px; color: #23282d;">
                    Počet stanic/bayů
                </label>
                <input type="number" name="_db_total_stations" id="_db_total_stations" min="1" 
                       value="<?php echo esc_attr($total_stations); ?>" 
                       style="width: 100px; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" />
                <p style="margin: 5px 0 0 0; color: #666; font-size: 13px;">
                    Celkový počet nabíjecích stanic nebo bayů na této lokalitě.
                </p>
            </div>
            
            <!-- Informace o OCM datech -->
            <div class="ocm-info-box" style="margin-bottom: 25px; padding: 15px; background: #fff3cd; border: 1px solid #ffeaa7; border-radius: 6px;">
                <div style="display: flex; align-items: center; margin-bottom: 10px;">
                    <span style="font-size: 18px; margin-right: 8px;">📡</span>
                    <h4 style="margin: 0; color: #856404;">Data z OpenChargeMap API</h4>
                </div>
                <p style="margin: 0; color: #856404; line-height: 1.4;">
                    <strong>Poznámka:</strong> Pokud jste vybrali stanici z OCM API, technické specifikace se automaticky vyplní. 
                    Můžete je upravit podle potřeby nebo ponechat původní hodnoty.
                </p>
                <p style="margin: 10px 0 0 0; color: #856404; line-height: 1.4; font-size: 13px;">
                    <strong>💡 Tip:</strong> Duplikáty konektorů se automaticky filtrují - OCM konektory mají přednost před taxonomiemi.
                </p>
                <p style="margin: 10px 0 0 0; color: #856404; line-height: 1.4; font-size: 13px;">
                    <strong>🎨 Ikony:</strong> Ikony konektorů se automaticky načítají z taxonomií, pokud existuje shoda názvu.
                </p>
                <p style="margin: 10px 0 0 0; color: #856404; line-height: 1.4; font-size: 13px;">
                    <strong>🔗 Inteligentní párování:</strong> Systém automaticky páruje podobné názvy (např. "Type 2" s "Type 2 (Socket Only)").
                </p>
            </div>
            
            <!-- Konektory -->
            <div class="connectors-section">
                <h4 style="margin: 0 0 20px 0; color: #23282d; font-size: 16px;">Equipment Details</h4>
                
                <?php if (!empty($all_connectors)) : ?>
                    <div class="connectors-list">
                        <?php foreach ($all_connectors as $connector) :
                            $connector_id = $connector['id'];
                            $connector_name = $connector['name'];
                            $connector_type = $connector['type'];
                            
                            // Určit, jestli je konektor zaškrtnutý
                            if ($connector_type === 'taxonomy') {
                                $checked = in_array($connector_id, $selected_types);
                            } else {
                                $checked = true; // OCM konektory jsou vždy zaškrtnuté
                            }
                            
                            // Získat data pro konektor
                            $count = isset($charger_counts[$connector_id]) ? intval($charger_counts[$connector_id]) : '';
                            $status = isset($charger_status[$connector_id]) ? $charger_status[$connector_id] : 'operational';
                            $power = isset($charger_power[$connector_id]) ? $charger_power[$connector_id] : '';
                            $phase = isset($charger_phase[$connector_id]) ? $charger_phase[$connector_id] : '';
                            $voltage = isset($charger_voltage[$connector_id]) ? $charger_voltage[$connector_id] : '';
                            $amperage = isset($charger_amperage[$connector_id]) ? $charger_amperage[$connector_id] : '';
                            $conn_methods = get_post_meta($post->ID, '_db_charger_connection_method', true);
                            if (!is_array($conn_methods)) $conn_methods = array();
                            $connection_method = isset($conn_methods[$connector_id]) ? $conn_methods[$connector_id] : '';
                            
                            // Získat data z taxonomie nebo použít výchozí
                            $current_type = $connector['current_type'];
                            $icon = $connector['icon'];
                        ?>
                            <div class="connector-item" style="margin-bottom: 20px; padding: 20px; background: #fff; border: 1px solid #e1e5e9; border-radius: 8px; box-shadow: 0 2px 4px rgba(0,0,0,0.1);">
                                <!-- Hlavička konektoru -->
                                <div class="connector-header" style="display: flex; align-items: center; margin-bottom: 15px;">
                                    <!-- Ikona -->
                                    <div class="connector-icon" style="margin-right: 15px;">
                                        <?php if ($icon) : ?>
                                            <img src="<?php echo esc_url($icon); ?>" style="width: 40px; height: 40px; border-radius: 50%; border: 2px solid #e1e5e9; object-fit: contain; background: #fff;" alt="<?php echo esc_attr($connector_name); ?>" />
                                        <?php else : ?>
                                            <div style="width: 40px; height: 40px; border-radius: 50%; border: 2px solid #e1e5e9; background: #f8f9fa; display: flex; align-items: center; justify-content: center; color: #6c757d; font-size: 20px;">
                                                ⚡
                                            </div>
                                <?php endif; ?>
                                    </div>
                                    
                                    <!-- Název a typ -->
                                    <div class="connector-info" style="flex: 1;">
                                        <h5 style="margin: 0 0 5px 0; font-size: 16px; color: #23282d;">
                                            <?php echo esc_html($connector_name); ?>
                                            <?php if ($connector_type === 'ocm') : ?>
                                                <span style="background: #0073aa; color: white; padding: 2px 6px; border-radius: 3px; font-size: 11px; margin-left: 8px; font-weight: 600;">OCM</span>
                                            <?php endif; ?>
                                        </h5>
                                        <?php if ($connector_type === 'ocm') : ?>
                                            <span style="color: #0073aa; font-size: 12px; font-style: italic;">
                                                Automaticky načteno z OpenChargeMap API
                                            </span>
                                        <?php endif; ?>
                                        <?php if ($current_type && $current_type !== 'N/A') : ?>
                                            <span style="color: #6c757d; font-size: 14px;">
                                                <?php echo esc_html($current_type); ?>
                                            </span>
                                        <?php elseif ($connector_type === 'ocm') : ?>
                                            <span style="color: #0073aa; font-size: 12px; font-style: italic;">
                                                Typ proudu: Automaticky detekován
                                            </span>
                                        <?php endif; ?>
                                    </div>
                                    
                                    <!-- Checkbox -->
                                    <div class="connector-checkbox" style="margin-left: 15px;">
                                        <label style="display: flex; align-items: center; cursor: pointer;">
                                            <input type="checkbox" name="charger_type[]" value="<?php echo esc_attr($connector_id); ?>" 
                                                   <?php checked($checked); ?> class="db-charger-type-checkbox"
                                                   style="margin-right: 8px; transform: scale(1.2);" />
                                            <span style="font-weight: 500; color: #23282d;">Vybrat</span>
                            </label>
                                    </div>
                                </div>
                                
                                <!-- Detaily konektoru (zobrazí se po zaškrtnutí) -->
                                <div class="connector-details" style="display: <?php echo $checked ? 'block' : 'none'; ?>; margin-left: 55px;">
                                    <!-- Základní informace -->
                                    <div class="basic-info" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 20px;">
                                        <div class="form-group">
                                            <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #23282d;">
                                                Počet konektorů
                                            </label>
                                            <input type="number" name="charger_count[<?php echo esc_attr($connector_id); ?>]" 
                                                   min="1" value="<?php echo esc_attr($count); ?>" 
                                                   style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;" 
                                                   placeholder="např. 2" />
                                        </div>
                                        
                                        <div class="form-group">
                                            <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #23282d;">
                                                Stav
                                            </label>
                                            <select name="charger_status[<?php echo esc_attr($connector_id); ?>]" 
                                                    style="width: 100%; padding: 8px; border: 1px solid #ddd; border-radius: 4px;">
                                                <option value="operational" <?php selected($status, 'operational'); ?>>Operational</option>
                                                <option value="non_operational" <?php selected($status, 'non_operational'); ?>>Non-Operational</option>
                                                <option value="under_construction" <?php selected($status, 'under_construction'); ?>>Under Construction</option>
                                                <option value="planned" <?php selected($status, 'planned'); ?>>Planned</option>
                                            </select>
                                        </div>
                                    </div>
                                    
                                    <!-- Technické specifikace -->
                                    <div class="technical-specs" style="background: #f8f9fa; padding: 15px; border-radius: 6px; border: 1px solid #e9ecef;">
                                        <h6 style="margin: 0 0 15px 0; color: #495057; font-size: 14px; font-weight: 600;">
                                            ⚡ Technické specifikace
                                        </h6>
                                        
                                         <div class="specs-grid" style="display: grid; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr)); gap: 15px;">
                                            <div class="spec-item">
                                                <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                                    Výkon (kW)
                                                </label>
                                                <input type="number" name="charger_power[<?php echo esc_attr($connector_id); ?>]" 
                                                       min="1" step="1" value="<?php echo esc_attr($power); ?>" 
                                                       style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;" 
                                                       placeholder="např. 50" />
                                            </div>
                                            
                                            <?php if ($current_type === 'AC' || ($connector_type === 'ocm' && $current_type === 'AC')) : ?>
                                                <div class="spec-item">
                                                    <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                                        Fáze
                                                    </label>
                                                    <select name="charger_phase[<?php echo esc_attr($connector_id); ?>]" 
                                                            style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;">
                                                        <option value="">-- Vyberte --</option>
                                                        <option value="single" <?php selected($phase, 'single'); ?>>Jednofázový</option>
                                                        <option value="three" <?php selected($phase, 'three'); ?>>Třífázový</option>
                                                    </select>
                                                </div>
                                            <?php endif; ?>
                                            
                                            <div class="spec-item">
                                                <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                                    Napětí (V)
                                                </label>
                                                <input type="number" name="charger_voltage[<?php echo esc_attr($connector_id); ?>]" 
                                                       min="1" step="1" value="<?php echo esc_attr($voltage); ?>" 
                                                       style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;" 
                                                       placeholder="např. 400" />
                                            </div>
                                            
                                            <div class="spec-item">
                                                <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                                    Proud (A)
                                                </label>
                                                <input type="number" name="charger_amperage[<?php echo esc_attr($connector_id); ?>]" 
                                                       min="1" step="1" value="<?php echo esc_attr($amperage); ?>" 
                                                       style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;" 
                                                       placeholder="např. 63" />
                                            </div>
                                             <div class="spec-item">
                                                 <label style="display: block; font-weight: 500; margin-bottom: 5px; color: #495057; font-size: 13px;">
                                                     Způsob připojení
                                                 </label>
                                                 <select name="charger_connection_method[<?php echo esc_attr($connector_id); ?>]" 
                                                         style="width: 100%; padding: 6px; border: 1px solid #ced4da; border-radius: 4px; font-size: 13px;">
                                                     <option value="">-- Neznámý --</option>
                                                     <option value="kabel" <?php selected($connection_method, 'kabel'); ?>>kabel</option>
                                                     <option value="zásuvka" <?php selected($connection_method, 'zásuvka'); ?>>zásuvka</option>
                                                 </select>
                                             </div>
                                        </div>
                                        
                                        <!-- Nápověda -->
                                        <div style="margin-top: 15px; padding: 10px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 4px; font-size: 12px; color: #004085;">
                                            <strong>💡 Tip:</strong> 
                                            <?php if ($connector_type === 'ocm') : ?>
                                                Tyto hodnoty se automaticky vyplnily z OCM API. 
                                            <?php else : ?>
                                                Tyto hodnoty se automaticky vyplní z OCM API, pokud jste vybrali stanici. 
                                            <?php endif; ?>
                                            Můžete je upravit podle skutečného stavu na místě.
                                        </div>
                                    </div>
                                </div>
                        </div>
                    <?php endforeach; ?>
                    </div>
                <?php else : ?>
                    <div style="padding: 20px; text-align: center; background: #fff3cd; border: 1px solid #ffc107; border-radius: 6px; color: #856404;">
                        <p style="margin: 0; font-weight: 600;">⚠️ Žádné typy konektorů nebyly nalezeny.</p>
                        <p style="margin: 10px 0 0 0; font-size: 13px;">
                            Pro vytvoření nabíjecího místa manuálně je potřeba nejdříve vytvořit typy konektorů.
                        </p>
                        <p style="margin: 10px 0 0 0; font-size: 13px;">
                            <a href="<?php echo admin_url('edit-tags.php?taxonomy=charger_type'); ?>" target="_blank" class="button button-primary" style="margin-top: 10px;">
                                ➕ Vytvořit typy konektorů
                            </a>
                        </p>
                        <p style="margin: 15px 0 0 0; font-size: 12px; color: #856404;">
                            <strong>Alternativa:</strong> Můžete použít vyhledávání v OpenChargeMap (box nahoře) pro automatické načtení typů konektorů.
                        </p>
                    </div>
                <?php endif; ?>
                
                <!-- Návod -->
                <div class="instructions-box" style="margin-top: 25px; padding: 20px; background: #e7f3ff; border: 1px solid #b3d9ff; border-radius: 6px;">
                    <div style="display: flex; align-items: center; margin-bottom: 15px;">
                        <span style="font-size: 18px; margin-right: 8px;">📋</span>
                        <h5 style="margin: 0; color: #004085;">Návod k vyplnění</h5>
                    </div>
                    <ol style="margin: 0; padding-left: 20px; color: #004085; line-height: 1.6;">
                        <li><strong>Vyberte typy konektorů</strong>, které máte na místě</li>
                        <li><strong>Zadejte počet konektorů</strong> každého typu</li>
                        <li><strong>Nastavte stav</strong> (Operational, Non-Operational, atd.)</li>
                        <li><strong>Vyplňte technické specifikace</strong> (výkon, napětí, proud)</li>
                        <li><strong>Pro AC konektory nastavte fázi</strong> (jednofázový/třífázový)</li>
                    </ol>
                </div>
            </div>
        </div>
        
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            // Event listener pro checkboxy konektorů
            document.querySelectorAll('.db-charger-type-checkbox').forEach(function(checkbox) {
                checkbox.addEventListener('change', function() {
                    handleConnectorCheckboxChange(this);
                });
            });
            
            // Automatické počítání celkového počtu stanic
            function updateTotalStations() {
                var total = 0;
                document.querySelectorAll('input[name^="charger_count"]').forEach(function(input) {
                    if (input.value && !isNaN(input.value)) {
                        total += parseInt(input.value);
                    }
                });
                
                var totalInput = document.getElementById('_db_total_stations');
                if (totalInput) {
                    totalInput.value = total;
                }
            }
            
            // Event listener pro změny v počtu konektorů
            document.addEventListener('input', function(e) {
                if (e.target.name && e.target.name.startsWith('charger_count')) {
                    updateTotalStations();
                }
            });

            // OCM Enrich – binding na tlačítko (vanilla JS, s logy)
            (function(){
                var btn = document.getElementById('db-ocm-enrich-btn');
                if (!btn) return;
                btn.addEventListener('click', function(){
                    console.info('[DB][OCM][ENRICH] Klik – start');
                    var latEl = document.getElementById('_db_lat');
                    var lngEl = document.getElementById('_db_lng');
                    var out = document.getElementById('db-ocm-enrich-results');
                    var lat = latEl ? latEl.value : '';
                    var lng = lngEl ? lngEl.value : '';
                    if (out) out.innerHTML = '<em>Načítám návrhy z OCM…</em>';
                    if (!lat || !lng) {
                        if (out) out.innerHTML = '<span style="color:#a00;">Zadejte prosím souřadnice (lat/lng).</span>';
                        console.warn('[DB][OCM][ENRICH] Chybí souřadnice');
                        return;
                    }
                    if (typeof ajaxurl === 'undefined') {
                        console.error('[DB][OCM][ENRICH] ajaxurl není definován');
                        return;
                    }
                    var payload = {
                        action: 'db_ocm_enrich_suggestions',
                        nonce: '<?php echo wp_create_nonce('db_ocm_enrich'); ?>',
                        lat: lat,
                        lng: lng,
                        post_id: <?php echo (int)$post->ID; ?>
                    };
                    console.info('[DB][OCM][ENRICH] AJAX POST', payload);
                    jQuery.post(ajaxurl, payload).done(function(resp){
                        console.info('[DB][OCM][ENRICH] Response:', resp);
                        if (!resp || !resp.success) {
                            if (out) out.innerHTML = '<span style="color:#a00;">Chyba, nelze získat návrhy.</span>';
                            return;
                        }
                        var sug = resp.data || {};
                        var rows = [];
                        if (sug.max_power_kw && !jQuery('#_max_power_kw').val()) {
                            rows.push('<tr><td>Max výkon (kW)</td><td><code>'+sug.max_power_kw+'</code></td><td><button type="button" class="button apply-enrich" data-target="_max_power_kw" data-value="'+sug.max_power_kw+'">Použít</button></td></tr>');
                        }
                        if (Array.isArray(sug.connectors) && sug.connectors.length > 0) {
                            rows.push('<tr><td>Konektory</td><td><code>'+sug.connectors.map(function(c){return (c.type||'')+' '+(c.power_kw||'')+'kW';}).join(', ')+'</code></td><td><button type="button" class="button apply-enrich-connectors">Navrhnout do formuláře</button></td></tr>');
                        }
                        if (sug.image_url && !jQuery('#_ocm_image_url').val()) {
                            rows.push('<tr><td>Obrázek</td><td><code>'+sug.image_url+'</code></td><td><button type="button" class="button apply-enrich" data-target="_ocm_image_url" data-value="'+sug.image_url+'">Použít</button></td></tr>');
                        }
                        if (rows.length === 0) {
                            if (out) out.innerHTML = '<span>Žádný vhodný návrh (existující hodnoty nepřepisuji).</span>';
                            return;
                        }
                        if (out) out.innerHTML = '<table class="widefat"><thead><tr><th>Položka</th><th>Návrh z OCM</th><th>Akce</th></tr></thead><tbody>'+rows.join('')+'</tbody></table>';
                    }).fail(function(xhr){
                        console.error('[DB][OCM][ENRICH] AJAX fail:', xhr && xhr.status, xhr && xhr.responseText);
                        if (out) out.innerHTML = '<span style="color:#a00;">AJAX chyba – viz konzole.</span>';
                    });
                });
            })();

            // Apply jednotlivé návrhy
            document.addEventListener('click', function(e){
                if (e.target && e.target.classList && e.target.classList.contains('apply-enrich')) {
                    var target = e.target.getAttribute('data-target');
                    var value = e.target.getAttribute('data-value');
                    if (!target) return;
                    var el = document.getElementById(target);
                    if (el && !el.value) {
                        el.value = value;
                        console.info('[DB][OCM][ENRICH] Applied ->', target, value);
                        window.showNotification('Hodnota doplněna z OCM: '+target, 'success');
                    } else {
                        console.info('[DB][OCM][ENRICH] Skipped (already filled) ->', target);
                        window.showNotification('Pole již má hodnotu, nic nepřepsáno.', 'warning');
                    }
                }
                if (e.target && e.target.classList && e.target.classList.contains('apply-enrich-connectors')) {
                    console.info('[DB][OCM][ENRICH] Connectors suggestion clicked');
                    window.showNotification('Návrh konektorů připraven – ručně potvrďte v technickém bloku dle potřeby.', 'info');
                }
            });
        });
        </script>
        
        <style>
        .technical-data-container {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
        }
        
        .connector-item {
            transition: all 0.3s ease;
        }
        
        .connector-item:hover {
            box-shadow: 0 4px 8px rgba(0,0,0,0.15);
        }
        
        .connector-details {
            transition: all 0.3s ease;
        }
        
        .form-group label {
            font-weight: 500;
        }
        
        .specs-grid {
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
        }
        
        @media (max-width: 768px) {
            .specs-grid {
                grid-template-columns: 1fr;
            }
            
            .connector-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .connector-checkbox {
                margin-left: 0;
                margin-top: 10px;
            }
        }
        </style>
        <?php
    }

    /**
     * Render meta boxu pro média
     */
    public function render_media_box($post) {
        $custom_icon = get_post_meta($post->ID, '_db_custom_icon', true);
        $icon_url = $custom_icon ? plugins_url('assets/icons/' . $custom_icon, dirname(__FILE__)) : '';
        ?>
        
        <h4>Vlastní ikona</h4>
        <?php if ($icon_url) : ?>
            <img src="<?php echo esc_url($icon_url); ?>" alt="ikona" style="max-width:48px;max-height:48px;vertical-align:middle;margin-right:10px;" />
        <?php endif; ?>
        <input type="file" name="_db_custom_icon" accept="image/svg+xml" />
        <?php if ($icon_url) echo '<br><small>Aktuální ikona. Pro změnu nahrajte novou SVG ikonu.</small>'; ?>
        
        <h4>Obrázek z OpenChargeMap</h4>
        <?php
        $ocm_image_url = get_post_meta($post->ID, '_ocm_image_url', true);
        $ocm_image_comment = get_post_meta($post->ID, '_ocm_image_comment', true);
        if ($ocm_image_url) : ?>
            <img src="<?php echo esc_url($ocm_image_url); ?>" alt="Obrázek stanice" style="max-width:200px;height:auto;margin-bottom:10px;" />
            <br>
            <small><?php echo esc_html($ocm_image_comment); ?></small>
        <?php else : ?>
            <p>Žádný obrázek z OpenChargeMap</p>
        <?php endif; ?>
        <?php
    }

    /**
     * Uložení meta boxů
     */
    public function save_meta_boxes($post_id, $post = null) {
        error_log('[CHARGING DEBUG] save_meta_boxes volána pro post_id: ' . $post_id);
        
        if (!isset($_POST['db_charging_form_nonce']) || !wp_verify_nonce($_POST['db_charging_form_nonce'], 'db_save_charging_form')) {
            error_log('[CHARGING DEBUG] Nonce check failed nebo chybí nonce');
            return;
        }

        if (defined('DOING_AUTOSAVE') && DOING_AUTOSAVE) {
            return;
        }

        if (!current_user_can('edit_post', $post_id)) {
            return;
        }

        // Pokud není $post předán, načíst ho
        if ($post === null) {
            $post = get_post($post_id);
        }

        if (!$post || $_POST['post_type'] !== 'charging_location') {
            return;
        }

        // Zpracování názvu z OCM API
        if (isset($_POST['_ocm_title']) && !empty($_POST['_ocm_title'])) {
            $ocm_title = sanitize_text_field($_POST['_ocm_title']);
            // Aktualizovat název příspěvku pouze pokud je prázdný nebo se liší
            if (empty($post->post_title) || $post->post_title !== $ocm_title) {
                wp_update_post(array(
                    'ID' => $post_id,
                    'post_title' => $ocm_title
                ));
            }
        }

        // Zpracování obrázku z OCM API
        if (isset($_POST['_ocm_image_url']) && !empty($_POST['_ocm_image_url'])) {
            error_log('[CHARGING DEBUG] Zpracovávám OCM obrázek: ' . $_POST['_ocm_image_url']);
            
            $image_url = esc_url_raw($_POST['_ocm_image_url']);
            $image_comment = sanitize_text_field($_POST['_ocm_image_comment'] ?? '');
            
            // Uložit URL obrázku
            update_post_meta($post_id, '_ocm_image_url', $image_url);
            update_post_meta($post_id, '_ocm_image_comment', $image_comment);
            
            // Pokus o nahrání obrázku jako featured image
            if (!has_post_thumbnail($post_id)) {
                error_log('[CHARGING DEBUG] Nahrávám obrázek jako featured image');
                $title = isset($ocm_title) ? $ocm_title : $post->post_title;
                $result = $this->upload_ocm_image_as_featured($post_id, $image_url, $title);
                if ($result) {
                    error_log('[CHARGING DEBUG] Obrázek úspěšně nahrán jako featured image');
                } else {
                    error_log('[CHARGING DEBUG] Chyba při nahrávání obrázku');
                }
            } else {
                error_log('[CHARGING DEBUG] Příspěvek již má featured image, obrázek se nenahrává');
            }
        } else {
            error_log('[CHARGING DEBUG] Žádný OCM obrázek k nahrání');
        }
        
        // Alternativní způsob - zkusit nahrát obrázek z uložených meta dat
        $saved_image_url = get_post_meta($post_id, '_ocm_image_url', true);
        if (!empty($saved_image_url) && !has_post_thumbnail($post_id)) {
            error_log('[CHARGING DEBUG] Zkouším nahrát obrázek z uložených meta dat: ' . $saved_image_url);
            $title = $post->post_title;
            $result = $this->upload_ocm_image_as_featured($post_id, $saved_image_url, $title);
            if ($result) {
                error_log('[CHARGING DEBUG] Obrázek úspěšně nahrán z meta dat');
            } else {
                error_log('[CHARGING DEBUG] Chyba při nahrávání obrázku z meta dat');
            }
        }

        // Adresa
        if (isset($_POST['_db_address'])) {
            update_post_meta($post_id, '_db_address', sanitize_text_field($_POST['_db_address']));
        }

        // Souřadnice
        if (isset($_POST['_db_lat'])) {
            update_post_meta($post_id, '_db_lat', floatval($_POST['_db_lat']));
        }
        if (isset($_POST['_db_lng'])) {
            update_post_meta($post_id, '_db_lng', floatval($_POST['_db_lng']));
        }

        // MPO UID (unikátní klíč pro párování importu)
        if (isset($_POST['_mpo_uniq_key'])) {
            $mpo_uid = sanitize_text_field($_POST['_mpo_uniq_key']);
            update_post_meta($post_id, '_mpo_uniq_key', $mpo_uid);
        }

        // MPO provozní doba
        if (isset($_POST['_mpo_opening_hours'])) {
            update_post_meta($post_id, '_mpo_opening_hours', sanitize_text_field($_POST['_mpo_opening_hours']));
        }

        // DB doporučuje (flag pro mapu)
        $recommended_flag = isset($_POST['_db_recommended']) && $_POST['_db_recommended'] === '1' ? '1' : '0';
        update_post_meta($post_id, '_db_recommended', $recommended_flag);

        // Poskytovatel
        if (isset($_POST['_db_provider']) && is_numeric($_POST['_db_provider'])) {
            wp_set_post_terms($post_id, array(intval($_POST['_db_provider'])), 'provider', false);
        } else {
            wp_set_post_terms($post_id, array(), 'provider', false);
        }

        // Typy nabíječek - podporuje jak ID taxonomie, tak názvy konektorů z OCM
        if (isset($_POST['charger_type']) && is_array($_POST['charger_type'])) {
            $charger_types = $_POST['charger_type'];
            $type_ids = array();
            $ocm_connector_names = array();
            
            // Rozdělit na ID taxonomie a názvy OCM konektorů
            foreach ($charger_types as $type) {
                if (is_numeric($type)) {
                    $type_ids[] = intval($type);
                } else {
                    $ocm_connector_names[] = sanitize_text_field($type);
                }
            }
            
            // Uložit taxonomie
            if (!empty($type_ids)) {
            wp_set_post_terms($post_id, $type_ids, 'charger_type', false);
            }
            
            // Uložit počty konektorů (podporuje jak ID, tak názvy)
            // Uložit pouze pro vybrané konektory (ty, které jsou v charger_type)
            $counts = array();
            if (isset($_POST['charger_count']) && is_array($_POST['charger_count'])) {
                foreach ($_POST['charger_count'] as $key => $count) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true)) {
                        $cnt = intval($count);
                        if ($cnt > 0) {
                            $counts[$key] = $cnt;
                        }
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_counts', $counts);
            
            // Uložit stavy konektorů (pouze pro vybrané konektory)
            $statuses = array();
            if (isset($_POST['charger_status']) && is_array($_POST['charger_status'])) {
                foreach ($_POST['charger_status'] as $key => $status) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true)) {
                        $statuses[$key] = sanitize_text_field($status);
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_status', $statuses);
            
            // Uložit výkony konektorů (pouze pro vybrané konektory)
            $powers = array();
            if (isset($_POST['charger_power']) && is_array($_POST['charger_power'])) {
                foreach ($_POST['charger_power'] as $key => $power) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true)) {
                        $power_val = intval($power);
                        if ($power_val > 0) {
                            $powers[$key] = $power_val;
                        }
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_power', $powers);
            
            // Uložit fáze konektorů (pouze pro AC, pouze pro vybrané konektory)
            $phases = array();
            if (isset($_POST['charger_phase']) && is_array($_POST['charger_phase'])) {
                foreach ($_POST['charger_phase'] as $key => $phase) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true) && !empty($phase)) {
                        $phases[$key] = sanitize_text_field($phase);
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_phase', $phases);
            
            // Uložit napětí konektorů (pouze pro vybrané konektory)
            $voltages = array();
            if (isset($_POST['charger_voltage']) && is_array($_POST['charger_voltage'])) {
                foreach ($_POST['charger_voltage'] as $key => $voltage) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true)) {
                        $voltage_val = intval($voltage);
                        if ($voltage_val > 0) {
                            $voltages[$key] = $voltage_val;
                        }
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_voltage', $voltages);
            
            // Uložit proudy konektorů (pouze pro vybrané konektory)
            $amperages = array();
            if (isset($_POST['charger_amperage']) && is_array($_POST['charger_amperage'])) {
                foreach ($_POST['charger_amperage'] as $key => $amperage) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true)) {
                        $amperage_val = intval($amperage);
                        if ($amperage_val > 0) {
                            $amperages[$key] = $amperage_val;
                        }
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_amperage', $amperages);

            // Uložit způsob připojení (kabel / zásuvka, pouze pro vybrané konektory)
            $conn_methods = array();
            if (isset($_POST['charger_connection_method']) && is_array($_POST['charger_connection_method'])) {
                foreach ($_POST['charger_connection_method'] as $key => $method) {
                    // Uložit pouze pokud je tento konektor vybraný v charger_type
                    if (in_array($key, $charger_types, true)) {
                        $m = sanitize_text_field($method);
                        if ($m !== '') {
                            $conn_methods[$key] = $m;
                        }
                    }
                }
            }
            update_post_meta($post_id, '_db_charger_connection_method', $conn_methods);
            
            // Uložit OCM/MPO data konektorů pro pozdější použití (vč. MPO uid/index/connection_method)
            if (!empty($ocm_connector_names)) {
                update_post_meta($post_id, '_ocm_connector_names', $ocm_connector_names);
            }
            
            error_log('[CHARGING DEBUG] Uloženo: ' . count($counts) . ' konektorů, ' . count($powers) . ' výkonů, ' . count($voltages) . ' napětí');
        } else {
            // Pokud není charger_type v POST, NESMAZAT existující data - může to být nový post nebo uživatel prostě nevybral žádné
            // Pouze pokud je to explicitní smazání (např. prázdný array), pak smazat
            // Pro nové posty necháme data prázdná, pro existující posty zachováme stávající data
            if (isset($_POST['charger_type']) && is_array($_POST['charger_type']) && empty($_POST['charger_type'])) {
                // Explicitní prázdný array = smazat všechno
                wp_set_post_terms($post_id, array(), 'charger_type', false);
                delete_post_meta($post_id, '_db_charger_counts');
                delete_post_meta($post_id, '_db_charger_status');
                delete_post_meta($post_id, '_db_charger_power');
                delete_post_meta($post_id, '_db_charger_phase');
                delete_post_meta($post_id, '_db_charger_voltage');
                delete_post_meta($post_id, '_db_charger_amperage');
                delete_post_meta($post_id, '_ocm_connector_names');
                error_log('[CHARGING DEBUG] Explicitně smazáno - prázdný charger_type array');
            } else {
                // Pokud charger_type není v POST vůbec, zachovat stávající data (nebo nechat prázdné pro nový post)
                error_log('[CHARGING DEBUG] charger_type není v POST - zachovávám stávající data');
            }
        }
        
        // Celkový počet stanic
        if (isset($_POST['_db_total_stations'])) {
            $total_stations = intval($_POST['_db_total_stations']);
            if ($total_stations > 0) {
                update_post_meta($post_id, '_db_total_stations', $total_stations);
            } else {
                delete_post_meta($post_id, '_db_total_stations');
            }
        }

        // Poznámky
        if (isset($_POST['_db_charger_note'])) {
            update_post_meta($post_id, '_db_charger_note', sanitize_textarea_field($_POST['_db_charger_note']));
        }

        // Cena
        $allowed_prices = array('free', 'paid');
        if (isset($_POST['_db_price']) && in_array($_POST['_db_price'], $allowed_prices, true)) {
            update_post_meta($post_id, '_db_price', sanitize_text_field($_POST['_db_price']));
        }

        // Informace o operátorovi a provozu
        if (isset($_POST['_operator'])) {
            update_post_meta($post_id, '_operator', sanitize_text_field($_POST['_operator']));
        }
        if (isset($_POST['_openchargemap_id'])) {
            update_post_meta($post_id, '_openchargemap_id', sanitize_text_field($_POST['_openchargemap_id']));
        }
        if (isset($_POST['_usage_type'])) {
            update_post_meta($post_id, '_usage_type', sanitize_text_field($_POST['_usage_type']));
        }
        if (isset($_POST['_status_type'])) {
            update_post_meta($post_id, '_status_type', sanitize_text_field($_POST['_status_type']));
        }
        if (isset($_POST['_data_provider'])) {
            update_post_meta($post_id, '_data_provider', sanitize_text_field($_POST['_data_provider']));
        }
        if (isset($_POST['_data_quality'])) {
            update_post_meta($post_id, '_data_quality', sanitize_text_field($_POST['_data_quality']));
        }
        if (isset($_POST['_date_created'])) {
            update_post_meta($post_id, '_date_created', sanitize_text_field($_POST['_date_created']));
        }
        if (isset($_POST['_last_status_update'])) {
            update_post_meta($post_id, '_last_status_update', sanitize_text_field($_POST['_last_status_update']));
        }
        if (isset($_POST['_submission_status'])) {
            update_post_meta($post_id, '_submission_status', sanitize_text_field($_POST['_submission_status']));
        }

        // Vlastní SVG ikona
        if (isset($_FILES['_db_custom_icon']) && isset($_FILES['_db_custom_icon']['tmp_name']) && $_FILES['_db_custom_icon']['size'] > 0) {
            $svg = file_get_contents($_FILES['_db_custom_icon']['tmp_name']);
            if (strpos($svg, '<svg') !== false) {
                $dir = DB_PLUGIN_DIR . 'assets/icons/';
                if (!is_dir($dir)) mkdir($dir, 0755, true);
                $filename = 'customicon-' . $post_id . '.svg';
                file_put_contents($dir . $filename, $svg);
                update_post_meta($post_id, '_db_custom_icon', $filename);
            }
        }
        
        // Zpracování konektorů z formuláře
        if (isset($_POST['charger_type']) && is_array($_POST['charger_type'])) {
            $connectors = array();
            
            foreach ($_POST['charger_type'] as $connector_id) {
                // Získat data konektoru z taxonomie
                $term = get_term($connector_id, 'charger_type');
                if ($term && !is_wp_error($term)) {
                    $connector_icon = get_term_meta($term->term_id, 'charger_icon', true);
                    $connector_current_type = get_term_meta($term->term_id, 'charger_current_type', true);
                    
                    $connectors[] = array(
                        'type' => $term->name,
                        'icon' => $connector_icon,
                        'current_type' => $connector_current_type,
                        'power_kw' => isset($_POST['charger_power'][$connector_id]) ? floatval($_POST['charger_power'][$connector_id]) : 0,
                        'quantity' => isset($_POST['charger_count'][$connector_id]) ? intval($_POST['charger_count'][$connector_id]) : 1,
                        'status' => isset($_POST['charger_status'][$connector_id]) ? sanitize_text_field($_POST['charger_status'][$connector_id]) : 'operational'
                    );
                }
            }
            
            if (!empty($connectors)) {
                update_post_meta($post_id, '_connectors', $connectors);
                error_log('[CHARGING DEBUG] Uloženo ' . count($connectors) . ' konektorů do _connectors');
            }
        }
    }

    /**
     * Nahrání OCM obrázku jako featured image
     */
    private function upload_ocm_image_as_featured($post_id, $image_url, $title) {
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $tmp = media_sideload_image($image_url, $post_id, $title, 'src');
        
        if (is_wp_error($tmp)) {
            error_log('[CHARGING DEBUG] Chyba při nahrávání OCM obrázku: ' . $tmp->get_error_message());
            return false;
        }
        
        $attachments = get_posts(array(
            'numberposts' => 1,
            'post_type' => 'attachment',
            'post_parent' => $post_id,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        if (!empty($attachments)) {
            $attachment_id = $attachments[0]->ID;
            set_post_thumbnail($post_id, $attachment_id);
            error_log('[CHARGING DEBUG] OCM obrázek úspěšně nahrán jako featured image, ID: ' . $attachment_id);
            return true;
        }
        
        return false;
    }

    /**
     * AJAX handler pro nahrání OCM obrázku
     */
    public function ajax_upload_ocm_image() {
        error_log('[CHARGING DEBUG] AJAX handler upload_ocm_image volán');
        
        try {
            if (!current_user_can('upload_files')) {
                error_log('[CHARGING DEBUG] Nedostatečná oprávnění');
                wp_send_json_error('Nedostatečná oprávnění pro nahrávání souborů');
                return;
            }
            
            if (!wp_verify_nonce($_POST['nonce'], 'upload_ocm_image')) {
                error_log('[CHARGING DEBUG] Security check failed');
                wp_send_json_error('Security check failed');
                return;
            }
            
            $photo_url = sanitize_url($_POST['photo_url']);
            $post_title = sanitize_text_field($_POST['post_title']);
            $post_id = intval($_POST['post_id'] ?? 0);
            
            error_log('[CHARGING DEBUG] Parametry: photo_url=' . $photo_url . ', post_title=' . $post_title . ', post_id=' . $post_id);
            
            if (empty($photo_url)) {
                error_log('[CHARGING DEBUG] Chybí URL fotky');
                wp_send_json_error('Chybí URL fotky');
                return;
            }
            
            require_once ABSPATH . 'wp-admin/includes/file.php';
            require_once ABSPATH . 'wp-admin/includes/media.php';
            require_once ABSPATH . 'wp-admin/includes/image.php';
            
            error_log('[CHARGING DEBUG] Nahrávám obrázek: ' . $photo_url);
            $tmp = media_sideload_image($photo_url, $post_id, $post_title, 'src');
            
            if (is_wp_error($tmp)) {
                error_log('[CHARGING DEBUG] Chyba při nahrávání: ' . $tmp->get_error_message());
                wp_send_json_error('Chyba při nahrávání: ' . $tmp->get_error_message());
                return;
            }
            
            $attachments = get_posts(array(
                'numberposts' => 1,
                'post_type' => 'attachment',
                'post_parent' => $post_id,
                'orderby' => 'date',
                'order' => 'DESC',
            ));
            
            if (!empty($attachments)) {
                $attachment_id = $attachments[0]->ID;
                $attachment_url = wp_get_attachment_url($attachment_id);
                
                set_post_thumbnail($post_id, $attachment_id);
                
                error_log('[CHARGING DEBUG] Obrázek úspěšně nahrán, ID: ' . $attachment_id);
                
                wp_send_json_success(array(
                    'attachment_id' => $attachment_id,
                    'url' => $attachment_url
                ));
            } else {
                error_log('[CHARGING DEBUG] Nepodařilo se najít nahraný obrázek');
                wp_send_json_error('Nepodařilo se najít nahraný obrázek');
            }
            
        } catch (Exception $e) {
            error_log('[CHARGING DEBUG] Exception: ' . $e->getMessage());
            wp_send_json_error('Exception: ' . $e->getMessage());
        }
    }

    /**
     * AJAX handler pro aktualizaci názvu
     */
    public function ajax_update_title() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Nedostatečná oprávnění');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'update_charging_title')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $post_id = intval($_POST['post_id'] ?? 0);
        $title = sanitize_text_field($_POST['title'] ?? '');
        
        if (!$post_id || !$title) {
            wp_send_json_error('Chybí ID příspěvku nebo název');
            return;
        }
        
        $result = wp_update_post(array(
            'ID' => $post_id,
            'post_title' => $title
        ));
        
        if (is_wp_error($result)) {
            wp_send_json_error('Chyba při aktualizaci: ' . $result->get_error_message());
        } else {
            wp_send_json_success('Název úspěšně aktualizován');
        }
    }
    
    /**
     * AJAX handler pro přidání nového poskytovatele
     */
    public function ajax_add_provider() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Nedostatečná oprávnění.');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'db_add_provider')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $name = isset($_POST['name']) ? sanitize_text_field($_POST['name']) : '';
        $friendly_name = isset($_POST['friendly_name']) ? sanitize_text_field($_POST['friendly_name']) : '';
        $logo = isset($_POST['logo']) ? esc_url_raw($_POST['logo']) : '';
        
        if (!$name) {
            wp_send_json_error('Název je povinný.');
            return;
        }
        
        // Kontrola, zda poskytovatel již neexistuje
        $term = term_exists($name, 'provider');
        if (!$term) {
            $term = wp_insert_term($name, 'provider');
        }
        
        if (is_wp_error($term)) {
            error_log('[CHARGING DEBUG] Chyba při vytváření/aktualizaci termu: ' . $term->get_error_message());
            wp_send_json_error($term->get_error_message());
            return;
        }
        
        $term_id = is_array($term) ? $term['term_id'] : $term;
        
        // Uložení friendly názvu a loga
        if (!empty($friendly_name)) {
            $result = update_term_meta($term_id, 'provider_friendly_name', $friendly_name);
            if (false === $result) {
                error_log('[CHARGING DEBUG] Chyba při ukládání friendly_name pro term_id: ' . $term_id);
            }
        }
        if (!empty($logo)) {
            $result = update_term_meta($term_id, 'provider_logo', $logo);
            if (false === $result) {
                error_log('[CHARGING DEBUG] Chyba při ukládání loga pro term_id: ' . $term_id);
            }
        }
        
        error_log('[CHARGING DEBUG] Poskytovatel úspěšně vytvořen/aktualizován: ' . $name . ' (ID: ' . $term_id . ')');
        wp_send_json_success(['term_id' => $term_id]);
    }
    
    /**
     * AJAX handler pro vytvoření poskytovatele z OCM operátora
     */
    public function ajax_create_provider_from_operator() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Nedostatečná oprávnění.');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'db_create_provider_from_operator')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $operator_name = isset($_POST['operator_name']) ? sanitize_text_field($_POST['operator_name']) : '';
        
        if (!$operator_name) {
            wp_send_json_error('Název operátora je povinný.');
            return;
        }
        
        // Kontrola, zda term již existuje (case-insensitive)
        $existing_terms = get_terms(array(
            'taxonomy' => 'provider',
            'hide_empty' => false,
            'name__like' => $operator_name
        ));
        
        if (is_wp_error($existing_terms)) {
            error_log('[CHARGING DEBUG] Chyba při hledání existujících termů: ' . $existing_terms->get_error_message());
            wp_send_json_error('Chyba při hledání existujících poskytovatelů: ' . $existing_terms->get_error_message());
            return;
        }
        
        $term_id = null;
        $is_new = false;
        
        // Hledat přesnou shodu (case-insensitive)
        foreach ($existing_terms as $term) {
            if (strtolower($term->name) === strtolower($operator_name)) {
                $term_id = $term->term_id;
                break;
            }
        }
        
        // Pokud nenajdeme, vytvoříme nový
        if (!$term_id) {
            $term = wp_insert_term($operator_name, 'provider');
            
            if (is_wp_error($term)) {
                error_log('[CHARGING DEBUG] Chyba při vytváření termu: ' . $term->get_error_message());
                wp_send_json_error('Chyba při vytváření poskytovatele: ' . $term->get_error_message());
                return;
            }
            
            $term_id = is_array($term) ? $term['term_id'] : $term;
            $is_new = true;
            
            // Log pro debugging
            error_log('[CHARGING DEBUG] Vytvořen nový poskytovatel z OCM operátora: ' . $operator_name . ' (ID: ' . $term_id . ')');
        } else {
            error_log('[CHARGING DEBUG] Nalezen existující poskytovatel pro OCM operátora: ' . $operator_name . ' (ID: ' . $term_id . ')');
        }
        
        wp_send_json_success(array(
            'term_id' => $term_id,
            'is_new' => $is_new,
            'operator_name' => $operator_name
        ));
    }
    
    /**
     * AJAX handler pro získání informací o poskytovateli
     */
    public function ajax_get_provider_info() {
        if (!current_user_can('edit_posts')) {
            wp_send_json_error('Nedostatečná oprávnění.');
            return;
        }
        
        if (!wp_verify_nonce($_POST['nonce'], 'db_get_provider_info')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $term_id = isset($_POST['term_id']) ? intval($_POST['term_id']) : 0;
        
        if (!$term_id) {
            wp_send_json_error('ID termu je povinné.');
            return;
        }
        
        $term = get_term($term_id, 'provider');
        
        if (!$term || is_wp_error($term)) {
            $error_msg = is_wp_error($term) ? $term->get_error_message() : 'Poskytovatel nebyl nalezen';
            error_log('[CHARGING DEBUG] Chyba při získávání termu: ' . $error_msg);
            wp_send_json_error('Poskytovatel nebyl nalezen: ' . $error_msg);
            return;
        }
        
        $provider_info = array(
            'term_id' => $term->term_id,
            'name' => $term->name,
            'friendly_name' => get_term_meta($term_id, 'provider_friendly_name', true),
            'logo' => get_term_meta($term_id, 'provider_logo', true)
        );
        
        error_log('[CHARGING DEBUG] Provider info získán: ' . json_encode($provider_info));
        wp_send_json_success($provider_info);
    }
    
    /**
     * AJAX handler pro uložení dat vybrané stanice
     */
    public function ajax_save_station_data() {
        if (!wp_verify_nonce($_POST['nonce'], 'db_save_station_data')) {
            wp_send_json_error('Neplatný nonce');
        }
        
        $post_id = intval($_POST['post_id']);
        $station_data = $_POST['station_data'];
        
        // Kontrola oprávnění
        if (!current_user_can('edit_post', $post_id)) {
            wp_send_json_error('Nedostatečná oprávnění');
        }
        
        // Uložit data stanice jako post meta
        update_post_meta($post_id, '_ocm_station_data', $station_data);
        
        // Uložit konektory jako post meta
        if (isset($station_data['connectors']) && is_array($station_data['connectors'])) {
            $connectors = array();
            $charger_counts = array();
            $charger_status = array();
            $charger_power = array();
            $charger_voltage = array();
            $charger_amperage = array();
            $charger_phase = array();
            
            foreach ($station_data['connectors'] as $connector) {
                $connector_type = $connector['type'];
                
                $connectors[] = array(
                    'type' => $connector_type,
                    'power_kw' => $connector['power_kw'],
                    'quantity' => $connector['quantity'] ?? 1,
                    'voltage' => $connector['voltage'] ?? '',
                    'amperage' => $connector['amperage'] ?? '',
                    'phase' => $connector['phase'] ?? ''
                );
                
                // Uložit data pro každý konektor
                $charger_counts[$connector_type] = intval($connector['quantity'] ?? 1);
                $charger_status[$connector_type] = 'operational';
                $charger_power[$connector_type] = intval($connector['power_kw'] ?? 0);
                $charger_voltage[$connector_type] = intval($connector['voltage'] ?? 0);
                $charger_amperage[$connector_type] = intval($connector['amperage'] ?? 0);
                $charger_phase[$connector_type] = sanitize_text_field($connector['phase'] ?? '');
            }
            
            update_post_meta($post_id, '_ocm_connectors', $connectors);
            update_post_meta($post_id, '_db_charger_counts', $charger_counts);
            update_post_meta($post_id, '_db_charger_status', $charger_status);
            update_post_meta($post_id, '_db_charger_power', $charger_power);
            update_post_meta($post_id, '_db_charger_voltage', $charger_voltage);
            update_post_meta($post_id, '_db_charger_amperage', $charger_amperage);
            update_post_meta($post_id, '_db_charger_phase', $charger_phase);
        }
        
        // Uložit celkový počet stanic
        if (isset($station_data['connectors']) && is_array($station_data['connectors'])) {
            $total_stations = array_sum(array_column($station_data['connectors'], 'quantity'));
            update_post_meta($post_id, '_db_total_stations', $total_stations);
        }
        
        wp_send_json_success('Data stanice byla úspěšně uložena');
    }

    /**
     * Registrace AJAX handlerů
     */
    public function register_ajax_handlers() {
        error_log('[CHARGING DEBUG] Registruji AJAX handlery...');
        
        add_action('wp_ajax_upload_ocm_image', array($this, 'ajax_upload_ocm_image'));
        add_action('wp_ajax_update_charging_title', array($this, 'ajax_update_title'));
        add_action('wp_ajax_db_add_provider', array($this, 'ajax_add_provider'));
        add_action('wp_ajax_db_create_provider_from_operator', array($this, 'ajax_create_provider_from_operator'));
        add_action('wp_ajax_db_get_provider_info', array($this, 'ajax_get_provider_info'));
        add_action('wp_ajax_save_selected_station_data', array($this, 'ajax_save_station_data'));
        
        
        error_log('[CHARGING DEBUG] AJAX handlery registrovány');
    }

    

    /**
     * Parsování MPO Excel souboru
     */
    private function parse_mpo_excel_file($file_path) {
        // Kontrola, zda je PhpSpreadsheet dostupné
        if (!class_exists('PhpOffice\PhpSpreadsheet\IOFactory')) {
            // Pokus o načtení Composer autoloaderu
            $composer_autoload = ABSPATH . 'vendor/autoload.php';
            if (file_exists($composer_autoload)) {
                require_once $composer_autoload;
            } else {
                throw new Exception('PhpSpreadsheet není dostupné. Nainstalujte ho přes Composer nebo WordPress plugin.');
            }
        }
        
        try {
            // Načtení Excel souboru
            $spreadsheet = \PhpOffice\PhpSpreadsheet\IOFactory::load($file_path);
            $worksheet = $spreadsheet->getActiveSheet();
            $rows = $worksheet->toArray();
            
            $stations = array();
            $current_station = null;
            
            foreach ($rows as $index => $row) {
                // Přeskočit hlavičku (první 3 řádky)
                if ($index < 3) continue;
                
                // Parsování řádku
                $station_data = $this->parse_mpo_row($row);
                
                if ($station_data) {
                    // Kontrola, jestli je to nová stanice nebo další konektor
                    $station_key = $station_data['lat'] . '_' . $station_data['lng'] . '_' . $station_data['address'];
                    
                    if (!isset($stations[$station_key])) {
                        // Nová stanice
                        $stations[$station_key] = array(
                            'name' => $station_data['address'],
                            'address' => $station_data['address'],
                            'lat' => $station_data['lat'],
                            'lng' => $station_data['lng'],
                            'postal_code' => $station_data['postal_code'],
                            'city' => $station_data['city'],
                            'operator_id' => $station_data['operator_id'],
                            'operator_name' => $station_data['operator_name'],
                            'total_power' => $station_data['total_power'],
                            'operating_hours' => $station_data['operating_hours'],
                            'commission_date' => $station_data['commission_date'],
                            'connectors' => array(),
                            'data_source' => 'mpo',
                            'data_source_badge' => 'MPO',
                            'data_source_color' => '#e74c3c'
                        );
                    }
                    
                    // Přidat konektor
                    if ($station_data['connector_type']) {
                        $stations[$station_key]['connectors'][] = array(
                            'type' => $station_data['connector_type'],
                            'power_kw' => $station_data['connector_power'],
                            'standard' => $station_data['connector_standard'],
                            'connection_method' => $station_data['connection_method'],
                            'excluded_combinations' => $station_data['excluded_combinations']
                        );
                    }
                }
            }
            
            return array_values($stations);
            
        } catch (Exception $e) {
            throw new Exception('Chyba při parsování Excel souboru: ' . $e->getMessage());
        }
    }

    /**
     * Parsování jednotlivého řádku MPO dat
     */
    private function parse_mpo_row($row) {
        // Kontrola, zda řádek není prázdný
        if (empty($row[0])) return null;
        
        // Parsování základních údajů
        $address = trim($row[0] . ', ' . $row[1]); // ulice + pozice
        $postal_code = trim($row[2]);
        $city = trim($row[3]);
        $operating_hours = trim($row[4]);
        $lat = floatval($row[5]);
        $lng = floatval($row[6]);
        $operator_id = trim($row[7]);
        $operator_name = trim($row[8]);
        $total_power = floatval($row[9]);
        
        // Kontrola povinných polí
        if (empty($address) || empty($lat) || empty($lng)) {
            return null;
        }
        
        // Parsování konektorů
        $connector_type = trim($row[12]); // AC/DC
        $connector_power = floatval($row[13]);
        $connector_standard = trim($row[14]); // Type 2, CHAdeMO, CCS2
        $connection_method = trim($row[15]); // kabel, zásuvka
        $excluded_combinations = trim($row[16]);
        
        // Datum uvedení do provozu
        $commission_date = trim($row[25]);
        
        return array(
            'address' => $address,
            'postal_code' => $postal_code,
            'city' => $city,
            'operating_hours' => $operating_hours,
            'lat' => $lat,
            'lng' => $lng,
            'operator_id' => $operator_id,
            'operator_name' => $operator_name,
            'total_power' => $total_power,
            'connector_type' => $connector_type,
            'connector_power' => $connector_power,
            'connector_standard' => $connector_standard,
            'connection_method' => $connection_method,
            'excluded_combinations' => $excluded_combinations,
            'commission_date' => $commission_date
        );
    }

    /**
     * Hlavní metoda pro zpracování MPO importu s deduplication
     */
    private function process_mpo_import_with_deduplication($mpo_data) {
        $stats = array(
            'total_processed' => 0,
            'new_stations' => 0,
            'updated_stations' => 0,
            'duplicates_found' => 0,
            'total_stations' => 0,
            'total_points' => 0,
            'errors' => 0,
            'duplicate_groups' => array()
        );
        
        // Agregace stanic podle GPS (více konektorů = jedna stanice)
        $aggregated_stations = $this->aggregate_mpo_stations_by_location($mpo_data);
        
        foreach ($aggregated_stations as $station_key => $station) {
            try {
                $result = $this->process_single_mpo_station($station);
                
                switch ($result['action']) {
                    case 'created':
                        $stats['new_stations']++;
                        break;
                    case 'updated':
                        $stats['updated_stations']++;
                        break;
                    case 'duplicate':
                        $stats['duplicates_found']++;
                        if (!empty($result['duplicate_group'])) {
                            $stats['duplicate_groups'][] = $result['duplicate_group'];
                        }
                        break;
                }
                
                $stats['total_stations']++;
                $stats['total_points'] += count($station['connectors']);
                $stats['total_processed']++;
                
            } catch (Exception $e) {
                $stats['errors']++;
                error_log('[MPO IMPORT] Chyba při zpracování stanice: ' . $e->getMessage());
            }
        }
        
        return $stats;
    }

    /**
     * Agregace MPO stanic podle GPS lokace
     */
    private function aggregate_mpo_stations_by_location($mpo_data) {
        $aggregated = array();
        
        foreach ($mpo_data as $row_data) {
            if (!$row_data) continue;
            
            // Vytvořit unikátní klíč pro stanici (GPS + adresa)
            $station_key = $this->create_station_key($row_data);
            
            if (!isset($aggregated[$station_key])) {
                // Nová stanice
                $aggregated[$station_key] = array(
                    'name' => $row_data['address'],
                    'address' => $row_data['address'],
                    'lat' => $row_data['lat'],
                    'lng' => $row_data['lng'],
                    'postal_code' => $row_data['postal_code'],
                    'city' => $row_data['city'],
                    'operator_id' => $row_data['operator_id'],
                    'operator_name' => $row_data['operator_name'],
                    'total_power' => $row_data['total_power'],
                    'operating_hours' => $row_data['operating_hours'],
                    'commission_date' => $row_data['commission_date'],
                    'connectors' => array(),
                    'data_source' => 'mpo',
                    'data_source_badge' => 'MPO',
                    'data_source_color' => '#e74c3c'
                );
            }
            
            // Přidat konektor
            if ($row_data['connector_type']) {
                $aggregated[$station_key]['connectors'][] = array(
                    'type' => $row_data['connector_type'],
                    'power_kw' => $row_data['connector_power'],
                    'standard' => $row_data['connector_standard'],
                    'connection_method' => $row_data['connection_method'],
                    'excluded_combinations' => $row_data['excluded_combinations']
                );
            }
        }
        
        return $aggregated;
    }

    /**
     * Vytvoření unikátního klíče pro stanici
     */
    private function create_station_key($station_data) {
        // Zaokrouhlit GPS na 5 desetinných míst (přibližně 1m přesnost)
        $lat = round($station_data['lat'], 5);
        $lng = round($station_data['lng'], 5);
        
        return $lat . '_' . $lng . '_' . sanitize_title($station_data['address']);
    }

    /**
     * Zpracování jedné MPO stanice s deduplication
     */
    private function process_single_mpo_station($mpo_station) {
        // 1. Hledat podle GPS souřadnic (nejspolehlivější)
        $existing_station = $this->find_station_by_gps($mpo_station['lat'], $mpo_station['lng']);
        
        if ($existing_station) {
            return $this->handle_existing_station($existing_station, $mpo_station);
        }
        
        // 2. Hledat podle adresy a názvu
        $existing_station = $this->find_station_by_address($mpo_station['address'], $mpo_station['city']);
        
        if ($existing_station) {
            return $this->handle_address_match($existing_station, $mpo_station);
        }
        
        // 3. Vytvořit novou stanici
        return $this->create_new_mpo_station($mpo_station);
    }

    /**
     * Hledání stanice podle GPS souřadnic
     */
    private function find_station_by_gps($lat, $lng, $radius_km = 0.05) {
        $config = $this->get_deduplication_config();
        $radius_km = $config['gps_radius_flexible'];
        
        // Hledat stanice v okruhu
        $args = array(
            'post_type' => 'charging_location',
            'post_status' => 'any',
            'meta_query' => array(
                array(
                    'key' => '_latitude',
                    'value' => array($lat - 0.001, $lat + 0.001), // Přibližně 100m
                    'type' => 'DECIMAL',
                    'compare' => 'BETWEEN'
                ),
                array(
                    'key' => '_longitude',
                    'value' => array($lng - 0.001, $lng + 0.001),
                    'type' => 'DECIMAL',
                    'compare' => 'BETWEEN'
                )
            ),
            'posts_per_page' => 20
        );
        
        $posts = get_posts($args);
        
        // Filtrovat podle přesné vzdálenosti
        $nearby_stations = array();
        foreach ($posts as $post) {
            $post_lat = get_post_meta($post->ID, '_latitude', true);
            $post_lng = get_post_meta($post->ID, '_longitude', true);
            
            if ($post_lat && $post_lng) {
                $distance = $this->calculate_distance($lat, $lng, $post_lat, $post_lng);
                
                if ($distance <= $radius_km) {
                    // Vrátit základní informace o stanici pro pozdější zpracování
                    $nearby_stations[] = array(
                        'post' => $post,
                        'distance' => $distance
                    );
                }
            }
        }
        
        // Seřadit podle vzdálenosti
        usort($nearby_stations, function($a, $b) {
            return $a['distance'] - $b['distance'];
        });
        
        return !empty($nearby_stations) ? $nearby_stations[0] : false;
    }

    /**
     * Hledání stanice podle adresy
     */
    private function find_station_by_address($address, $city) {
        $config = $this->get_deduplication_config();
        
        $args = array(
            'post_type' => 'charging_location',
            'post_status' => 'any',
            'meta_query' => array(
                'relation' => 'OR',
                array(
                    'key' => '_address',
                    'value' => $address,
                    'compare' => 'LIKE'
                ),
                array(
                    'key' => '_city',
                    'value' => $city,
                    'compare' => 'LIKE'
                )
            ),
            'posts_per_page' => 10
        );
        
        $posts = get_posts($args);
        
        foreach ($posts as $post) {
            $post_address = get_post_meta($post->ID, '_address', true);
            $post_city = get_post_meta($post->ID, '_city', true);
            
            $address_similarity = $this->calculate_text_similarity($address, $post_address);
            $city_similarity = $this->calculate_text_similarity($city, $post_city);
            
            if ($address_similarity >= $config['address_similarity_threshold'] || 
                $city_similarity >= $config['address_similarity_threshold']) {
                return $post;
            }
        }
        
        return false;
    }

    /**
     * Výpočet vzdálenosti mezi dvěma GPS body
     */
    private function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 6371; // km
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earth_radius * $c;
    }

    /**
     * Výpočet skóre podobnosti mezi MPO stanicí a existujícím postem
     */
    private function calculate_similarity_score($mpo_station, $existing_post) {
        $score = 0;
        
        // 1. GPS vzdálenost (0-50 bodů)
        $post_lat = get_post_meta($existing_post->ID, '_latitude', true);
        $post_lng = get_post_meta($existing_post->ID, '_longitude', true);
        
        if ($post_lat && $post_lng) {
            $distance = $this->calculate_distance(
                $mpo_station['lat'], $mpo_station['lng'], 
                $post_lat, $post_lng
            );
            
            if ($distance <= 0.01) { // 10m
                $score += 50;
            } elseif ($distance <= 0.05) { // 50m
                $score += 40;
            } elseif ($distance <= 0.1) { // 100m
                $score += 30;
            } elseif ($distance <= 0.2) { // 200m
                $score += 20;
            }
        }
        
        // 2. Název/adresa podobnost (0-30 bodů)
        $existing_name = $existing_post->post_title;
        $existing_address = get_post_meta($existing_post->ID, '_address', true);
        
        $name_similarity = $this->calculate_text_similarity($mpo_station['name'], $existing_name);
        $address_similarity = $this->calculate_text_similarity($mpo_station['address'], $existing_address);
        
        $score += max($name_similarity, $address_similarity) * 30;
        
        // 3. Provozovatel shoda (0-20 bodů)
        $existing_operator = get_post_meta($existing_post->ID, '_operator_name', true);
        if ($existing_operator) {
            if (strtolower($mpo_station['operator_name']) === strtolower($existing_operator)) {
                $score += 20;
            } elseif (strpos(strtolower($mpo_station['operator_name']), strtolower($existing_operator)) !== false) {
                $score += 15;
            }
        }
        
        return min(100, $score);
    }

    /**
     * Výpočet textové podobnosti
     */
    private function calculate_text_similarity($text1, $text2) {
        $text1 = strtolower(trim($text1));
        $text2 = strtolower(trim($text2));
        
        if (empty($text1) || empty($text2)) return 0;
        
        // Levenshtein distance
        $distance = levenshtein($text1, $text2);
        $max_length = max(strlen($text1), strlen($text2));
        
        if ($max_length === 0) return 1;
        
        return 1 - ($distance / $max_length);
    }

    /**
     * Zpracování existující stanice
     */
    private function handle_existing_station($existing_station, $mpo_station) {
        $config = $this->get_deduplication_config();
        
        // Počítat similarity score
        $similarity_score = $this->calculate_similarity_score($mpo_station, $existing_station['post']);
        
        if ($similarity_score >= $config['min_similarity_score']) {
            // Vysoká podobnost - aktualizovat existující stanici
            $this->update_existing_station_with_mpo($existing_station['post'], $mpo_station);
            
            return array(
                'action' => 'updated',
                'post_id' => $existing_station['post']->ID,
                'similarity_score' => $similarity_score
            );
        } else {
            // Nízká podobnost - považovat za duplicit
            return array(
                'action' => 'duplicate',
                'existing_post' => $existing_station['post'],
                'similarity_score' => $similarity_score,
                'duplicate_group' => array(
                    'type' => 'gps_match',
                    'existing' => $existing_station['post'],
                    'new' => $mpo_station,
                    'score' => $similarity_score
                )
            );
        }
    }

    /**
     * Zpracování shody podle adresy
     */
    private function handle_address_match($existing_post, $mpo_station) {
        $config = $this->get_deduplication_config();
        
        $address_similarity = $this->calculate_text_similarity(
            $mpo_station['address'], 
            get_post_meta($existing_post->ID, '_address', true)
        );
        
        if ($address_similarity >= $config['address_similarity_threshold']) {
            // Vysoká podobnost adresy - aktualizovat
            $this->update_existing_station_with_mpo($existing_post, $mpo_station);
            
            return array(
                'action' => 'updated',
                'post_id' => $existing_post->ID,
                'similarity_score' => $address_similarity * 100
            );
        } else {
            // Nízká podobnost - považovat za duplicit
            return array(
                'action' => 'duplicate',
                'existing_post' => $existing_post,
                'similarity_score' => $address_similarity * 100,
                'duplicate_group' => array(
                    'type' => 'address_match',
                    'existing' => $existing_post,
                    'new' => $mpo_station,
                    'score' => $address_similarity * 100
                )
            );
        }
    }

    /**
     * Vytvoření nové MPO stanice
     */
    private function create_new_mpo_station($mpo_station) {
        $post_data = array(
            'post_title' => $mpo_station['name'],
            'post_content' => 'Stanice z MPO databáze',
            'post_status' => 'publish',
            'post_type' => 'charging_location'
        );
        
        $post_id = wp_insert_post($post_data);
        
        if ($post_id) {
            // Uložit MPO data do meta boxů
            $this->populate_meta_boxes_from_mpo($post_id, $mpo_station);
            
            return array(
                'action' => 'created',
                'post_id' => $post_id
            );
        }
        
        throw new Exception('Nepodařilo se vytvořit novou stanici');
    }

    /**
     * Aktualizace existující stanice MPO daty
     */
    private function update_existing_station_with_mpo($existing_post, $mpo_station) {
        // Aktualizovat základní data
        $update_data = array(
            'ID' => $existing_post->ID,
            'post_title' => $mpo_station['name']
        );
        
        wp_update_post($update_data);
        
        // Aktualizovat meta data
        $this->populate_meta_boxes_from_mpo($existing_post->ID, $mpo_station);
        
        // Označit jako kombinovaný zdroj
        update_post_meta($existing_post->ID, '_data_source', 'combined');
        update_post_meta($existing_post->ID, '_mpo_data', $mpo_station);
        update_post_meta($existing_post->ID, '_mpo_last_update', current_time('Y-m-d H:i:s'));
    }

    /**
     * Populace meta boxů z MPO dat
     */
    private function populate_meta_boxes_from_mpo($post_id, $mpo_station) {
        // Základní informace
        update_post_meta($post_id, '_address', $mpo_station['address']);
        update_post_meta($post_id, '_postal_code', $mpo_station['postal_code']);
        update_post_meta($post_id, '_city', $mpo_station['city']);
        update_post_meta($post_id, '_latitude', $mpo_station['lat']);
        update_post_meta($post_id, '_longitude', $mpo_station['lng']);
        
        // Provozovatel
        update_post_meta($post_id, '_operator_name', $mpo_station['operator_name']);
        update_post_meta($post_id, '_mpo_operator_id', $mpo_station['operator_id']);
        
        // Technické údaje
        update_post_meta($post_id, '_mpo_total_power', $mpo_station['total_power']);
        update_post_meta($post_id, '_mpo_operating_hours', $mpo_station['operating_hours']);
        update_post_meta($post_id, '_mpo_commission_date', $mpo_station['commission_date']);
        
        // Konektory
        if (!empty($mpo_station['connectors'])) {
            $connector_data = array();
            foreach ($mpo_station['connectors'] as $connector) {
                $connector_data[] = array(
                    'type' => $connector['type'],
                    'power_kw' => $connector['power_kw'],
                    'standard' => $connector['standard'],
                    'connection_method' => $connector['connection_method']
                );
            }
            update_post_meta($post_id, '_mpo_connectors', $connector_data);
        }
        
        // MPO metadata
        update_post_meta($post_id, '_mpo_station_key', $this->create_station_key($mpo_station));
        update_post_meta($post_id, '_data_source', 'mpo');
        update_post_meta($post_id, '_mpo_data', $mpo_station);
        update_post_meta($post_id, '_mpo_import_date', current_time('Y-m-d H:i:s'));
    }

    /**
     * Konfigurace deduplication pravidel
     */
    private function get_deduplication_config() {
        return array(
            'gps_radius_strict' => 0.01,      // 10m - striktní shoda
            'gps_radius_flexible' => 0.05,    // 50m - flexibilní shoda
            'gps_radius_warning' => 0.1,      // 100m - varování
            'min_similarity_score' => 70,     // Minimální skóre pro považování za duplicit
            'name_similarity_threshold' => 0.8, // 80% podobnost názvu
            'address_similarity_threshold' => 0.7, // 70% podobnost adresy
            'enable_fuzzy_matching' => true,  // Povolit fuzzy matching
            'log_duplicates' => true,         // Logovat nalezené duplicity
            'auto_merge_threshold' => 90      // Automatické sloučení nad 90% podobnost
        );
    }

    /**
     * Přidání admin stránky pro MPO testování
     */
    public function add_mpo_admin_page() {
        // Odstraněno – stránka MPO Import Test byla nahrazena samostatným importérem
    }

    /**
     * Render admin stránky pro MPO testování
     */
    public function render_mpo_admin_page() {
        // Získání aktuálních MPO dat
        $mpo_last_update = get_option('db_mpo_last_update', 'Nikdy');
        $mpo_stations_count = get_option('db_mpo_stations_count', '0');
        $mpo_points_count = get_option('db_mpo_points_count', '0');
        $mpo_stations_data = get_option('db_mpo_stations_data', array());
        $mpo_import_stats = get_option('db_mpo_import_stats', array());
        
        // Generování aktuálního MPO odkazu
        $current_year = date('Y');
        $current_month = date('n');
        $mpo_url = "https://mpo.gov.cz/assets/cz/energetika/statistika/statistika-a-evidence-cerpacich-a-dobijecich-stanic/{$current_year}/{$current_month}/";
        
        ?>
        <div class="wrap">
            <h1>🧪 MPO Import Test - Dobitý Baterky</h1>
            
            <div class="notice notice-info">
                <h3>ℹ️ Informace o MPO datech</h3>
                <ul>
                    <li><strong>MPO</strong> = Ministerstvo průmyslu a obchodu České republiky</li>
                    <li>Oficiální databáze všech dobíjecích stanic v ČR</li>
                    <li>Aktualizuje se měsíčně (obvykle kolem 15. dne)</li>
                    <li>Obsahuje přes 3,000 stanic a 5,600 dobíjecích bodů</li>
                    <li>Data zahrnují: GPS souřadnice, výkony, typy konektorů, provozovatele</li>
                    <li>Hlavní provozovatelé: ČEZ, PRE, E.ON, innogy, atd.</li>
                </ul>
            </div>
            
            <div class="notice notice-warning">
                <h3>🔗 Rychlý odkaz na MPO portál</h3>
                <p>Aktuální odkaz pro <strong><?php echo esc_html($current_month . '/' . $current_year); ?></strong>:</p>
                <p><a href="<?php echo esc_url($mpo_url); ?>" target="_blank" class="button button-primary">
                    🌐 Otevřít MPO portál pro stažení dat
                </a></p>
                <p><strong>Tip:</strong> Odkaz se automaticky aktualizuje podle aktuálního měsíce a roku.</p>
            </div>
            
            <div class="card">
                <h3>📊 Aktuální stav MPO dat</h3>
                <table class="form-table">
                    <tr>
                        <th>Počet stanic:</th>
                        <td><strong><?php echo esc_html($mpo_stations_count); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Počet dobíjecích bodů:</th>
                        <td><strong><?php echo esc_html($mpo_points_count); ?></strong></td>
                    </tr>
                    <tr>
                        <th>Poslední aktualizace:</th>
                        <td><strong><?php echo esc_html($mpo_last_update); ?></strong></td>
                    </tr>
                </table>
                
                <?php if (!empty($mpo_import_stats)): ?>
                <h4>📈 Statistiky posledního importu</h4>
                <table class="form-table">
                    <tr>
                        <th>Nové stanice:</th>
                        <td><?php echo esc_html($mpo_import_stats['new_stations'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Aktualizované:</th>
                        <td><?php echo esc_html($mpo_import_stats['updated_stations'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Duplicity:</th>
                        <td><?php echo esc_html($mpo_import_stats['duplicates_found'] ?? 0); ?></td>
                    </tr>
                    <tr>
                        <th>Chyby:</th>
                        <td><?php echo esc_html($mpo_import_stats['errors'] ?? 0); ?></td>
                    </tr>
                </table>
                <?php endif; ?>
            </div>
            
            <div class="card">
                <h3>📥 Import MPO dat (přesunuto)</h3>
                <p>Tato testovací sekce byla odstraněna. Pro import MPO dat použijte prosím hlavní menu: <strong>EV – Import MPO</strong>.</p>
            </div>
            
            <?php if (!empty($mpo_stations_data)): ?>
            <div class="card">
                <h3>📋 Importované MPO stanice (prvních 20)</h3>
                <table class="wp-list-table widefat fixed striped">
                    <thead>
                        <tr>
                            <th>Název/Adresa</th>
                            <th>Souřadnice</th>
                            <th>Provozovatel</th>
                            <th>Celkový výkon</th>
                            <th>Konektory</th>
                            <th>Zdroj</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php 
                        $display_count = 0;
                        foreach (array_slice($mpo_stations_data, 0, 20) as $station): 
                            $display_count++;
                        ?>
                        <tr>
                            <td>
                                <strong><?php echo esc_html($station['name']); ?></strong><br>
                                <small><?php echo esc_html($station['postal_code'] . ' ' . $station['city']); ?></small>
                            </td>
                            <td>
                                <?php echo esc_html($station['lat'] . ', ' . $station['lng']); ?>
                            </td>
                            <td>
                                <strong><?php echo esc_html($station['operator_name']); ?></strong><br>
                                <small>ID: <?php echo esc_html($station['operator_id']); ?></small>
                            </td>
                            <td>
                                <?php echo esc_html($station['total_power']); ?> kW
                            </td>
                            <td>
                                <?php if (!empty($station['connectors'])): ?>
                                    <?php foreach ($station['connectors'] as $connector): ?>
                                        <div style="margin-bottom: 5px;">
                                            <strong><?php echo esc_html($connector['type']); ?></strong><br>
                                            <?php echo esc_html($connector['power_kw']); ?> kW | 
                                            <?php echo esc_html($connector['standard']); ?><br>
                                            <small><?php echo esc_html($connector['connection_method']); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <em>Žádné konektory</em>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span style="display: inline-block; padding: 2px 8px; border-radius: 12px; font-size: 11px; font-weight: bold; color: white; background: #e74c3c;">
                                    <?php echo esc_html($station['data_source_badge']); ?>
                                </span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <?php if (count($mpo_stations_data) > 20): ?>
                    <p style="text-align: center; margin-top: 15px; color: #666;">
                        Zobrazeno <?php echo $display_count; ?> z <?php echo count($mpo_stations_data); ?> stanic
                    </p>
                <?php endif; ?>
            </div>
            <?php else: ?>
            <div class="card">
                <h3>📋 Importované MPO stanice</h3>
                <p style="text-align: center; color: #666; padding: 40px;">
                    <em>Žádná MPO data nejsou importována.</em><br>
                    Použijte formulář výše pro import dat z MPO portálu.
                </p>
            </div>
            <?php endif; ?>
        </div>
        
        <script>
        // Definovat ajaxurl pro WordPress
        var ajaxurl = '<?php echo admin_url('admin-ajax.php'); ?>';
        jQuery(document).ready(function($) {
            $('#mpo-import-form').on('submit', function(e) {
                e.preventDefault();
                
                var formData = new FormData(this);
                var importBtn = $('#mpo-import-btn');
                var statusDiv = $('#mpo-import-status');
                
                // Přidat action parametr do FormData
                formData.append('action', 'db_import_mpo_data');
                
                // Debug: zobrazit FormData obsah
                console.log('FormData obsah:');
                for (var pair of formData.entries()) {
                    console.log(pair[0] + ': ' + pair[1]);
                }
                
                // Zobrazit loading
                importBtn.prop('disabled', true).text('⏳ Importuji...');
                statusDiv.html('<div class="notice notice-info">⏳ Importuji MPO data...</div>');
                
                // AJAX import
                $.ajax({
                    url: ajaxurl,
                    type: 'POST',
                    data: formData,
                    processData: false,
                    contentType: false,
                    success: function(response) {
                        console.log('AJAX success response:', response);
                        if (response.success) {
                            statusDiv.html('<div class="notice notice-success">✅ ' + response.data.message + '</div>');
                            // Reload stránky pro zobrazení nových dat
                            setTimeout(function() {
                                location.reload();
                            }, 2000);
                        } else {
                            statusDiv.html('<div class="notice notice-error">❌ ' + response.data + '</div>');
                        }
                    },
                    error: function(xhr, status, error) {
                        console.log('AJAX error:', {xhr: xhr, status: status, error: error});
                        console.log('Response text:', xhr.responseText);
                        statusDiv.html('<div class="notice notice-error">❌ Chyba při importu: ' + error + '</div>');
                    },
                    complete: function() {
                        importBtn.prop('disabled', false).text('📥 Importovat MPO data');
                    }
                });
            });
        });
        </script>
        <?php
    }
} 