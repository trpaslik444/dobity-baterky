<?php
/**
 * Frontendová mapa – shortcode a assety
 * @package DobityBaterky
 */

namespace DB;

class Frontend_Map {
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register() {
        // Shortcode a assety se registrují ručně v hlavním souboru s ochranou
        // add_shortcode( 'db_map', array( $this, 'render_shortcode' ) );
        // add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    public function enqueue_assets() {
        // ⛔️ Nepovolaným vůbec nenačítej JS/CSS mapy
        if ( ! function_exists('db_user_can_see_map') || ! db_user_can_see_map() ) {
            return;
        }

        // Zkontrolovat, zda je to mapová stránka
        $is_map_page = false;
        
        // Kontrola 1: stránka obsahující [db_map] shortcode
        global $post;
        if ( $post && has_shortcode( (string) $post->post_content, 'db_map' ) ) {
            $is_map_page = true;
        }
        
        // Kontrola 2: funkce db_is_map_app_page (pokud existuje)
        if ( function_exists('db_is_map_app_page') && db_is_map_app_page() ) {
            $is_map_page = true;
        }
        
        // Načítat assety pouze na mapových stránkách
        if ( ! $is_map_page ) {
            return;
        }
        
        // CSS - správné pořadí
        wp_enqueue_style( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.css', array(), '1.9.4' );
        wp_enqueue_style( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.css', array('leaflet'), '1.5.3' );
        wp_enqueue_style( 'leaflet-markercluster-default', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/MarkerCluster.Default.css', array('leaflet-markercluster'), '1.5.3' );
        wp_enqueue_style( 'db-map', plugins_url( 'assets/db-map.css', DB_PLUGIN_FILE ), array('leaflet','leaflet-markercluster'), DB_PLUGIN_VERSION );
        
        // JavaScript - správné pořadí: Leaflet -> MarkerCluster -> vlastní
        wp_enqueue_script( 'leaflet', 'https://unpkg.com/leaflet@1.9.4/dist/leaflet.js', array(), '1.9.4', true );
        wp_enqueue_script( 'leaflet-markercluster', 'https://unpkg.com/leaflet.markercluster@1.5.3/dist/leaflet.markercluster.js', array('leaflet'), '1.5.3', true );
        wp_enqueue_script( 'db-map', plugins_url( 'assets/db-map.js', DB_PLUGIN_FILE ), array('leaflet','leaflet-markercluster'), DB_PLUGIN_VERSION, true );
        
        // PWA optimalizace - dočasně deaktivováno pro testování
        // if (function_exists('db_is_map_app_page') && db_is_map_app_page()) {
        //     // PWA for WP plugin už poskytuje základní PWA funkcionalitu
        //     // Přidáme pouze specifické styly pro mapu
        //     wp_enqueue_style( 'db-pwa-map-styles', plugins_url( 'assets/pwa-map-styles.css', DB_PLUGIN_FILE ), array('db-map'), DB_PLUGIN_VERSION );
        // }
        
        // Data pro JS
        $favorites_payload = array(
            'enabled' => false,
        );

        if ( is_user_logged_in() && class_exists( '\\DB\\Favorites_Manager' ) ) {
            try {
                $favorites_manager = Favorites_Manager::get_instance();
                $favorites_payload = $favorites_manager->get_localized_payload( get_current_user_id() );
            } catch ( \Throwable $e ) {
                $favorites_payload = array( 'enabled' => false );
            }
        }

        wp_localize_script( 'db-map', 'dbMapData', array(
            'restUrl'   => rest_url( 'db/v1/map' ),
            'searchUrl' => rest_url( 'db/v1/map-search' ),
            'poiExternalUrl' => rest_url( 'db/v1/poi-external' ),
            'chargingExternalUrl' => rest_url( 'db/v1/charging-external' ),
            'restNonce' => wp_create_nonce( 'wp_rest' ),
            'iconsBase' => plugins_url( 'assets/icons/', DB_PLUGIN_FILE ),
            'pluginUrl' => plugins_url( '/', DB_PLUGIN_FILE ),
            'dbLogoUrl' => plugins_url( 'assets/DB_bez(2160px).svg', DB_PLUGIN_FILE ),
            'isMapPage' => function_exists('db_is_map_app_page') ? db_is_map_app_page() : false,
            'pwaEnabled' => class_exists('PWAforWP') ? true : false,
            'ajaxUrl'   => admin_url('admin-ajax.php'),
            'googleApiKey' => get_option('db_google_api_key'),
            'chargerIconColor' => get_option('db_charger_icon_color', '#049FE8'),
            'favorites' => $favorites_payload,
        ) );
        
    }

    public function render_shortcode( $atts ) {
        // Guard: nepovolaným nevyrenderovat HTML vůbec
        if ( ! function_exists('db_user_can_see_map') || ! db_user_can_see_map() ) {
            if ( ! is_user_logged_in() ) {
                $login_url = wp_login_url( get_permalink() );
                return '<p>Pro zobrazení mapy se prosím <a href="'. esc_url($login_url) .'">přihlas</a>.</p>';
            }
            return '<p>Tento obsah je dostupný jen pro oprávněné uživatele.</p>';
        }

        // Výchozí filtry (v base64 pro JS)
        $default = array(
            'included' => 'charger,rv_spot,poi',
            'provider' => 'all',
            'poi_type' => 'all',
        );
        $data = base64_encode( json_encode( $default ) );
        ob_start();
        ?>
        <div id="db-map-filters" data-default="<?php echo esc_attr($data); ?>"></div>
        <div id="db-map" style="width:100%;"></div>
        <?php
        return ob_get_clean();
    }
} 