<?php
/**
 * Administrační stránka pro správu ikon
 * @package DobityBaterky
 */

namespace DB;

class Icon_Admin {
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function render_rv_color_settings() {
        if (isset($_POST['save_rv_color'])) {
            if (wp_verify_nonce($_POST['rv_color_nonce'], 'save_rv_color')) {
                $color = isset($_POST['db_rv_color']) ? sanitize_hex_color($_POST['db_rv_color']) : '';
                if (!$color) { $color = '#FCE67D'; }
                update_option('db_rv_color', $color);
                $icon_color = isset($_POST['db_rv_icon_color']) ? sanitize_hex_color($_POST['db_rv_icon_color']) : '';
                if (!$icon_color) { $icon_color = '#049FE8'; }
                update_option('db_rv_icon_color', $icon_color);
                echo '<div class="notice notice-success"><p>Barvy RV uloženy.</p></div>';
            }
        }
        $current = get_option('db_rv_color', '#FCE67D');
        if (!is_string($current) || !preg_match('/^#[0-9a-fA-F]{6}$/', $current)) { $current = '#FCE67D'; }
        $current_icon = get_option('db_rv_icon_color', '#049FE8');
        if (!is_string($current_icon) || !preg_match('/^#[0-9a-fA-F]{6}$/', $current_icon)) { $current_icon = '#049FE8'; }
        ?>
        <div class="card">
            <h2>Barva RV pinů</h2>
            <p>Centrální barva výplně a barva SVG ikony pro RV místa.</p>
            <form method="post" action="">
                <?php wp_nonce_field('save_rv_color', 'rv_color_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="db_rv_color">Barva pinu (HEX)</label></th>
                        <td>
                            <input type="text" id="db_rv_color" name="db_rv_color" value="<?php echo esc_attr($current); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_rv_color_picker" value="<?php echo esc_attr($current); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                            <p class="description">Výchozí je #FCE67D.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="db_rv_icon_color">Barva SVG ikony (HEX)</label></th>
                        <td>
                            <input type="text" id="db_rv_icon_color" name="db_rv_icon_color" value="<?php echo esc_attr($current_icon); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_rv_icon_color_picker" value="<?php echo esc_attr($current_icon); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                            <p class="description">Výchozí je #049FE8.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="save_rv_color" class="button-primary" value="Uložit" />
                </p>
            </form>
        </div>
        <script>
        (function(){
            function bindSync(txtId, pickId){
                var txt = document.getElementById(txtId);
                var pick = document.getElementById(pickId);
                pick.addEventListener('input', function(){ txt.value = pick.value; });
                txt.addEventListener('input', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ pick.value = txt.value; } });
                txt.addEventListener('change', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ pick.value = txt.value; } });
            }
            bindSync('db_rv_color','db_rv_color_picker');
            bindSync('db_rv_icon_color','db_rv_icon_color_picker');
        })();
        </script>
        <?php
    }

    private function render_charger_colors_settings() {
        if (isset($_POST['save_charger_colors'])) {
            if (wp_verify_nonce($_POST['charger_colors_nonce'], 'save_charger_colors')) {
                $ac = isset($_POST['db_charger_ac_color']) ? sanitize_hex_color($_POST['db_charger_ac_color']) : '';
                $dc = isset($_POST['db_charger_dc_color']) ? sanitize_hex_color($_POST['db_charger_dc_color']) : '';
                $icon = isset($_POST['db_charger_icon_color']) ? sanitize_hex_color($_POST['db_charger_icon_color']) : '';
                $blend_start = isset($_POST['db_charger_blend_start']) ? intval($_POST['db_charger_blend_start']) : 30;
                $blend_end = isset($_POST['db_charger_blend_end']) ? intval($_POST['db_charger_blend_end']) : 70;
                if (!$ac) $ac = '#049FE8';
                if (!$dc) $dc = '#FFACC4';
                if (!$icon) $icon = '#ffffff';
                $blend_start = max(0, min(100, $blend_start));
                $blend_end = max(0, min(100, $blend_end));
                if ($blend_end <= $blend_start) { $blend_end = min(100, $blend_start + 20); }
                update_option('db_charger_ac_color', $ac);
                update_option('db_charger_dc_color', $dc);
                update_option('db_charger_icon_color', $icon);
                update_option('db_charger_blend_start', $blend_start);
                update_option('db_charger_blend_end', $blend_end);
                echo '<div class="notice notice-success"><p>Barvy nabíječek uloženy.</p></div>';
            }
        }
        $ac = get_option('db_charger_ac_color', '#049FE8');
        $dc = get_option('db_charger_dc_color', '#FFACC4');
        $icon = get_option('db_charger_icon_color', '#ffffff');
        $blend_start = (int) get_option('db_charger_blend_start', 30);
        $blend_end = (int) get_option('db_charger_blend_end', 70);
        if (!is_string($ac) || !preg_match('/^#[0-9a-fA-F]{6}$/', $ac)) $ac = '#049FE8';
        if (!is_string($dc) || !preg_match('/^#[0-9a-fA-F]{6}$/', $dc)) $dc = '#FFACC4';
        if (!is_string($icon) || !preg_match('/^#[0-9a-fA-F]{6}$/', $icon)) $icon = '#ffffff';
        ?>
        <div class="card">
            <h2>Barvy nabíječek</h2>
            <p>Nastavení základních barev pro AC a DC a šířky přechodu pro hybridní (kombinovaný) bod.</p>
            <form method="post" action="">
                <?php wp_nonce_field('save_charger_colors', 'charger_colors_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="db_charger_ac_color">AC barva</label></th>
                        <td>
                            <input type="text" id="db_charger_ac_color" name="db_charger_ac_color" value="<?php echo esc_attr($ac); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_charger_ac_color_picker" value="<?php echo esc_attr($ac); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="db_charger_dc_color">DC barva</label></th>
                        <td>
                            <input type="text" id="db_charger_dc_color" name="db_charger_dc_color" value="<?php echo esc_attr($dc); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_charger_dc_color_picker" value="<?php echo esc_attr($dc); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="db_charger_icon_color">Barva SVG ikony (HEX)</label></th>
                        <td>
                            <input type="text" id="db_charger_icon_color" name="db_charger_icon_color" value="<?php echo esc_attr($icon); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_charger_icon_color_picker" value="<?php echo esc_attr($icon); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                            <p class="description">Barva SVG ikony uvnitř pinu nabíječek.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="db_charger_blend_start">Hybrid přechod od (%)</label></th>
                        <td>
                            <input type="number" id="db_charger_blend_start" name="db_charger_blend_start" value="<?php echo esc_attr($blend_start); ?>" min="0" max="100" step="1" />
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="db_charger_blend_end">Hybrid přechod do (%)</label></th>
                        <td>
                            <input type="number" id="db_charger_blend_end" name="db_charger_blend_end" value="<?php echo esc_attr($blend_end); ?>" min="0" max="100" step="1" />
                        </td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="save_charger_colors" class="button-primary" value="Uložit" />
                </p>
            </form>
        </div>
        <script>
        (function(){
            function bindSync(txtId, pickId){
                var txt = document.getElementById(txtId);
                var pick = document.getElementById(pickId);
                pick.addEventListener('input', function(){ txt.value = pick.value; });
                txt.addEventListener('input', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ pick.value = txt.value; } });
                txt.addEventListener('change', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ pick.value = txt.value; } });
            }
            bindSync('db_charger_ac_color','db_charger_ac_color_picker');
            bindSync('db_charger_dc_color','db_charger_dc_color_picker');
            bindSync('db_charger_icon_color','db_charger_icon_color_picker');
        })();
        </script>
        <?php
    }

    private function __construct() {
        add_action( 'admin_menu', array( $this, 'add_menu_page' ) );
        add_action( 'admin_init', array( $this, 'handle_form' ) );
    }

    public function add_menu_page() {
        add_submenu_page(
            'tools.php', // Parent slug - Tools menu
            __( 'Správa ikon', 'dobity-baterky' ),
            __( 'Správa ikon', 'dobity-baterky' ),
            'manage_options',
            'db-icon-admin',
            array( $this, 'render_page' )
        );


    }

    public function handle_form() {
        if ( ! isset($_POST['db_icon_admin_nonce']) || ! wp_verify_nonce($_POST['db_icon_admin_nonce'], 'db_icon_admin_save') ) return;
        if ( ! current_user_can('manage_options') ) return;
        $type = sanitize_text_field($_POST['icon_type'] ?? '');
        $color = sanitize_hex_color($_POST['icon_color'] ?? '');
        // Připrav cílový adresář v uploads
        $up = wp_upload_dir();
        $icons_dir = trailingslashit($up['basedir']) . 'dobity-baterky/icons/';
        if ( ! is_dir( $icons_dir ) ) { wp_mkdir_p( $icons_dir ); }
        // Smazání dekorace (pouze pro POI a RV typy, ne pro charger_type)
        if (isset($_POST['delete_icon_svg']) && $_POST['delete_icon_svg'] === '1') {
            if (preg_match('/^(poi_type|rv_type):([0-9]+)$/', $type, $m)) {
                $slug = $m[1] . '-' . $m[2];
                $file_upload = $icons_dir . $slug . '.svg';
                if (file_exists($file_upload)) unlink($file_upload);
                $term_id = intval($m[2]);
                if ($term_id) {
                    update_term_meta($term_id, 'icon_slug', '');
                }
            }
            return;
        }
        // SVG upload (pouze pro POI a RV typy, ne pro charger_type)
        if ( isset($_FILES['icon_svg']) && $_FILES['icon_svg']['size'] > 0 ) {
            // Kontrola, zda se nejedná o charger_type
            if ( strpos($type, 'charger_type:') === 0 ) {
                // Pro charger_type se ikony neukládají - používají se automaticky
                return;
            }
            
            $svg = file_get_contents($_FILES['icon_svg']['tmp_name']);
            // Validace SVG: musí obsahovat <svg ...> a viewBox a nesmí obsahovat <script>
            if ( strpos($svg, '<svg') !== false && strpos($svg, 'viewBox=') !== false && strpos($svg, '<script') === false ) {
                // Odstraň width/height
                $svg = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg);
                $svg = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg);
                // Odstraň fill a stroke z vnořených elementů
                $svg = preg_replace('/(<(path|g|rect|circle|polygon|ellipse|line|polyline)[^>]*)\s(fill|stroke)="[^"]*"/i', '$1', $svg);
                // Najdi původní viewBox
                if (preg_match('/viewBox="([\-0-9\.]+) ([\-0-9\.]+) ([0-9\.]+) ([0-9\.]+)"/', $svg, $m)) {
                    $vx = floatval($m[1]);
                    $vy = floatval($m[2]);
                    $vw = floatval($m[3]);
                    $vh = floatval($m[4]);
                    // Výpočet scale a posunu pro centrování a unifikaci
                    $scale = min(256/$vw, 256/$vh);
                    $dx = (256 - $vw * $scale) / 2 - $vx * $scale;
                    $dy = (256 - $vh * $scale) / 2 - $vy * $scale;
                    // Přepiš viewBox na 0 0 256 256
                    $svg = preg_replace('/viewBox="[^"]*"/', 'viewBox="0 0 256 256"', $svg);
                    // Obal obsah do <g transform="translate(dx,dy) scale(scale)">
                    $svg = preg_replace_callback(
                        '/(<svg[^>]*>)(.*)(<\/svg>)/s',
                        function($matches) use ($dx, $dy, $scale) {
                            return $matches[1] . '<g transform="translate(' . round($dx,2) . ',' . round($dy,2) . ') scale(' . round($scale,4) . ')">' . $matches[2] . '</g>' . $matches[3];
                        },
                        $svg
                    );
                }
                // Nastav fill="#fff" na hlavní <svg>
                $svg = preg_replace('/<svg /', '<svg fill="#fff" ', $svg, 1);
                // Přidej width/height 100% a style
                $svg = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg, 1);
                $slug = str_replace(':', '-', $type);
                $filename = $icons_dir . $slug . '.svg';
                file_put_contents($filename, $svg);
            }
        }
        // Uložení barvy do term meta podle prefixu
        if ( preg_match('/^(poi_type|rv_type):(\d+)$/', $type, $m) ) {
            $term_id = intval($m[2]);
            if ( $term_id ) {
                update_term_meta($term_id, 'color_hex', $color);
                update_term_meta($term_id, 'icon_slug', $m[1] . '-' . $term_id);
            }
        }
        // Pro charger_type se ikony a barvy neukládají - používají se automaticky
    }

    public function render_page() {
        echo '<div class="wrap"><h1>' . esc_html__('Správa ikon', 'dobity-baterky') . '</h1>';
        echo '<p>' . esc_html__('Přiřaďte SVG ikony a barvy jednotlivým typům. Pokud typ nemá ikonu, bude zvýrazněn.', 'dobity-baterky') . '</p>';
        
        // Přidání tabů pro různé sekce
        $active_tab = isset($_GET['tab']) ? $_GET['tab'] : 'icons';
        echo '<h2 class="nav-tab-wrapper">';
        echo '<a href="?page=db-icon-admin&tab=icons" class="nav-tab ' . ($active_tab == 'icons' ? 'nav-tab-active' : '') . '">Správa ikon</a>';
        echo '<a href="?page=db-icon-admin&tab=api" class="nav-tab ' . ($active_tab == 'api' ? 'nav-tab-active' : '') . '">API nastavení</a>';
        echo '<a href="?page=db-icon-admin&tab=poi_color" class="nav-tab ' . ($active_tab == 'poi_color' ? 'nav-tab-active' : '') . '">Barva POI pinů</a>';
        echo '<a href="?page=db-icon-admin&tab=charger_colors" class="nav-tab ' . ($active_tab == 'charger_colors' ? 'nav-tab-active' : '') . '">Barvy nabíječek</a>';
        echo '<a href="?page=db-icon-admin&tab=rv_color" class="nav-tab ' . ($active_tab == 'rv_color' ? 'nav-tab-active' : '') . '">Barva RV pinů</a>';
        echo '</h2>';
        
        if ($active_tab == 'api') {
            $this->render_api_settings();
        } elseif ($active_tab == 'poi_color') {
            $this->render_poi_color_settings();
        } elseif ($active_tab == 'charger_colors') {
            $this->render_charger_colors_settings();
        } elseif ($active_tab == 'rv_color') {
            $this->render_rv_color_settings();
        } else {
            $this->render_icons_table();
        }
        echo '</div>';
    }
    
    private function render_api_settings() {
        if (isset($_POST['save_google_api_settings'])) {
            if (wp_verify_nonce($_POST['api_nonce'], 'save_api_settings')) {
                update_option('db_google_api_key', sanitize_text_field($_POST['google_api_key']));
                echo '<div class="notice notice-success"><p>Google API nastavení bylo uloženo.</p></div>';
            }
        }
        
        if (isset($_POST['save_tomtom_api_settings'])) {
            if (wp_verify_nonce($_POST['api_nonce'], 'save_api_settings')) {
                update_option('db_tomtom_api_key', sanitize_text_field($_POST['tomtom_api_key']));
                echo '<div class="notice notice-success"><p>TomTom API nastavení bylo uloženo.</p></div>';
            }
        }
        
        if (isset($_POST['save_openchargemap_api_settings'])) {
            if (wp_verify_nonce($_POST['api_nonce'], 'save_api_settings')) {
                update_option('db_openchargemap_api_key', sanitize_text_field($_POST['openchargemap_api_key']));
                echo '<div class="notice notice-success"><p>OpenChargeMap API nastavení bylo uloženo.</p></div>';
            }
        }
        
        if (isset($_POST['save_ors_api_settings'])) {
            if (wp_verify_nonce($_POST['api_nonce'], 'save_api_settings')) {
                // Aktualizovat konfiguraci nearby places
                $config = get_option('db_nearby_config', array());
                $config['ors_api_key'] = sanitize_text_field($_POST['ors_api_key']);
                update_option('db_nearby_config', $config);
                echo '<div class="notice notice-success"><p>OpenRouteService API nastavení bylo uloženo.</p></div>';
            }
        }
        
        $google_api_key = get_option('db_google_api_key', '');
        $tomtom_api_key = get_option('db_tomtom_api_key', '');
        $openchargemap_api_key = get_option('db_openchargemap_api_key', '');
        $config = get_option('db_nearby_config', array());
        $ors_api_key = $config['ors_api_key'] ?? '';
        $google_masked_key = $google_api_key ? str_repeat('•', min(strlen($google_api_key), 20)) : '';
        $tomtom_masked_key = $tomtom_api_key ? str_repeat('•', min(strlen($tomtom_api_key), 20)) : '';
        $openchargemap_masked_key = $openchargemap_api_key ? str_repeat('•', min(strlen($openchargemap_api_key), 20)) : '';
        $ors_masked_key = $ors_api_key ? str_repeat('•', min(strlen($ors_api_key), 20)) : '';
        ?>
        <div class="card">
            <h2>Google API nastavení</h2>
            <p>API klíč pro Google Places API. Potřebný pro funkci "Přidat místo" na frontendu.</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label>Google API klíč</label></th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="text" id="google_api_key_display" value="<?php echo esc_attr($google_masked_key); ?>" class="regular-text" readonly style="background-color: #f0f0f0;" />
                            <button type="button" class="button" onclick="showGoogleApiKeyDialog()">Upravit</button>
                        </div>
                        <p class="description">
                            Získejte klíč na <a href="https://console.cloud.google.com/" target="_blank">Google Cloud Console</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card">
            <h2>TomTom API nastavení</h2>
            <p>API klíč pro TomTom EV Search API. Potřebný pro načítání dat o nabíjecích stanicích.</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label>TomTom API klíč</label></th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="text" id="tomtom_api_key_display" value="<?php echo esc_attr($tomtom_masked_key); ?>" class="regular-text" readonly style="background-color: #f0f0f0;" />
                            <button type="button" class="button" onclick="showTomTomApiKeyDialog()">Upravit</button>
                        </div>
                        <p class="description">
                            Získejte klíč na <a href="https://developer.tomtom.com/" target="_blank">TomTom Developer Portal</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card">
            <h2>OpenChargeMap API nastavení</h2>
            <p>API klíč pro OpenChargeMap API. Potřebný pro vyhledávání nabíjecích stanic.</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label>OpenChargeMap API klíč</label></th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="text" id="openchargemap_api_key_display" value="<?php echo esc_attr($openchargemap_masked_key); ?>" class="regular-text" readonly style="background-color: #f0f0f0;" />
                            <button type="button" class="button" onclick="showOpenChargeMapApiKeyDialog()">Upravit</button>
                        </div>
                        <p class="description">
                            Získejte klíč na <a href="https://openchargemap.org/site/develop/api" target="_blank">OpenChargeMap Developer Portal</a>.
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <div class="card">
            <h2>OpenRouteService API nastavení</h2>
            <p>API klíč pro OpenRouteService API. Potřebný pro výpočet reálných vzdáleností a časů chůze v "Blízké body".</p>
            
            <table class="form-table">
                <tr>
                    <th scope="row"><label>OpenRouteService API klíč</label></th>
                    <td>
                        <div style="display: flex; align-items: center; gap: 10px;">
                            <input type="text" id="ors_api_key_display" value="<?php echo esc_attr($ors_masked_key); ?>" class="regular-text" readonly style="background-color: #f0f0f0;" />
                            <button type="button" class="button" onclick="showOrsApiKeyDialog()">Upravit</button>
                        </div>
                        <p class="description">
                            Získejte klíč na <a href="https://openrouteservice.org/" target="_blank">OpenRouteService</a> (zdarma).
                        </p>
                    </td>
                </tr>
            </table>
        </div>

        <!-- Modal dialog pro editaci Google API klíče -->
        <div id="google_api_key_modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
            <div style="background-color: white; margin: 15% auto; padding: 20px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Upravit Google API klíč</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('save_api_settings', 'api_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="google_api_key">Google API klíč</label></th>
                            <td>
                                <input type="text" id="google_api_key" name="google_api_key" value="<?php echo esc_attr($google_api_key); ?>" class="regular-text" style="width: 100%;" />
                                <p class="description">Vložte váš Google API klíč pro Places API</p>
                            </td>
                        </tr>
                    </table>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="button" onclick="closeGoogleApiKeyDialog()">Zrušit</button>
                        <input type="submit" name="save_google_api_settings" class="button-primary" value="Uložit" style="margin-left: 10px;" />
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal dialog pro editaci TomTom API klíče -->
        <div id="tomtom_api_key_modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
            <div style="background-color: white; margin: 15% auto; padding: 20px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Upravit TomTom API klíč</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('save_api_settings', 'api_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="tomtom_api_key">TomTom API klíč</label></th>
                            <td>
                                <input type="text" id="tomtom_api_key" name="tomtom_api_key" value="<?php echo esc_attr($tomtom_api_key); ?>" class="regular-text" style="width: 100%;" />
                                <p class="description">Vložte váš TomTom API klíč pro EV Search API</p>
                            </td>
                        </tr>
                    </table>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="button" onclick="closeTomTomApiKeyDialog()">Zrušit</button>
                        <input type="submit" name="save_tomtom_api_settings" class="button-primary" value="Uložit" style="margin-left: 10px;" />
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal dialog pro editaci OpenChargeMap API klíče -->
        <div id="openchargemap_api_key_modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
            <div style="background-color: white; margin: 15% auto; padding: 20px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Upravit OpenChargeMap API klíč</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('save_api_settings', 'api_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="openchargemap_api_key">OpenChargeMap API klíč</label></th>
                            <td>
                                <input type="text" id="openchargemap_api_key" name="openchargemap_api_key" value="<?php echo esc_attr($openchargemap_api_key); ?>" class="regular-text" style="width: 100%;" />
                                <p class="description">Vložte váš OpenChargeMap API klíč pro vyhledávání nabíjecích stanic</p>
                            </td>
                        </tr>
                    </table>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="button" onclick="closeOpenChargeMapApiKeyDialog()">Zrušit</button>
                        <input type="submit" name="save_openchargemap_api_settings" class="button-primary" value="Uložit" style="margin-left: 10px;" />
                    </div>
                </form>
            </div>
        </div>

        <!-- Modal dialog pro editaci OpenRouteService API klíče -->
        <div id="ors_api_key_modal" style="display: none; position: fixed; z-index: 100000; left: 0; top: 0; width: 100%; height: 100%; background-color: rgba(0,0,0,0.5);">
            <div style="background-color: white; margin: 15% auto; padding: 20px; border-radius: 5px; width: 500px; max-width: 90%;">
                <h3>Upravit OpenRouteService API klíč</h3>
                <form method="post" action="">
                    <?php wp_nonce_field('save_api_settings', 'api_nonce'); ?>
                    <table class="form-table">
                        <tr>
                            <th scope="row"><label for="ors_api_key">OpenRouteService API klíč</label></th>
                            <td>
                                <input type="text" id="ors_api_key" name="ors_api_key" value="<?php echo esc_attr($ors_api_key); ?>" class="regular-text" style="width: 100%;" />
                                <p class="description">Vložte váš OpenRouteService API klíč pro výpočet reálných vzdáleností a časů chůze</p>
                            </td>
                        </tr>
                    </table>
                    <div style="text-align: right; margin-top: 20px;">
                        <button type="button" class="button" onclick="closeOrsApiKeyDialog()">Zrušit</button>
                        <input type="submit" name="save_ors_api_settings" class="button-primary" value="Uložit" style="margin-left: 10px;" />
                    </div>
                </form>
            </div>
        </div>

        <script>
        function showGoogleApiKeyDialog() {
            document.getElementById('google_api_key_modal').style.display = 'block';
        }
        
        function closeGoogleApiKeyDialog() {
            document.getElementById('google_api_key_modal').style.display = 'none';
        }
        
        function showTomTomApiKeyDialog() {
            document.getElementById('tomtom_api_key_modal').style.display = 'block';
        }
        
        function closeTomTomApiKeyDialog() {
            document.getElementById('tomtom_api_key_modal').style.display = 'none';
        }

        function showOpenChargeMapApiKeyDialog() {
            document.getElementById('openchargemap_api_key_modal').style.display = 'block';
        }
        
        function closeOpenChargeMapApiKeyDialog() {
            document.getElementById('openchargemap_api_key_modal').style.display = 'none';
        }

        function showOrsApiKeyDialog() {
            document.getElementById('ors_api_key_modal').style.display = 'block';
        }
        
        function closeOrsApiKeyDialog() {
            document.getElementById('ors_api_key_modal').style.display = 'none';
        }
        
        // Zavřít modaly při kliknutí mimo něj
        window.onclick = function(event) {
            var googleModal = document.getElementById('google_api_key_modal');
            var tomtomModal = document.getElementById('tomtom_api_key_modal');
            var openchargemapModal = document.getElementById('openchargemap_api_key_modal');
            var orsModal = document.getElementById('ors_api_key_modal');
            if (event.target == googleModal) {
                closeGoogleApiKeyDialog();
            }
            if (event.target == tomtomModal) {
                closeTomTomApiKeyDialog();
            }
            if (event.target == openchargemapModal) {
                closeOpenChargeMapApiKeyDialog();
            }
            if (event.target == orsModal) {
                closeOrsApiKeyDialog();
            }
        }
        </script>
        <?php
    }
    
    private function render_poi_color_settings() {
        if (isset($_POST['save_poi_color'])) {
            if (wp_verify_nonce($_POST['poi_color_nonce'], 'save_poi_color')) {
                $color = isset($_POST['db_poi_color']) ? sanitize_hex_color($_POST['db_poi_color']) : '';
                if (!$color) { $color = '#FCE67D'; }
                update_option('db_poi_color', $color);
                $icon_color = isset($_POST['db_poi_icon_color']) ? sanitize_hex_color($_POST['db_poi_icon_color']) : '';
                if (!$icon_color) { $icon_color = '#049FE8'; }
                update_option('db_poi_icon_color', $icon_color);
                echo '<div class="notice notice-success"><p>Barvy POI uloženy.</p></div>';
            }
        }
        $current = get_option('db_poi_color', '#FCE67D');
        if (!is_string($current) || !preg_match('/^#[0-9a-fA-F]{6}$/', $current)) {
            $current = '#FCE67D';
        }
        $current_icon = get_option('db_poi_icon_color', '#049FE8');
        if (!is_string($current_icon) || !preg_match('/^#[0-9a-fA-F]{6}$/', $current_icon)) {
            $current_icon = '#049FE8';
        }
        ?>
        <div class="card">
            <h2>Barva POI pinů</h2>
            <p>Centrální barva výplně pinů pro všechny POI. Tuto barvu zdědí i komponenty na frontendu, které dosud používaly pevnou barvu.</p>
            <form method="post" action="">
                <?php wp_nonce_field('save_poi_color', 'poi_color_nonce'); ?>
                <table class="form-table">
                    <tr>
                        <th scope="row"><label for="db_poi_color">Barva (HEX)</label></th>
                        <td>
                            <input type="text" id="db_poi_color" name="db_poi_color" value="<?php echo esc_attr($current); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_poi_color_picker" value="<?php echo esc_attr($current); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                            <p class="description">Výchozí je #FCE67D dle brandbooku.</p>
                        </td>
                    </tr>
                    <tr>
                        <th scope="row"><label for="db_poi_icon_color">Barva SVG ikony (HEX)</label></th>
                        <td>
                            <input type="text" id="db_poi_icon_color" name="db_poi_icon_color" value="<?php echo esc_attr($current_icon); ?>" class="regular-text" style="width:120px;" />
                            <input type="color" id="db_poi_icon_color_picker" value="<?php echo esc_attr($current_icon); ?>" style="width:40px;height:40px;vertical-align:middle;margin-left:8px;" />
                            <p class="description">Barva výplně/obrysu SVG ikony uvnitř pinu. Výchozí #049FE8.</p>
                        </td>
                    </tr>
                </table>
                <p>
                    <input type="submit" name="save_poi_color" class="button-primary" value="Uložit" />
                </p>
            </form>
        </div>
        <script>
        (function(){
            var txt = document.getElementById('db_poi_color');
            var picker = document.getElementById('db_poi_color_picker');
            picker.addEventListener('input', function(){ txt.value = picker.value; });
            txt.addEventListener('input', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ picker.value = txt.value; } });
            txt.addEventListener('change', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ picker.value = txt.value; } });
            var txt2 = document.getElementById('db_poi_icon_color');
            var picker2 = document.getElementById('db_poi_icon_color_picker');
            picker2.addEventListener('input', function(){ txt2.value = picker2.value; });
            txt2.addEventListener('input', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt2.value)){ picker2.value = txt2.value; } });
            txt2.addEventListener('change', function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt2.value)){ picker2.value = txt2.value; } });
        })();
        </script>
        <?php
    }
    
    private function render_icons_table() {
        echo '<table class="widefat fixed striped"><thead><tr><th>Typ</th><th>Ikona</th><th>Barva</th><th>Akce</th></tr></thead><tbody>';
        // POI typy
        $poi_types = get_terms( array('taxonomy'=>'poi_type','hide_empty'=>false) );
        foreach ( $poi_types as $term ) {
            $icon_slug = get_term_meta($term->term_id, 'icon_slug', true);
            $color = get_term_meta($term->term_id, 'color_hex', true) ?: '#FF6A4B';
            
            echo '<tr><td><strong>POI: ' . esc_html($term->name) . '</strong><br><small>ID: ' . $term->term_id . ', icon_slug: ' . esc_html($icon_slug) . '</small></td>';
            echo '<td>';
            $pin = '<svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="' . esc_attr($color) . '"/></svg>';
            
            if ($icon_slug) {
                // Preferovat uploads cestu, fallback do assets
                $up = wp_upload_dir();
                $uploads_icon = trailingslashit($up['basedir']) . 'dobity-baterky/icons/' . $icon_slug . '.svg';
                $assets_icon = DB_PLUGIN_DIR . 'assets/icons/' . $icon_slug . '.svg';
                $chosen = file_exists($uploads_icon) ? $uploads_icon : (file_exists($assets_icon) ? $assets_icon : '');
                if ($chosen) {
                    $svg = file_get_contents($chosen);
                // Úprava SVG: odstranění width/height a přidání width="100%" height="100%" style="display:block;"
                $svg = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg);
                $svg = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg);
                $svg = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg, 1);
                // Nastav fill a stroke na bílé
                $svg = preg_replace('/fill="[^"]*"/', 'fill="#fff"', $svg);
                $svg = preg_replace('/stroke="[^"]*"/', 'stroke="#fff"', $svg);
                echo '<div style="position:relative;width:32px;height:32px;display:inline-block;">' . $pin . '<div style="position:absolute;left:8px;top:6px;width:16px;height:16px;display:flex;align-items:center;justify-content:center;">' . $svg . '</div></div>';
                } else {
                echo '<div style="width:32px;height:32px;display:inline-block;">' . $pin . '</div>';
                }
            } else {
                echo '<div style="width:32px;height:32px;display:inline-block;">' . $pin . '</div>';
            }
            echo '</td>';
            echo '<td><span style="display:inline-block;width:24px;height:24px;background:' . esc_attr($color) . ';border:1px solid #ccc;"></span> ' . esc_html($color) . '</td>';
            echo '<td>';
            $this->render_form('poi_type:' . $term->term_id, $color);
            echo '</td></tr>';
        }
        // Typy nabíječek - pouze informace (ikony se nastavují automaticky)
        $charger_types = get_terms( array('taxonomy'=>'charger_type','hide_empty'=>false) );
        foreach ( $charger_types as $term ) {
            echo '<tr><td>' . esc_html($term->name) . ' <small>(Nabíječka - ikona se nastavuje automaticky)</small></td>';
            echo '<td><em>Automaticky: charger_type-198.svg</em></td>';
            echo '<td><em>Barva se určuje podle AC/DC</em></td>';
            echo '<td><em>Bez možnosti úpravy</em></td></tr>';
        }
        // Typy RV stání (dynamicky z taxonomy)
        $rv_types = get_terms( array('taxonomy'=>'rv_type','hide_empty'=>false) );
        foreach ( $rv_types as $term ) {
            $icon_slug = get_term_meta($term->term_id, 'icon_slug', true);
            $color = get_term_meta($term->term_id, 'color_hex', true) ?: '#A27CFF';
            echo '<tr><td>' . esc_html($term->name) . ' <small>(RV)</small></td>';
            echo '<td>';
            $pin = '<svg width="32" height="32" viewBox="0 0 32 32" xmlns="http://www.w3.org/2000/svg"><path d="M16 2C9.372 2 4 7.372 4 14c0 6.075 8.06 14.53 11.293 17.293a1 1 0 0 0 1.414 0C19.94 28.53 28 20.075 28 14c0-6.628-5.372-12-12-12z" fill="' . esc_attr($color) . '"/></svg>';
            if ($icon_slug) {
                $up = wp_upload_dir();
                $uploads_icon = trailingslashit($up['basedir']) . 'dobity-baterky/icons/' . $icon_slug . '.svg';
                $assets_icon = DB_PLUGIN_DIR . 'assets/icons/' . $icon_slug . '.svg';
                $chosen = file_exists($uploads_icon) ? $uploads_icon : (file_exists($assets_icon) ? $assets_icon : '');
                if ($chosen) {
                    $svg = file_get_contents($chosen);
                // Úprava SVG: odstranění width/height a přidání width="100%" height="100%" style="display:block;"
                $svg = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg);
                $svg = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg);
                $svg = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg, 1);
                // Nastav fill a stroke na bílé
                $svg = preg_replace('/fill="[^"]*"/', 'fill="#fff"', $svg);
                $svg = preg_replace('/stroke="[^"]*"/', 'stroke="#fff"', $svg);
                echo '<div style="position:relative;width:32px;height:32px;display:inline-block;">' . $pin . '<div style="position:absolute;left:8px;top:6px;width:16px;height:16px;display:flex;align-items:center;justify-content:center;">' . $svg . '</div></div>';
                } else {
                echo '<div style="width:32px;height:32px;display:inline-block;">' . $pin . '</div>';
                }
            } else {
                echo '<div style="width:32px;height:32px;display:inline-block;">' . $pin . '</div>';
            }
            echo '</td>';
            echo '<td><span style="display:inline-block;width:24px;height:24px;background:' . esc_attr($color) . ';border:1px solid #ccc;"></span> ' . esc_html($color) . '</td>';
            echo '<td>';
            $this->render_form('rv_type:' . $term->term_id, $color);
            echo '</td></tr>';
        }
        echo '</tbody></table>';
        // Globální JS pro generování barvy
        echo <<<JS
<script>
window.dbGenColor = function(inputId) {
    var used = Array.from(document.querySelectorAll("input[name$=\"icon_color\"]"))
        .map(function(i){return i.value.toLowerCase().replace(/[^#a-f0-9]/g,'');})
        .filter(function(v){return v.length === 7;});
    var color;
    do {
        color = "#" + Math.floor(Math.random()*16777215).toString(16).padStart(6,"0");
    } while (used.includes(color));
    var t = document.getElementById(inputId);
    var p = document.getElementById("picker_"+inputId.replace("color_",""));
    t.value = color;
    if(p) p.value = color;
};
</script>
JS;
    }

    private function render_form($type, $color) {
        $uniq = uniqid('color_', true);
        $color_val = (is_string($color) && preg_match('/^#[0-9a-fA-F]{6}$/', $color)) ? $color : '';
        
        // Zjisti, jestli je to POI typ
        $is_poi_type = strpos($type, 'poi_type:') === 0;
        
        // Zjisti slug a existenci SVG dekorace
        if (preg_match('/^(poi_type|charger_type|rv_type):([0-9]+)$/', $type, $m)) {
            $slug = $m[1] . '-' . $m[2];
            $up = wp_upload_dir();
            $uploads_icon = trailingslashit($up['basedir']) . 'dobity-baterky/icons/' . $slug . '.svg';
            $assets_icon = DB_PLUGIN_DIR . 'assets/icons/' . $slug . '.svg';
            $has_svg = file_exists($uploads_icon) || file_exists($assets_icon);
        } else {
            $slug = '';
            $has_svg = false;
        }
        
        echo '<form method="post" enctype="multipart/form-data" style="display:inline-block;min-width:200px;" id="form_' . $uniq . '">';
        wp_nonce_field('db_icon_admin_save', 'db_icon_admin_nonce');
        echo '<input type="hidden" name="icon_type" value="' . esc_attr($type) . '" />';
        echo '<input type="file" name="icon_svg" accept="image/svg+xml" style="width:120px;" /> ';
        
        if ($is_poi_type) {
            // Pro POI typy - barva je pevná, zobrazit jako disabled
            echo '<input type="text" id="' . $uniq . '" name="icon_color" value="' . esc_attr($color) . '" placeholder="#HEX" style="width:80px;background-color:#f0f0f0;" readonly /> ';
            echo '<input type="color" id="picker_' . $uniq . '" value="' . esc_attr($color_val) . '" style="width:32px;height:32px;vertical-align:middle;" disabled /> ';
            echo '<button type="button" class="button" disabled>Generovat barvu</button> ';
        } else {
            // Pro ostatní typy - normální formulář
            echo '<input type="text" id="' . $uniq . '" name="icon_color" value="' . esc_attr($color) . '" placeholder="#HEX" style="width:80px;" /> ';
            echo '<input type="color" id="picker_' . $uniq . '" value="' . esc_attr($color_val) . '" style="width:32px;height:32px;vertical-align:middle;" /> ';
            echo '<button type="button" class="button" onclick="dbGenColor(\'' . $uniq . '\')">Generovat barvu</button> ';
        }
        
        echo '<button type="submit" class="button">Uložit</button>';
        if ($has_svg) {
            echo ' <button type="submit" name="delete_icon_svg" value="1" class="button">Smazat dekoraci</button>';
        }
        echo '</form>';
        // Synchronizace polí (pouze pro ne-POI typy)
        if (!$is_poi_type) {
            echo <<<JS
<script>
(function(){
    var txt = document.getElementById("$uniq");
    var picker = document.getElementById("picker_$uniq");
    var form = document.getElementById("form_$uniq");
    picker.addEventListener("input", function(){ txt.value = picker.value; });
    txt.addEventListener("input", function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ picker.value = txt.value; } });
    txt.addEventListener("change", function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ picker.value = txt.value; } });
    form.addEventListener("submit", function(){ if(/^#[0-9a-fA-F]{6}$/.test(txt.value)){ picker.value = txt.value; } });
})();
</script>
JS;
        }
    }










} 