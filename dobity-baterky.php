<?php
/**
 * Plugin Name: Dobitý Baterky – Elektromobilní průvodce
 * Plugin URI:  https://example.com/dobity-baterky
 * Description: Interaktivní průvodce nabíjecími stanicemi s pokročilým systémem správy nearby bodů a automatizovaným zpracováním dat.
 * Version:     2.0.7
 * Author:      Ondřej Plas
 * Text Domain: dobity-baterky
 * Domain Path: /languages
 * Requires at least: 5.0
 * Tested up to: 6.8
 * Requires PHP: 7.4
 */

// Bezpečnostní kontrola
if ( ! defined( 'ABSPATH' ) ) exit;

// Debug informace pro produkci
// Debug logging pouze v development módu
if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'DB_DEBUG' ) && DB_DEBUG ) {
    error_log( "[DB DEBUG] Plugin se načítá" );
}

// Zabránit duplicitní inicializaci
if ( defined( 'DB_PLUGIN_LOADED' ) ) {
    return;
}
define( 'DB_PLUGIN_LOADED', true );

// Definice konstant
if ( ! defined( 'DB_PLUGIN_FILE' ) ) {
    define( 'DB_PLUGIN_FILE', __FILE__ );
}
if ( ! defined( 'DB_PLUGIN_DIR' ) ) {
    define( 'DB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
}
if ( ! defined( 'DB_PLUGIN_URL' ) ) {
    define( 'DB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
}
if ( ! defined( 'DB_PLUGIN_VERSION' ) ) {
    define( 'DB_PLUGIN_VERSION', '2.0.7' );
}
if ( ! defined( 'DB_MAP_ROUTE_QUERY_VAR' ) ) {
    define( 'DB_MAP_ROUTE_QUERY_VAR', 'db_map_app' );
}

// -----------------------------------------------------------------------------
// Access helpers – jednotná kontrola přístupu k mapové appce
// -----------------------------------------------------------------------------

if ( ! function_exists('db_required_capability') ) {
    /**
     * Název capability, která dává přístup k mapové appce.
     * Default: 'access_app'. Lze změnit přes filtr: add_filter('db_required_capability', fn() => 'custom_cap');
     */
    function db_required_capability(): string {
        return apply_filters('db_required_capability', 'access_app');
    }
}

if ( ! function_exists('db_ensure_capability_exists') ) {
    /**
     * Zajistí, že požadovaná capability existuje v Members pluginu
     */
    function db_ensure_capability_exists() {
        if ( ! function_exists('members_register_cap') ) {
            // Pokud Members není dostupný, vytvoříme capability ručně
            add_action('init', function() {
                $cap = db_required_capability();
                if ( $cap && ! get_role('administrator')->has_cap($cap) ) {
                    // Přidáme capability k admin roli jako fallback
                    $admin_role = get_role('administrator');
                    if ( $admin_role ) {
                        $admin_role->add_cap($cap);
                    }
                }
            }, 30);
            return;
        }
        
        $cap = db_required_capability();
        if ( $cap && function_exists('members_capability_exists') && ! members_capability_exists( $cap ) ) {
            members_register_cap( $cap, array(
                'label' => 'Přístup k mapové aplikaci',
                'description' => 'Umožňuje zobrazit mapu Dobitý Baterky',
                'group' => 'dobity-baterky'
            ) );
        }
    }
}

if ( ! function_exists( 'db_map_route_slug' ) ) {
    /**
     * Vrací slug mapové aplikace (např. /mapa). Lze upravit filtrem.
     */
    function db_map_route_slug(): string {
        $slug = apply_filters( 'db_map_route_slug', 'mapa' );
        $slug = trim( (string) $slug );
        return $slug !== '' ? trim( $slug, '/' ) : 'mapa';
    }
}

if ( ! function_exists( 'db_register_map_route' ) ) {
    /**
     * Zaregistruje rewrite pravidlo pro mapovou aplikaci.
     */
    function db_register_map_route(): void {
        $slug = db_map_route_slug();
        add_rewrite_rule(
            '^' . preg_quote( $slug, '/' ) . '/?$',
            'index.php?' . DB_MAP_ROUTE_QUERY_VAR . '=1',
            'top'
        );
    }
}

if ( ! function_exists('db_user_can_see_map') ) {
    /**
     * Kontrola přístupu s fallbackem pro případ bez Members pluginu
     */
    function db_user_can_see_map(): bool {
        // Pokud není uživatel přihlášen, nemá přístup
        if ( ! is_user_logged_in() ) {
            return false;
        }
        
        // Admin a Editor mají vždy přístup
        if ( current_user_can('administrator') || current_user_can('editor') ) {
            return true;
        }
        
        // Načíst plugin.php, pokud není načteno (potřeba pro is_plugin_active na front-endu)
        if ( ! function_exists('is_plugin_active') ) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        
        // Pokud je Members plugin aktivní, kontrolujeme 'access_app' capability
        if ( function_exists('is_plugin_active') && is_plugin_active('members/members.php') ) {
            $cap = db_required_capability();
            
            // Debug informace (pouze pro adminy)
            if ( current_user_can('administrator') && defined('WP_DEBUG') && WP_DEBUG ) {
                $current_user = wp_get_current_user();
                $user_login = ($current_user instanceof WP_User) ? $current_user->user_login : '';
                $has_cap = current_user_can($cap) ? 'YES' : 'NO';
                $is_logged = is_user_logged_in() ? 'YES' : 'NO';
                $message = sprintf('[DB MAP DEBUG] User: %s, Cap: %s, Has cap: %s, Is logged in: %s', $user_login, $cap, $has_cap, $is_logged);
                error_log($message);
            }
            
            // Pokud má uživatel požadovanou capability, má přístup
            if ( $cap && current_user_can($cap) ) {
                return true;
            }
            
            return false;
        }
        
        // Pokud Members plugin není aktivní, povolíme přístup všem přihlášeným
        return true;
    }
}

if ( ! function_exists( 'db_is_map_frontend_context' ) ) {
    /**
     * Zjistí, zda aktuální request odpovídá mapové stránce dostupné oprávněnému uživateli.
     * Výsledek se memoizuje pro opakované použití v rámci requestu.
     */
    function db_is_map_frontend_context(): bool {
        static $is_map_request = null;

        if ( $is_map_request !== null ) {
            return $is_map_request;
        }

        if ( ! function_exists( 'db_user_can_see_map' ) || ! db_user_can_see_map() ) {
            $is_map_request = false;
            return $is_map_request;
        }

        $is_map_request = false;

        // Kontrola shortcode na aktuálním příspěvku/stránce
        global $post;
        if ( $post && has_shortcode( (string) $post->post_content, 'db_map' ) ) {
            $is_map_request = true;
        }

        // Dodatečná kontrola přes helper (pokud existuje)
        if ( function_exists( 'db_is_map_app_page' ) && db_is_map_app_page() ) {
            $is_map_request = true;
        }

        if ( intval( get_query_var( DB_MAP_ROUTE_QUERY_VAR ) ) === 1 ) {
            $is_map_request = true;
        }

        return $is_map_request;
    }
}

// PSR-4 Autoloader s debug a bezpečnostním wrapperem
spl_autoload_register( function ( $class ) {
    try {
        // Namespace prefix
        $prefix = 'DB\\';
        $base_dir = DB_PLUGIN_DIR . 'includes/';

        // Kontrola namespace
        $len = strlen( $prefix );
        if ( strncmp( $prefix, $class, $len ) !== 0 ) {
            return;
        }

        // Získání relativní třídy
        $relative_class = substr( $class, $len );

        // Nahrazení namespace separátorů za directory separátory
        $file = $base_dir . str_replace( '\\', '/', $relative_class ) . '.php';

        // Debug log pro produkci
        // Debug pouze v development módu
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG && defined( 'DB_DEBUG' ) && DB_DEBUG ) {
            error_log( "[DB DEBUG] Načítám: {$class}" );
        }

        // Načtení souboru pokud existuje
        if ( file_exists( $file ) ) {
            require_once $file;
            // Soubor načten úspěšně
        } else {
            // Soubor neexistuje - tichá chyba
        }
    } catch ( Exception $e ) {
        // Tichý error handling pro produkci
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[AUTOLOADER ERROR] Chyba při načítání {$class}: " . $e->getMessage() );
        }
        return;
    } catch ( Error $e ) {
        // Zachytit PHP 7+ Fatal Errors
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[AUTOLOADER FATAL] Fatal error při načítání {$class}: " . $e->getMessage() );
        }
        return;
    }
} );

// Načtení Database Optimizer
require_once DB_PLUGIN_DIR . 'includes/Database_Optimizer.php';

// Načtení On-Demand Processor
require_once DB_PLUGIN_DIR . 'includes/Jobs/On_Demand_Processor.php';
require_once DB_PLUGIN_DIR . 'includes/Jobs/Optimized_Worker_Manager.php';
require_once DB_PLUGIN_DIR . 'includes/REST_On_Demand.php';
require_once DB_PLUGIN_DIR . 'includes/REST_Isochrones.php';

// Hooky aktivace a deaktivace s bezpečnostním wrapperem
function db_safe_activate() {
    db_register_map_route();
    try {
        if ( class_exists( 'DB\Activation' ) ) {
            DB\Activation::activate();
        } else {
            // Fallback - základní aktivace
            flush_rewrite_rules();
            if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                error_log( "[ACTIVATION DEBUG] Třída Activation neexistuje, použít fallback" );
            }
        }
    } catch ( Exception $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[ACTIVATION ERROR] Chyba při aktivaci: " . $e->getMessage() );
        }
        // Fallback - alespoň základní aktivace
        flush_rewrite_rules();
    } catch ( Error $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[ACTIVATION FATAL] Fatal error při aktivaci: " . $e->getMessage() );
        }
        flush_rewrite_rules();
    }
}

function db_safe_deactivate() {
    try {
        if ( class_exists( 'DB\Activation' ) ) {
            DB\Activation::deactivate();
        } else {
            flush_rewrite_rules();
        }
    } catch ( Exception $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[DEACTIVATION ERROR] Chyba při deaktivaci: " . $e->getMessage() );
        }
        flush_rewrite_rules();
    } catch ( Error $e ) {
        if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
            error_log( "[DEACTIVATION FATAL] Fatal error při deaktivaci: " . $e->getMessage() );
        }
        flush_rewrite_rules();
    }
}

register_activation_hook( __FILE__, 'db_safe_activate' );
register_deactivation_hook( __FILE__, 'db_safe_deactivate' );

// Inicializace pluginu
load_plugin_textdomain( 'dobity-baterky', false, dirname( plugin_basename( __FILE__ ) ) . '/languages' );

// Kontrola existence třídy CPT před inicializací
try {
    if ( class_exists( 'DB\CPT' ) ) {
        DB\CPT::get_instance();
    } else {
        // CPT třída není dostupná
    }
} catch ( Exception $e ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log('[PLUGIN ERROR] Chyba při inicializaci CPT: ' . $e->getMessage());
    }
} catch ( Error $e ) {
    if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
        error_log('[PLUGIN FATAL] Fatal error při inicializaci CPT: ' . $e->getMessage());
    }
}

// Starý Meta_Boxes byl odstraněn - používá se Charging_Location_Form

if ( class_exists( 'DB\RV_Spot' ) ) {
    DB\RV_Spot::get_instance()->register();
}

if ( class_exists( 'DB\RV_Spot_Boxes' ) ) {
    DB\RV_Spot_Boxes::get_instance()->init();
}

if ( class_exists( 'DB\Spot_Zone' ) ) {
    DB\Spot_Zone::get_instance()->register();
}

if ( class_exists( 'DB\POI' ) ) {
    DB\POI::get_instance()->register();
}

if ( class_exists( 'DB\POI_Boxes' ) ) {
    DB\POI_Boxes::get_instance()->init();
}

// Registry ikon
if ( file_exists( __DIR__ . '/includes/Icon_Registry.php' ) ) {
    require_once __DIR__ . '/includes/Icon_Registry.php';
    if ( class_exists( 'DB\Icon_Registry' ) ) {
        DB\Icon_Registry::get_instance();
    }
}

// CPT Display Block (Gutenberg blok pro zobrazení jednotlivých záznamů)
if ( file_exists( __DIR__ . '/includes/CPT_Display_Block.php' ) ) {
    require_once __DIR__ . '/includes/CPT_Display_Block.php';
    if ( class_exists( 'DB\CPT_Display_Block' ) ) {
        DB\CPT_Display_Block::get_instance();
    }
}

// CPT URL Manager (správa URL a navigace pro místa)
if ( file_exists( __DIR__ . '/includes/CPT_URL_Manager.php' ) ) {
    require_once __DIR__ . '/includes/CPT_URL_Manager.php';
    if ( class_exists( 'DB\CPT_URL_Manager' ) ) {
        DB\CPT_URL_Manager::get_instance();
    }
}

// CPT Shortcodes (shortcode pro zobrazení odkazů na místa)
if ( file_exists( __DIR__ . '/includes/CPT_Shortcodes.php' ) ) {
    require_once __DIR__ . '/includes/CPT_Shortcodes.php';
    if ( class_exists( 'DB\CPT_Shortcodes' ) ) {
        DB\CPT_Shortcodes::get_instance();
    }
}

// REST endpoint pro mapu
if ( file_exists( __DIR__ . '/includes/REST_Map.php' ) ) {
    require_once __DIR__ . '/includes/REST_Map.php';
    if ( class_exists( 'DB\REST_Map' ) ) {
        DB\REST_Map::get_instance()->register();
    }
}

// Favorites Manager - načítá se před REST API
if ( file_exists( __DIR__ . '/includes/Favorites_Manager.php' ) ) {
    require_once __DIR__ . '/includes/Favorites_Manager.php';
}

// Favorites REST API
if ( file_exists( __DIR__ . '/includes/REST_Favorites.php' ) ) {
    require_once __DIR__ . '/includes/REST_Favorites.php';
    if ( class_exists( 'DB\REST_Favorites' ) ) {
        DB\REST_Favorites::get_instance()->register();
    }
}

// On-Demand REST API
if ( file_exists( __DIR__ . '/includes/REST_On_Demand.php' ) ) {
    require_once __DIR__ . '/includes/REST_On_Demand.php';
    if ( class_exists( 'DB\REST_On_Demand' ) ) {
        DB\REST_On_Demand::get_instance()->register();
    }
}

// Isochrones REST API
if ( file_exists( __DIR__ . '/includes/REST_Isochrones.php' ) ) {
    require_once __DIR__ . '/includes/REST_Isochrones.php';
    if ( class_exists( 'DB\REST_Isochrones' ) ) {
        DB\REST_Isochrones::get_instance()->register();
    }
}


// Feedback module (REST + frontend + admin)
if ( file_exists( __DIR__ . '/includes/Feedback.php' ) ) {
    require_once __DIR__ . '/includes/Feedback.php';
    if ( class_exists( 'DB\Feedback' ) ) {
        DB\Feedback::get_instance()->register();
    }
}

if ( file_exists( __DIR__ . '/includes/Feedback_Admin.php' ) ) {
    require_once __DIR__ . '/includes/Feedback_Admin.php';
    if ( class_exists( 'DB\Feedback_Admin' ) ) {
        DB\Feedback_Admin::get_instance()->register();
    }
}
// Early Adopters Mailing List Admin
if ( file_exists( __DIR__ . '/includes/Early_Adopters_Admin.php' ) ) {
    require_once __DIR__ . '/includes/Early_Adopters_Admin.php';
    if ( class_exists( 'DB\Early_Adopters_Admin' ) ) {
        DB\Early_Adopters_Admin::get_instance()->register();
    }
}

// Po načtení pluginu zkontrolovat existenci tabulek (bez nutnosti re-aktivace)
add_action('plugins_loaded', function() {
    if (class_exists('DB\\Activation')) {
        DB\Activation::ensure_tables();
    }
});

// Administrační stránka pro správu ikon (na konec)
if ( file_exists( __DIR__ . '/includes/Icon_Admin.php' ) ) {
    require_once __DIR__ . '/includes/Icon_Admin.php';
    if ( class_exists( 'DB\Icon_Admin' ) ) {
        DB\Icon_Admin::get_instance();
    }
}

// Charging Icon Manager - správa SVG ikon pro typy konektorů
if ( file_exists( __DIR__ . '/includes/Charging_Icon_Manager.php' ) ) {
    require_once __DIR__ . '/includes/Charging_Icon_Manager.php';
    if ( class_exists( 'DB\Charging_Icon_Manager' ) ) {
        DB\Charging_Icon_Manager::init();
    }
}

// Hlavní formulář pro charging_location
if ( file_exists( __DIR__ . '/includes/Charging_Location_Form.php' ) ) {
    require_once __DIR__ . '/includes/Charging_Location_Form.php';
    // Inicializace se provede v init hooku s vysokou prioritou pro AJAX handlery
    add_action('init', function() {
        // Inicializace Charging_Location_Form
        if (class_exists('DB\Charging_Location_Form')) {
            try {
                DB\Charging_Location_Form::get_instance()->init();
            } catch (Exception $e) {
                if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
                    error_log('[DB ERROR] Charging_Location_Form: ' . $e->getMessage());
                }
            }
        }
    }, 10);
}

// Frontendová mapa (shortcode) s ochranou - načítá se až po Charging_Location_Form
add_action('init', function() {
    if ( file_exists( __DIR__ . '/includes/Frontend_Map.php' ) ) {
        require_once __DIR__ . '/includes/Frontend_Map.php';
        if ( class_exists( 'DB\Frontend_Map' ) ) {
            $map = \DB\Frontend_Map::get_instance();
            
            // Registrujeme shortcode s ochranou
            if ( method_exists($map, 'render_shortcode') ) {
                add_shortcode('db_map', function($atts) use ($map) {
                    if ( ! db_user_can_see_map() ) {
                        if ( ! is_user_logged_in() ) {
                            $login_url = wp_login_url( get_permalink() );
                            return '<p>Pro zobrazení mapy se prosím <a href="'. esc_url($login_url) .'">přihlas</a>.</p>';
                        }
                        return '<p>Tento obsah je dostupný jen pro oprávněné uživatele.</p>';
                    }
                    return call_user_func([$map, 'render_shortcode'], $atts);
                });
            }
        }
    }
}, 30);

add_action( 'init', function() {
    db_register_map_route();
    
    // Pokud je to první načtení po aktualizaci pluginu, nastavit flag pro flush rewrite rules
    if (get_option('db_map_route_registered', false) === false) {
        update_option('db_rewrite_flush_needed', true);
        update_option('db_map_route_registered', true);
    }
}, 5 );

add_filter( 'query_vars', function( array $vars ): array {
    if ( ! in_array( DB_MAP_ROUTE_QUERY_VAR, $vars, true ) ) {
        $vars[] = DB_MAP_ROUTE_QUERY_VAR;
    }
    return $vars;
} );

add_action( 'template_redirect', function() {
    if ( intval( get_query_var( DB_MAP_ROUTE_QUERY_VAR ) ) !== 1 ) {
        return;
    }

    if ( ! db_user_can_see_map() ) {
        if ( ! is_user_logged_in() ) {
            $redirect = wp_login_url( home_url( '/' . db_map_route_slug() . '/' ) );
            wp_safe_redirect( $redirect );
            exit;
        }

        wp_die(
            esc_html__( 'K zobrazení mapy nemáte oprávnění.', 'dobity-baterky' ),
            esc_html__( 'Přístup zamítnut', 'dobity-baterky' ),
            array( 'response' => 403 )
        );
    }

    status_header( 200 );
    nocache_headers();

    $template = DB_PLUGIN_DIR . 'templates/map-app.php';
    if ( file_exists( $template ) ) {
        include $template;
    } else {
        wp_die( esc_html__( 'Šablona mapy nebyla nalezena.', 'dobity-baterky' ), '', array( 'response' => 500 ) );
    }
    exit;
} );

// Načítání assetů s ochranou - spouští se až po init
add_action('wp_enqueue_scripts', function() {

    // Enqueue map assets pouze pro uživatele s přístupem k mapě
    if ( ! db_is_map_frontend_context() ) {
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
    wp_enqueue_script( 'db-map-loader', plugins_url( 'assets/map/loader.js', DB_PLUGIN_FILE ), array('leaflet','leaflet-markercluster'), DB_PLUGIN_VERSION, true );
    
    // On-Demand Processor
    wp_enqueue_script( 'db-ondemand', plugins_url( 'assets/ondemand-processor.js', DB_PLUGIN_FILE ), array('jquery','db-map-loader'), DB_PLUGIN_VERSION, true );
    
    // Data pro JS
    // Příprava favorites payload
    $favorites_payload = array(
        'enabled' => false,
    );
    
    // Načíst favorites data pro přihlášené uživatele
    if ( class_exists( '\\DB\\Favorites_Manager' ) && is_user_logged_in() ) {
        try {
            $favorites_manager = \DB\Favorites_Manager::get_instance();
            $favorites_payload = $favorites_manager->get_localized_payload( get_current_user_id() );
        } catch ( \Throwable $e ) {
            $favorites_payload = array( 'enabled' => false );
        }
    }
    
    // Načíst překlady
    $translations = array();
    if ( class_exists( '\\DB\\Translation_Manager' ) ) {
        $translation_manager = \DB\Translation_Manager::get_instance();
        $translations = $translation_manager->get_frontend_translations();
    }
    
    wp_localize_script( 'db-map-loader', 'dbMapData', array(
        'restUrl'   => rest_url( 'db/v1/map' ),
        'restNonce' => wp_create_nonce( 'wp_rest' ),
        'iconsBase' => plugins_url( 'assets/icons/', DB_PLUGIN_FILE ),
        'pluginUrl' => plugins_url( '/', DB_PLUGIN_FILE ),
        'assetsBase' => plugins_url( 'assets/map/', DB_PLUGIN_FILE ),
        'version' => DB_PLUGIN_VERSION,
        'isMapPage' => function_exists('db_is_map_app_page') ? db_is_map_app_page() : false,
        'pwaEnabled' => true, // Vlastní PWA implementace - vždy povoleno
        'isAdmin' => current_user_can('administrator') || current_user_can('editor'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'adminNonce' => wp_create_nonce('db_admin_actions'),
        'adminUrl' => admin_url(),
        // Google Places Photos API vyžaduje klíč i na frontendu (URL redirect). Klíč by měl být omezen refererem.
        'googleApiKey' => get_option('db_google_api_key') ?: '',
        'chargerColors' => array(
            'ac' => get_option('db_charger_ac_color', '#049FE8'),
            'dc' => get_option('db_charger_dc_color', '#FFACC4'),
            'blendStart' => (int) get_option('db_charger_blend_start', 30),
            'blendEnd' => (int) get_option('db_charger_blend_end', 70),
        ),
        'poiColor' => get_option('db_poi_color', '#FCE67D'),
        'rvColor' => get_option('db_rv_color', '#FCE67D'),
        // Barva ikony nabíječky uvnitř pinu (bez vnitřního fill ve SVG)
        'chargerIconColor' => get_option('db_charger_icon_color', '#ffffff'),
        // Account / user info for frontend menu
        'isLoggedIn' => is_user_logged_in(),
        'currentUser' => is_user_logged_in() ? wp_get_current_user()->display_name : '',
        'accountUrl' => is_user_logged_in() ? admin_url('profile.php') : wp_login_url(),
        'logoutUrl' => is_user_logged_in() ? wp_logout_url( home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ) ) ) : '',
        'loginUrl' => is_user_logged_in() ? '' : wp_login_url( home_url( add_query_arg( array(), $_SERVER['REQUEST_URI'] ) ) ),
        'favorites' => $favorites_payload,
        'translations' => $translations,
    ) );
    
    // On-Demand Processor data
    wp_localize_script( 'db-ondemand', 'wpApiSettings', array(
        'root' => esc_url_raw( rest_url() ),
        'nonce' => wp_create_nonce( 'wp_rest' )
    ));
}, 20);

/**
 * Odstraní těžké/nechtěné skripty a styly na mapové stránce.
 */
add_action( 'wp_enqueue_scripts', function() {
    if ( ! db_is_map_frontend_context() ) {
        return;
    }

    $script_substrings = apply_filters(
        'db_map_unwanted_script_sources',
        array(
            '/wp-content/plugins/woocommerce/',
            '/wp-content/plugins/woocommerce-payments/',
            '/wp-content/plugins/woocommerce-gateway-',
            '/wp-content/plugins/woo-stripe',
            '/wp-content/plugins/woocom',
            '/wp-content/plugins/gutenberg/',
            '/wp-content/plugins/google-site-kit/',
            '/wp-content/plugins/jetpack/',
            'js.stripe.com/v3',
            'prebid-load.js',
            'stats.wp.com/w.js',
        )
    );

    $style_substrings = apply_filters(
        'db_map_unwanted_style_sources',
        array(
            '/wp-content/plugins/woocommerce/',
            '/wp-content/plugins/woocom',
            '/wp-content/plugins/gutenberg/',
            '/wp-content/plugins/google-site-kit/',
            '/wp-content/plugins/jetpack/',
        )
    );

    $scripts = wp_scripts();
    if ( $scripts instanceof \WP_Scripts ) {
        foreach ( (array) $scripts->queue as $handle ) {
            if ( ! isset( $scripts->registered[ $handle ] ) ) {
                continue;
            }
            $src = $scripts->registered[ $handle ]->src ?? '';
            if ( ! $src ) {
                continue;
            }

            $full_src = $src;
            if ( 0 === strpos( $src, '//' ) ) {
                $full_src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
            } elseif ( '/' === substr( $src, 0, 1 ) ) {
                $full_src = home_url( $src );
            }

            foreach ( $script_substrings as $needle ) {
                if ( $needle && false !== strpos( $full_src, $needle ) ) {
                    wp_dequeue_script( $handle );
                    wp_deregister_script( $handle );
                    break;
                }
            }
        }
    }

    $styles = wp_styles();
    if ( $styles instanceof \WP_Styles ) {
        foreach ( (array) $styles->queue as $handle ) {
            if ( ! isset( $styles->registered[ $handle ] ) ) {
                continue;
            }
            $src = $styles->registered[ $handle ]->src ?? '';
            if ( ! $src ) {
                continue;
            }

            $full_src = $src;
            if ( 0 === strpos( $src, '//' ) ) {
                $full_src = ( is_ssl() ? 'https:' : 'http:' ) . $src;
            } elseif ( '/' === substr( $src, 0, 1 ) ) {
                $full_src = home_url( $src );
            }

            foreach ( $style_substrings as $needle ) {
                if ( $needle && false !== strpos( $full_src, $needle ) ) {
                    wp_dequeue_style( $handle );
                    wp_deregister_style( $handle );
                    break;
                }
            }
        }
    }
}, 999 );

/**
 * Ořezání DNS-prefetch / prefetch hintů na mapové stránce.
 */
add_filter( 'wp_resource_hints', function( array $urls, string $relation_type ): array {
    if ( ! db_is_map_frontend_context() ) {
        return $urls;
    }

    if ( ! in_array( $relation_type, array( 'dns-prefetch', 'prefetch', 'preconnect', 'prerender', 'preload' ), true ) ) {
        return $urls;
    }

    $blocked = apply_filters(
        'db_map_blocked_resource_hints',
        array(
            'googleads',
            'doubleclick',
            'googletagmanager',
            'googletagservices',
            'googlesyndication',
            'ads.pubmatic',
            'switchadhub',
            'amazon-adsystem',
            'criteo',
            'jetpack',
            'woocommerce',
            'gutenberg',
            'google-site-kit',
            'stripe.com',
            'wp.com',
        )
    );

    $filtered = array();

    foreach ( $urls as $entry ) {
        $href = is_array( $entry ) ? ( $entry['href'] ?? '' ) : $entry;

        if ( ! $href ) {
            continue;
        }

        $should_block = false;
        foreach ( $blocked as $needle ) {
            if ( $needle && false !== strpos( $href, $needle ) ) {
                $should_block = true;
                break;
            }
        }

        if ( $should_block ) {
            continue;
        }

        $filtered[] = $entry;
    }

    return $filtered;
}, 10, 2 );

// Registrace capability v Members pluginu - spustí se až když je Members dostupný
add_action('init', function() {
    if ( function_exists('members_register_cap') ) {
        db_ensure_capability_exists();
    }
}, 20);

// ⛔️ U nepovolaných uživatelů odstraň [db_map] ze stránkového obsahu (když do obsahu zasahuje jiný plugin)
add_filter('the_content', function($content){
    if ( function_exists('db_user_can_see_map') && ! db_user_can_see_map() ) {
        // Odstraní i varianty s atributy
        $content = preg_replace('/\[db_map[^\]]*\]/i', '', (string) $content);
    }
    return $content;
}, 9);

// Šablony pro single zobrazení CPT (charging_location, rv_spot, poi)
add_filter('single_template', function($single) {
    global $post;
    if (!isset($post) || !is_object($post)) return $single;
    $type = $post->post_type;
    $template_map = array(
        'charging_location' => 'templates/single-charging_location.php',
        'rv_spot' => 'templates/single-rv_spot.php',
        'poi' => 'templates/single-poi.php',
    );
    if (isset($template_map[$type])) {
        $candidate = DB_PLUGIN_DIR . $template_map[$type];
        if (file_exists($candidate)) return $candidate;
    }
    return $single;
});

// Oprava rewrite pravidel pro CPT - spustí se po registraci CPT
add_action('init', function() {
    // Flush rewrite rules pouze pokud je potřeba
    if (get_option('db_rewrite_flush_needed', false)) {
        flush_rewrite_rules();
        delete_option('db_rewrite_flush_needed');
    }
    
    // Flush rewrite rules pokud chybí ServiceWorker endpoint
    if (get_option('db_sw_endpoint_added', false) !== '1') {
        flush_rewrite_rules(false);
        update_option('db_sw_endpoint_added', '1');
    }
}, 999);

// Přidání custom query vars pro lepší URL strukturu
add_filter('query_vars', function($vars) {
    $vars[] = 'db_location_id';
    $vars[] = 'db_location_type';
    $vars[] = 'db_sw'; // ServiceWorker endpoint
    return $vars;
});

// Custom endpoint pro zobrazení míst
add_action('init', function() {
    add_rewrite_rule(
        '^lokality/([^/]+)/?$',
        'index.php?post_type=charging_location&name=$matches[1]',
        'top'
    );
    
    add_rewrite_rule(
        '^rv-mista/([^/]+)/?$',
        'index.php?post_type=rv_spot&name=$matches[1]',
        'top'
    );
    
    add_rewrite_rule(
        '^tipy/([^/]+)/?$',
        'index.php?post_type=poi&name=$matches[1]',
        'top'
    );
    
    // ServiceWorker endpoint pro PWA - servuje SW z root, aby mohl mít scope /
    add_rewrite_rule(
        '^db-sw\.js$',
        'index.php?db_sw=1',
        'top'
    );
}, 10);


// Servovat ServiceWorker z root endpointu
// Použít jak template_redirect (pro rewrite rules), tak parse_request (pro přímý přístup)
add_action('parse_request', function($wp) {
    $request_uri = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $is_sw_request = ($request_uri === '/db-sw.js' || $request_uri === '/db-sw.js/');
    
    if ($is_sw_request) {
        $sw_file = DB_PLUGIN_DIR . 'assets/sw.js';
        if (file_exists($sw_file)) {
            // Získat WordPress site path pro Service-Worker-Allowed hlavičku
            $site_path = parse_url(home_url('/'), PHP_URL_PATH);
            if (!$site_path || $site_path === '/') {
                $site_path = '/';
            }
            
            // Nastavit správné hlavičky pro ServiceWorker
            status_header(200);
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: ' . $site_path); // Povolit scope pro WordPress site path
            header('Cache-Control: public, max-age=3600'); // Cache na 1 hodinu
            
            // Vypnout WordPress output
            remove_all_actions('wp_head');
            remove_all_actions('wp_footer');
            
            // Servovat ServiceWorker soubor
            readfile($sw_file);
            exit;
        } else {
            // Pokud soubor neexistuje, vrátit 404
            status_header(404);
            exit;
        }
    }
}, 1); // Vysoká priorita

add_action('template_redirect', function() {
    // Kontrola přes query var i přes REQUEST_URI (pro případ, že rewrite rules ještě nejsou flushnuté)
    $request_uri = isset($_SERVER['REQUEST_URI']) ? parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) : '';
    $is_sw_request = (get_query_var('db_sw') == 1) || 
                     ($request_uri === '/db-sw.js' || $request_uri === '/db-sw.js/');
    
    if ($is_sw_request) {
        $sw_file = DB_PLUGIN_DIR . 'assets/sw.js';
        if (file_exists($sw_file)) {
            // Získat WordPress site path pro Service-Worker-Allowed hlavičku
            $site_path = parse_url(home_url('/'), PHP_URL_PATH);
            if (!$site_path || $site_path === '/') {
                $site_path = '/';
            }
            
            // Nastavit správné hlavičky pro ServiceWorker
            status_header(200);
            header('Content-Type: application/javascript; charset=utf-8');
            header('Service-Worker-Allowed: ' . $site_path); // Povolit scope pro WordPress site path
            header('Cache-Control: public, max-age=3600'); // Cache na 1 hodinu
            
            // Vypnout WordPress output
            remove_all_actions('wp_head');
            remove_all_actions('wp_footer');
            
            // Servovat ServiceWorker soubor
            readfile($sw_file);
            exit;
        } else {
            // Pokud soubor neexistuje, vrátit 404
            status_header(404);
            exit;
        }
    }
}, 1); // Vysoká priorita, aby se spustilo dříve než ostatní template_redirect handlery

// Manager pro nabíjecí stanice s TomTom API (pouze pro AJAX handlery, ne pro meta boxy)
if ( file_exists( __DIR__ . '/includes/Charging_Manager.php' ) ) {
    require_once __DIR__ . '/includes/Charging_Manager.php';
    if ( class_exists( 'DB\Charging_Manager' ) ) {
        $charging_manager = DB\Charging_Manager::get_instance();
    }
}

// Admin panel handlery pro mapu
if ( file_exists( __DIR__ . '/includes/Admin_Panel_Handlers.php' ) ) {
    require_once __DIR__ . '/includes/Admin_Panel_Handlers.php';
    if ( class_exists( 'DB\Admin_Panel_Handlers' ) ) {
        new DB\Admin_Panel_Handlers();
    }
}

// Odstranit všechny staré meta boxy pro charging_location
add_action('add_meta_boxes', function() {
    // Odstranit staré meta boxy
    remove_meta_box('db_charging_details', 'charging_location', 'normal');
    remove_meta_box('db_charging_search', 'charging_location', 'normal');
    remove_meta_box('db_charging_tomtom', 'charging_location', 'normal');
}, 999);

// DATEX II byl vypnut – žádné načítání admin/manager tříd

// Admin: MPO JSON import (bundlovaná data → CPT charging_location)
if ( file_exists( __DIR__ . '/includes/MPO_JSON_Admin.php' ) ) {
    require_once __DIR__ . '/includes/MPO_JSON_Admin.php';
    if ( class_exists( 'DB\MPO_JSON_Admin' ) ) {
        DB\MPO_JSON_Admin::get_instance()->register();
    }
}

// Admin: Provider management (export/import) - NEW safe version
if (is_admin()) {
    try {
        if ( file_exists( __DIR__ . '/includes/Admin/Provider_Manager.php' ) ) {
            require_once __DIR__ . '/includes/Admin/Provider_Manager.php';
            if ( class_exists( 'DB\Provider_Manager' ) ) {
                new DB\Provider_Manager();
            }
        }
        // POI Admin - pokročilé rozhraní v administraci (včetně CSV importu/exportu)
        if ( file_exists( __DIR__ . '/includes/POI_Admin.php' ) ) {
            require_once __DIR__ . '/includes/POI_Admin.php';
            if ( class_exists( 'DB\POI_Admin' ) ) {
                DB\POI_Admin::get_instance();
            }
        }
        
        // Nearby Settings - routing konfigurace
        if ( file_exists( __DIR__ . '/includes/Admin/Nearby_Settings.php' ) ) {
            require_once __DIR__ . '/includes/Admin/Nearby_Settings.php';
            if ( class_exists( 'DB\Admin\Nearby_Settings' ) ) {
                new DB\Admin\Nearby_Settings();
            }
        }
        
        if ( file_exists( __DIR__ . '/includes/Admin/Nearby_Queue_Admin.php' ) ) {
            require_once __DIR__ . '/includes/Admin/Nearby_Queue_Admin.php';
            if ( class_exists( 'DB\Admin\Nearby_Queue_Admin' ) ) {
                new DB\Admin\Nearby_Queue_Admin();
            }
        }

        // POI Discovery Admin (submenu pod POI)
        if ( file_exists( __DIR__ . '/includes/Admin/POI_Discovery_Admin.php' ) ) {
            require_once __DIR__ . '/includes/Admin/POI_Discovery_Admin.php';
            if ( class_exists( 'DB\Admin\POI_Discovery_Admin' ) ) {
                new DB\Admin\POI_Discovery_Admin();
            }
        }

        if ( file_exists( __DIR__ . '/includes/Admin/Charging_Discovery_Admin.php' ) ) {
            require_once __DIR__ . '/includes/Admin/Charging_Discovery_Admin.php';
            if ( class_exists( 'DB\Admin\Charging_Discovery_Admin' ) ) {
                new DB\Admin\Charging_Discovery_Admin();
            }
        }
        
        // Inicializovat automatické zpracování
        if ( file_exists( __DIR__ . '/includes/Jobs/Nearby_Auto_Processor.php' ) ) {
            require_once __DIR__ . '/includes/Jobs/Nearby_Auto_Processor.php';
            if ( class_exists( 'DB\Jobs\Nearby_Auto_Processor' ) ) {
                new DB\Jobs\Nearby_Auto_Processor();
            }
        }
    } catch (\Exception $e) {
        error_log('[PROVIDER MANAGER] Failed to initialize: ' . $e->getMessage());
        // Don't break admin - just log the error
    }
}

// REST API pro Nearby Places s walking distance
if ( file_exists( __DIR__ . '/includes/REST_Nearby.php' ) ) {
    require_once __DIR__ . '/includes/REST_Nearby.php';
    if ( class_exists( 'DB\REST_Nearby' ) ) {
        $rest_nearby = DB\REST_Nearby::get_instance();
        $rest_nearby->register();
    }
}

// REST API: POI Discovery (admin only)
if ( file_exists( __DIR__ . '/includes/REST_POI_Discovery.php' ) ) {
    require_once __DIR__ . '/includes/REST_POI_Discovery.php';
    if ( class_exists( 'DB\REST_POI_Discovery' ) ) {
        DB\REST_POI_Discovery::get_instance()->register();
    }
}

if ( file_exists( __DIR__ . '/includes/REST_Charging_Discovery.php' ) ) {
    require_once __DIR__ . '/includes/REST_Charging_Discovery.php';
    if ( class_exists( 'DB\REST_Charging_Discovery' ) ) {
        DB\REST_Charging_Discovery::get_instance()->register();
    }
}

// POI Microservice Client - WordPress volá POI microservice API a vytváří posty sám
if ( file_exists( __DIR__ . '/includes/Services/POI_Microservice_Client.php' ) ) {
    require_once __DIR__ . '/includes/Services/POI_Microservice_Client.php';
}

// POI Microservice Admin rozhraní
if ( file_exists( __DIR__ . '/includes/Admin/POI_Service_Admin.php' ) ) {
    require_once __DIR__ . '/includes/Admin/POI_Service_Admin.php';
    if ( class_exists( 'DB\Admin\POI_Service_Admin' ) ) {
        DB\Admin\POI_Service_Admin::get_instance();
    }
}

// WP-CLI příkazy
if ( defined( 'WP_CLI' ) && WP_CLI ) {
    if ( file_exists( __DIR__ . '/includes/CLI/Test_Wikidata_Command.php' ) ) {
        require_once __DIR__ . '/includes/CLI/Test_Wikidata_Command.php';
    }
}

// REST API pro synchronizaci POIs z POI microservice (volitelné - pro externí integrace)
if ( file_exists( __DIR__ . '/includes/REST_POI_Sync.php' ) ) {
    require_once __DIR__ . '/includes/REST_POI_Sync.php';
    if ( class_exists( 'DB\REST_POI_Sync' ) ) {
        DB\REST_POI_Sync::get_instance()->register();
    }
}

// POI Discovery Worker helpers
if ( file_exists( __DIR__ . '/includes/Jobs/POI_Discovery_Worker.php' ) ) {
    require_once __DIR__ . '/includes/Jobs/POI_Discovery_Worker.php';
}

if ( file_exists( __DIR__ . '/includes/Jobs/Charging_Discovery_Worker.php' ) ) {
    require_once __DIR__ . '/includes/Jobs/Charging_Discovery_Worker.php';
}

// Background Jobs pro recompute
if ( file_exists( __DIR__ . '/includes/Jobs/Nearby_Recompute_Job.php' ) ) {
    require_once __DIR__ . '/includes/Jobs/Nearby_Recompute_Job.php';
    if ( class_exists( 'DB\Jobs\Nearby_Recompute_Job' ) ) {
        new DB\Jobs\Nearby_Recompute_Job();
    }
}

// Dočasná oprava termu "kavárna" - ZAKÁZÁNO kvůli "headers already sent" erroru
// if ( file_exists( __DIR__ . '/fix_kavarna.php' ) ) {
//     require_once __DIR__ . '/fix_kavarna.php';
// }

// Import CSV rozhraní
// require_once __DIR__ . '/includes/class-csv-importer.php';
// DB\CSV_Importer::get_instance()->register();

// AJAX handler pro přidání poskytovatele je nyní implementován v Charging_Location_Form.php

// POI Importer – admin submenu pod POI (součást hlavního pluginu) - ZAKÁZÁNO kvůli "headers already sent" erroru
// if ( file_exists( __DIR__ . '/poi-importer.php' ) ) {
//     require_once __DIR__ . '/poi-importer.php';
//     if ( class_exists( 'POI_Importer' ) && !isset($GLOBALS['poi_importer_instance'])) {
//         $GLOBALS['poi_importer_instance'] = new POI_Importer();
//     }
// }

// WP-CLI: provider enrichment test command
if (defined('WP_CLI') && WP_CLI) {
    if ( file_exists( __DIR__ . '/includes/CLI/Provider_Enrichment_Command.php' ) ) {
        require_once __DIR__ . '/includes/CLI/Provider_Enrichment_Command.php';
        if ( class_exists( 'DB\\CLI\\Provider_Enrichment_Command' ) ) {
            \WP_CLI::add_command('db-providers', 'DB\\CLI\\Provider_Enrichment_Command');
        }
    }
    if ( file_exists( __DIR__ . '/includes/CLI/POI_Discovery_Command.php' ) ) {
        require_once __DIR__ . '/includes/CLI/POI_Discovery_Command.php';
        if ( class_exists( 'DB\\CLI\\POI_Discovery_Command' ) ) {
            \WP_CLI::add_command('db-poi', 'DB\\CLI\\POI_Discovery_Command');
        }
    }
    if ( file_exists( __DIR__ . '/includes/CLI/Charging_Discovery_Command.php' ) ) {
        require_once __DIR__ . '/includes/CLI/Charging_Discovery_Command.php';
        if ( class_exists( 'DB\\CLI\\Charging_Discovery_Command' ) ) {
            \WP_CLI::add_command('db-charging', 'DB\\CLI\\Charging_Discovery_Command');
        }
    }
}

// ===== VLASTNÍ PWA IMPLEMENTACE =====
// Vlastní PWA řešení, které funguje na staging i produkci
// NENÍ závislé na PWA for WP pluginu (ten můžete vypnout/deaktivovat)

/**
 * Přidá PWA manifest link do head
 */
add_action('wp_head', function() {
    // Odstranit manifest linky z PWA for WP pluginu (pokud existuje)
    // a přidat náš vlastní
    $manifest_url = plugins_url('assets/manifest.json', DB_PLUGIN_FILE);
    ?>
    <link rel="manifest" href="<?php echo esc_url($manifest_url); ?>">
    <meta name="theme-color" content="#049FE8">
    <meta name="apple-mobile-web-app-capable" content="yes">
           <meta name="mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="default">
    <meta name="apple-mobile-web-app-title" content="Dobitý Baterky">
           <!-- PWA icons served from plugin (ensure PNGs exist at these paths) -->
           <link rel="apple-touch-icon" sizes="180x180" href="<?php echo esc_url( plugins_url('assets/pwa/db-icon-180.png', DB_PLUGIN_FILE) ); ?>">
           <link rel="icon" type="image/png" sizes="192x192" href="<?php echo esc_url( plugins_url('assets/pwa/db-icon-192.png', DB_PLUGIN_FILE) ); ?>">
           <link rel="icon" type="image/png" sizes="512x512" href="<?php echo esc_url( plugins_url('assets/pwa/db-icon-512.png', DB_PLUGIN_FILE) ); ?>">
    <?php
}, 1);

/**
 * Načte PWA helper script (musí být enqueued dříve než wp_print_footer_scripts)
 */
add_action('wp_enqueue_scripts', function() {
    // PWA styles (pro prompt a tlačítka)
    wp_enqueue_style(
        'db-pwa-styles',
        plugins_url('assets/pwa-styles.css', DB_PLUGIN_FILE),
        array(),
        DB_PLUGIN_VERSION
    );
    
    wp_enqueue_script(
        'db-pwa-helper',
        plugins_url('assets/pwa-helper.js', DB_PLUGIN_FILE),
        array(),
        DB_PLUGIN_VERSION,
        true
    );
}, 20);

/**
 * Zaregistruje ServiceWorker a blokuje nesprávné registrace
 */
add_action('wp_footer', function() {
    // Kontrola, zda už není script přidaný (ochrana proti duplicitnímu spuštění)
    static $script_added = false;
    if ($script_added) {
        return;
    }
    $script_added = true;
    
    // Použít vlastní endpoint pro ServiceWorker (z root, aby mohl mít scope /)
    $sw_url = add_query_arg('db_sw', '1', home_url('/'));
    ?>
    <script>
    (function() {
        // Ochrana proti duplicitnímu spuštění
        if (window.__DB_PWA_SW_INIT__) {
            return;
        }
        window.__DB_PWA_SW_INIT__ = true;
        
        // Zablokovat ServiceWorker registrace z PWA for WP pluginu (pokud existuje)
        // s nesprávným originem
        if ('serviceWorker' in navigator) {
            const originalRegister = navigator.serviceWorker.register.bind(navigator.serviceWorker);
            navigator.serviceWorker.register = function(scriptURL, options) {
                try {
                    const url = new URL(scriptURL, window.location.origin);
                    const currentOrigin = window.location.origin;
                    
                    // Pokud ServiceWorker URL má jiný origin než aktuální, zablokovat
                    if (url.origin !== currentOrigin) {
                        console.warn('[DB PWA] ServiceWorker registrace zablokována - origin mismatch:', {
                            scriptURL: scriptURL,
                            scriptOrigin: url.origin,
                            currentOrigin: currentOrigin
                        });
                        return Promise.reject(new Error('ServiceWorker origin mismatch - blocked'));
                    }
                } catch (e) {
                    console.warn('[DB PWA] ServiceWorker registrace zablokována - invalid URL:', scriptURL);
                    return Promise.reject(new Error('Invalid ServiceWorker URL'));
                }
                
                return originalRegister(scriptURL, options);
            };
            
            // Čištění registrací: neodstraňuj jiné aplikace na stejném originu.
            // 1) Cizí origin vždy odstranit (bezpečnost).
            // 2) Ze stejného originu odstranit POUZE naše registrace (db-sw.js) s nesprávným scope.
            (function cleanupRegistrations() {
                try {
                    const sitePath = '<?php echo esc_js(parse_url(home_url('/'), PHP_URL_PATH)); ?>';
                    const siteScope = window.location.origin + sitePath;
                    const ourSwSuffix = '/db-sw.js';
                    const ourSwQueryParam = 'db_sw';
                    function isOurReg(reg) {
                        try {
                            const urlString = (reg && reg.active && reg.active.scriptURL)
                                      || (reg && reg.waiting && reg.waiting.scriptURL)
                                      || (reg && reg.installing && reg.installing.scriptURL)
                                      || null;
                            if (!urlString) return false;
                            try {
                                const parsed = new URL(urlString, window.location.origin);
                                if (parsed.pathname && parsed.pathname.indexOf(ourSwSuffix) !== -1) {
                                    return true;
                                }
                                if (parsed.searchParams && parsed.searchParams.has(ourSwQueryParam)) {
                                    return true;
                                }
                            } catch(_) {
                                return urlString.indexOf(ourSwSuffix) !== -1 || urlString.indexOf(ourSwQueryParam + '=') !== -1;
                            }
                            return false;
                        } catch(_) { return false; }
                    }
                    if (typeof navigator.serviceWorker.getRegistrations === 'function') {
                        navigator.serviceWorker.getRegistrations().then(function(registrations) {
                            registrations.forEach(function(reg) {
                                if (!reg || !reg.scope) return;
                                const sameOrigin = reg.scope.indexOf(window.location.origin) === 0;
                                const ourScope = reg.scope === siteScope || reg.scope.indexOf(sitePath) === 0;
                                const ours = isOurReg(reg);
                                if (!sameOrigin) {
                                    console.warn('[DB PWA] Unregister foreign-origin SW:', reg.scope);
                                    reg.unregister();
                                } else if (ours && !ourScope) {
                                    console.warn('[DB PWA] Unregister our SW with mismatched scope:', reg.scope);
                                    reg.unregister();
                                }
                            });
                        }).catch(function(){});
                    } else if (navigator.serviceWorker.getRegistration) {
                        // Safari fallback: zkusit jen potenciální naše scope a sahat pouze na naše SW
                        navigator.serviceWorker.getRegistration('/').then(function(reg){
                            try {
                                if (reg && reg.scope) {
                                    const sameOrigin = reg.scope.indexOf(window.location.origin) === 0;
                                    const ourScope = reg.scope === siteScope || reg.scope.indexOf(sitePath) === 0;
                                    if (isOurReg(reg) && (!sameOrigin || !ourScope)) {
                                        console.warn('[DB PWA] Unregister our SW (fallback /):', reg.scope);
                                        reg.unregister();
                                    }
                                }
                            } catch(_) {}
                        }).catch(function(){});
                        if (sitePath && sitePath !== '/') {
                            navigator.serviceWorker.getRegistration(sitePath).then(function(reg){
                                try {
                                    if (reg && reg.scope) {
                                        const sameOrigin = reg.scope.indexOf(window.location.origin) === 0;
                                        const ourScope = reg.scope === siteScope || reg.scope.indexOf(sitePath) === 0;
                                        if (isOurReg(reg) && (!sameOrigin || !ourScope)) {
                                            console.warn('[DB PWA] Unregister our SW (fallback sitePath):', reg.scope);
                                            reg.unregister();
                                        }
                                    }
                                } catch(_) {}
                            }).catch(function(){});
                        }
                    }
                } catch(_) {}
            })();
            
            // Zaregistrovat náš vlastní ServiceWorker
            // Scope je omezen na WordPress site path (ne celý origin) pro bezpečnost
            function registerServiceWorker() {
                // Získat WordPress site path (např. '/' nebo '/blog' pro subdirectory instalace)
                const sitePath = '<?php echo esc_js(parse_url(home_url('/'), PHP_URL_PATH)); ?>';
                const siteScope = window.location.origin + sitePath;
                const swUrl = '<?php echo esc_js($sw_url); ?>';
                
                // Zkontrolovat, zda už není zaregistrován
                // Kontrola dostupnosti getRegistrations() pro Safari kompatibilitu
                if (typeof navigator.serviceWorker.getRegistrations === 'function') {
                    navigator.serviceWorker.getRegistrations().then(function(registrations) {
                        const ourSW = registrations.find(function(reg) {
                            return reg.scope === siteScope || reg.scope.startsWith(siteScope);
                        });
                        
                        if (ourSW) {
                            console.log('[DB PWA] ServiceWorker už je zaregistrován:', ourSW.scope);
                            return;
                        }
                        
                        // Nejprve ověřit dostupnost souboru, aby se předešlo 404 na stagingu
                        fetch(swUrl, { method: 'GET', cache: 'no-store' })
                            .then(function(resp) {
                                if (!resp.ok) {
                                    console.warn('[DB PWA] ServiceWorker endpoint nedostupný (' + resp.status + '), registraci přeskočím.');
                                    return;
                                }
                                // Registrovat s scope omezeným na WordPress site path
                                navigator.serviceWorker.register(swUrl, { scope: sitePath })
                                    .then(function(registration) {
                                        console.log('[DB PWA] ServiceWorker zaregistrován:', registration.scope);
                                    })
                                    .catch(function(error) {
                                        console.warn('[DB PWA] ServiceWorker registrace selhala:', error);
                                    });
                            })
                            .catch(function(err){
                                console.warn('[DB PWA] Kontrola ServiceWorker endpointu selhala:', err);
                            });
                    }).catch(function(error) {
                        console.warn('[DB PWA] Chyba při kontrole ServiceWorker registrací:', error);
                        // Pokusit se zaregistrovat i při chybě
                        fetch(swUrl, { method: 'GET', cache: 'no-store' })
                            .then(function(resp) {
                                if (!resp.ok) {
                                    console.warn('[DB PWA] ServiceWorker endpoint nedostupný (' + resp.status + '), registraci přeskočím.');
                                    return;
                                }
                                return navigator.serviceWorker.register(swUrl, { scope: sitePath })
                                    .then(function(registration) {
                                        console.log('[DB PWA] ServiceWorker zaregistrován:', registration.scope);
                                    })
                                    .catch(function(error) {
                                        console.warn('[DB PWA] ServiceWorker registrace selhala:', error);
                                    });
                            })
                            .catch(function(err){
                                console.warn('[DB PWA] Kontrola ServiceWorker endpointu selhala:', err);
                            });
                    });
                } else {
                    // Fallback pro Safari - zkusit získat registraci pro site scope
                    navigator.serviceWorker.getRegistration(sitePath).then(function(registration) {
                        if (registration && (registration.scope === siteScope || registration.scope.startsWith(siteScope))) {
                            console.log('[DB PWA] ServiceWorker už je zaregistrován:', registration.scope);
                            return;
                        }
                        
                        // Ověřit dostupnost a poté registrovat s omezeným scope
                        fetch(swUrl, { method: 'GET', cache: 'no-store' })
                            .then(function(resp) {
                                if (!resp.ok) {
                                    console.warn('[DB PWA] ServiceWorker endpoint nedostupný (' + resp.status + '), registraci přeskočím.');
                                    return;
                                }
                                return navigator.serviceWorker.register(swUrl, { scope: sitePath })
                                    .then(function(registration) {
                                        console.log('[DB PWA] ServiceWorker zaregistrován:', registration.scope);
                                    })
                                    .catch(function(error) {
                                        console.warn('[DB PWA] ServiceWorker registrace selhala:', error);
                                    });
                            })
                            .catch(function(err){
                                console.warn('[DB PWA] Kontrola ServiceWorker endpointu selhala:', err);
                            });
                    }).catch(function(error) {
                        // Pokud getRegistration selže, zkusit registrovat přímo
                        fetch(swUrl, { method: 'GET', cache: 'no-store' })
                            .then(function(resp) {
                                if (!resp.ok) {
                                    console.warn('[DB PWA] ServiceWorker endpoint nedostupný (' + resp.status + '), registraci přeskočím.');
                                    return;
                                }
                                return navigator.serviceWorker.register(swUrl, { scope: sitePath })
                                    .then(function(registration) {
                                        console.log('[DB PWA] ServiceWorker zaregistrován:', registration.scope);
                                    })
                                    .catch(function(error) {
                                        console.warn('[DB PWA] ServiceWorker registrace selhala:', error);
                                    });
                            })
                            .catch(function(err){
                                console.warn('[DB PWA] Kontrola ServiceWorker endpointu selhala:', err);
                            });
                    });
                }
            }
            
            // Zaregistrovat po načtení stránky
            if (document.readyState === 'complete') {
                registerServiceWorker();
            } else {
                window.addEventListener('load', registerServiceWorker);
            }
        }
    })();
    </script>
    <?php
}, 999);

/**
 * Rozpoznání mapové stránky pro PWA optimalizace
 */
function db_is_map_app_page(): bool {
    // a) vlastní endpoint přes rewrite (např. ?dobity_map=1)
    if (get_query_var('dobity_map') == 1) return true;
    
    // b) NEBO konkrétní WP stránka podle slugu/ID:
    if (is_page('mapa')) return true;
    
    // c) NEBO stránka obsahující [db_map] shortcode
    global $post;
    if ($post && has_shortcode($post->post_content, 'db_map')) return true;
    
    return false;
}

/**
 * PWA optimalizace pro mapové stránky
 * Integrace s PWA for WP pluginem
 * 
 * POZNÁMKA: PWA funkce jsou dočasně deaktivovány pro testování
 * Pokud chcete PWA aktivovat, odkomentujte níže uvedené kódy
 */

/*
add_action('wp_head', function () {
    if (!db_is_map_app_page()) return;
    
    // PWA for WP plugin už poskytuje manifest a základní meta tagy
    // Přidáme pouze specifické optimalizace pro mapu
    
    echo '<!-- Dobitý Baterky PWA Map Optimalizace -->' . "\n";
    echo '<meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">' . "\n";
    echo '<meta name="mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-capable" content="yes">' . "\n";
    echo '<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">' . "\n";
    echo '<meta name="apple-mobile-web-app-title" content="DB Mapa">' . "\n";
    
    // Optimalizace pro mapové dlaždice
    echo '<meta name="format-detection" content="telephone=no">' . "\n";
    echo '<meta name="msapplication-tap-highlight" content="no">' . "\n";
}, 1);

add_action('wp_footer', function () {
    if (!db_is_map_app_page()) return;
    
    // PWA for WP plugin už registruje Service Worker
    // Přidáme pouze specifické optimalizace pro mapu
    
    echo '<script>' . "\n";
    echo '  (function(){' . "\n";
    echo '    // Detekce PWA standalone módu' . "\n";
    echo '    const isStandalone = window.matchMedia(\'(display-mode: standalone)\').matches || window.navigator.standalone === true;' . "\n";
    echo '    if (isStandalone) {' . "\n";
    echo '      document.documentElement.classList.add(\'pwa-standalone\');' . "\n";
    echo '      document.body.classList.add(\'pwa-standalone\');' . "\n";
    echo '      console.log(\'DB Mapa: PWA standalone mód aktivní\');' . "\n";
    echo '    }' . "\n";
    echo '    ' . "\n";
    echo '    // Optimalizace pro mapové dlaždice v PWA' . "\n";
    echo '    if (window.L && window.L.map) {' . "\n";
    echo '      // PWA optimalizace pro Leaflet' . "\n";
    echo '      L.Map.include({' . "\n";
    echo '        _onResize: function() {' . "\n";
    echo '          if (this._resizeRequest) {' . "\n";
    echo '            clearTimeout(this._resizeRequest);' . "\n";
    echo '          }' . "\n";
    echo '          this._resizeRequest = setTimeout(L.Util.bind(function() {' . "\n";
    echo '            this.invalidateSize();' . "\n";
    echo '          }, this), 100);' . "\n";
    echo '        }' . "\n";
    echo '      });' . "\n";
    echo '    }' . "\n";
    echo '  })();' . "\n";
    echo '</script>' . "\n";
}, 100);
*/
