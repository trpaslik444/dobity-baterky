<?php
/**
 * Plugin Name: Dobitý Baterky – Elektromobilní průvodce
 * Plugin URI:  https://example.com/dobity-baterky
 * Description: Interaktivní průvodce nabíjecími stanicemi s pokročilým systémem správy nearby bodů a automatizovaným zpracováním dat.
 * Version:     2.0.0
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

// Definice konstant
define( 'DB_PLUGIN_FILE', __FILE__ );
define( 'DB_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'DB_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'DB_PLUGIN_VERSION', '1.0.5-local-working' );

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
        
        // Pokud je Members plugin aktivní, kontrolujeme 'access_app' capability
        if ( is_plugin_active('members/members.php') ) {
            $cap = db_required_capability();
            
            // Debug informace (pouze pro adminy)
            if ( current_user_can('administrator') && defined('WP_DEBUG') && WP_DEBUG ) {
                error_log('[DB MAP DEBUG] User: ' . wp_get_current_user()->user_login . 
                         ', Cap: ' . $cap . 
                         ', Has cap: ' . (current_user_can($cap) ? 'YES' : 'NO') .
                         ', Is logged in: ' . (is_user_logged_in() ? 'YES' : 'NO'));
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

// Hooky aktivace a deaktivace s bezpečnostním wrapperem
function db_safe_activate() {
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

// Načítání assetů s ochranou - spouští se až po init
add_action('wp_enqueue_scripts', function() {
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
    
    // Data pro JS
    wp_localize_script( 'db-map', 'dbMapData', array(
        'restUrl'   => rest_url( 'db/v1/map' ),
        'restNonce' => wp_create_nonce( 'wp_rest' ),
        'iconsBase' => plugins_url( 'assets/icons/', DB_PLUGIN_FILE ),
        'pluginUrl' => plugins_url( '/', DB_PLUGIN_FILE ),
        'dbLogoUrl' => plugins_url( 'assets/DB_bez(2160px).svg', DB_PLUGIN_FILE ),
        'isMapPage' => function_exists('db_is_map_app_page') ? db_is_map_app_page() : false,
        'pwaEnabled' => class_exists('PWAforWP') ? true : false,
        'isAdmin' => current_user_can('administrator') || current_user_can('editor'),
        'ajaxUrl' => admin_url('admin-ajax.php'),
        'adminNonce' => wp_create_nonce('db_admin_actions'),
        'adminUrl' => admin_url(),
    ) );
}, 20);

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
}, 999);

// Přidání custom query vars pro lepší URL strukturu
add_filter('query_vars', function($vars) {
    $vars[] = 'db_location_id';
    $vars[] = 'db_location_type';
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
}, 10);

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
    } catch (\Exception $e) {
        error_log('[PROVIDER MANAGER] Failed to initialize: ' . $e->getMessage());
        // Don't break admin - just log the error
    }
}

// Inicializovat automatické zpracování i mimo administraci
if ( file_exists( __DIR__ . '/includes/Jobs/Nearby_Auto_Processor.php' ) ) {
    require_once __DIR__ . '/includes/Jobs/Nearby_Auto_Processor.php';
    if ( class_exists( 'DB\Jobs\Nearby_Auto_Processor' ) ) {
        DB\Jobs\Nearby_Auto_Processor::get_instance();
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
}

// ===== PWA INTEGRACE S PWA FOR WP PLUGINEM =====

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
