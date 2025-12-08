<?php
/**
 * REST endpoint pro GeoJSON mapu
 * @package DobityBaterky
 */

namespace DB;

require_once __DIR__ . '/Util/Places_Enrichment_Service.php';
use DB\Util\Places_Enrichment_Service;

class REST_Map {
    private static $instance = null;
    private $enrichment_service;

    const GOOGLE_CACHE_TTL = DAY_IN_SECONDS * 30; // Google Places Service Terms allow storing Place Details for max 30 dní
    const TRIPADVISOR_CACHE_TTL = DAY_IN_SECONDS; // Tripadvisor Content API vyžaduje refresh do 24 hodin
    const GOOGLE_DAILY_LIMIT = 900; // Bezplatný tarif má 1000 dotazů/den – necháme si rezervu
    const TRIPADVISOR_DAILY_LIMIT = 500;
    const USAGE_OPTION = 'db_poi_api_usage';

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'wp_ajax_google_places_nearby', array( $this, 'ajax_google_places_nearby' ) );
        add_action( 'wp_ajax_google_place_details', array( $this, 'ajax_google_place_details' ) );
        // Hook pro invalidaci cache při změně/uložení postu
        add_action( 'save_post', array( $this, 'invalidate_special_cache' ), 10, 2 );
        $this->enrichment_service = Places_Enrichment_Service::get_instance();
    }

    public function register() {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes() {
         // Google Places API proxy
        register_rest_route( 'db/v1', '/google-places', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_google_places' ),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ) );
        
        // Google Places Search (Text Search + Nearby Search)
        register_rest_route( 'db/v1', '/google-places-search', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_google_places_search' ),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ) );
        
        // Google Places Nearby Search (New API)
        register_rest_route( 'db/v1', '/google-places-nearby', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_google_places_nearby' ),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ) );
        
        // Google Place Details (New API)
        register_rest_route( 'db/v1', '/google-place-details', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_google_place_details' ),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ) );
        
        // POI creation endpoint
        register_rest_route( 'db/v1', '/poi', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_add_poi' ),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ) );
        
        // POI photo upload endpoint
        register_rest_route( 'poi/v1', '/photo', array(
            'methods'  => 'POST',
            'callback' => array( $this, 'handle_poi_photo_upload' ),
            'permission_callback' => function() { return current_user_can('upload_files'); },
        ) );
        
        // Map data endpoint - striktní endpoint vyžadující lat/lng/radius_km
        // Fetch je on-demand podle středu mapy, s minimálním payloadem
        register_rest_route( 'db/v1', '/map', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_map' ),
            'permission_callback' => function ( $request ) {
                // Zkontroluj nonce autentizaci (pokud je poslán)
                $nonce = $request->get_header( 'X-WP-Nonce' );
                if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                    return false;
                }

                // Kontrola přístupu - vždy vynucovat db_user_can_see_map() pro bezpečnost
                // Tato funkce vyžaduje přihlášeného uživatele s příslušnou capability
                if ( function_exists('db_user_can_see_map') ) {
                    return db_user_can_see_map();
                }
                
                // Pokud funkce neexistuje, zamítnout přístup (bezpečnostní opatření)
                return false;
            },
        ) );

        // Detail endpoint pro full data - volá se při kliknutí na pin
        register_rest_route( 'db/v1', '/map-detail/(?P<type>[a-z_]+)/(?P<id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_map_detail' ),
            'permission_callback' => function ( $request ) {
                $nonce = $request->get_header( 'X-WP-Nonce' );
                if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                    return false;
                }
                return function_exists('db_user_can_see_map') ? db_user_can_see_map() : false;
            },
        ) );

        // Providers endpoint
        register_rest_route( 'db/v1', '/providers', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_providers' ),
            'permission_callback' => function ( $request ) {
                if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
                    return false;
                }
                return function_exists('db_user_can_see_map') ? db_user_can_see_map() : false;
            },
        ) );

        // Filter options endpoint - globální katalog filtrů z celé DB
        register_rest_route( 'db/v1', '/filter-options', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_filter_options' ),
            'permission_callback' => function ( $request ) {
                $nonce = $request->get_header( 'X-WP-Nonce' );
                if ( $nonce && ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
                    return false;
                }
                return function_exists('db_user_can_see_map') ? db_user_can_see_map() : false;
            },
        ) );

        // Externí detaily POI (Google Places / Tripadvisor)
        register_rest_route( 'db/v1', '/poi-external/(?P<id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_poi_external' ),
            'permission_callback' => function ( $request ) {
                if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
                    return false;
                }

                return function_exists('db_user_can_see_map') ? db_user_can_see_map() : false;
            },
        ) );

        // Internal database search endpoint
        register_rest_route( 'db/v1', '/map-search', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_map_search' ),
            'permission_callback' => function ( $request ) {
                if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
                    return false;
                }

                return function_exists('db_user_can_see_map') ? db_user_can_see_map() : false;
            },
            'args' => array(
                'query' => array(
                    'required' => true,
                    'type'     => 'string',
                ),
                'limit' => array(
                    'type'    => 'integer',
                    'default' => 8,
                ),
                'post_types' => array(
                    'type' => 'string',
                ),
            ),
        ) );

        // TomTom/DATEX endpointy byly vypnuty
        
        // Debug endpoint for charging location details
        register_rest_route( 'dobity-baterky/v1', '/charging-location-details/(?P<id>\d+)', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'get_charging_location_details' ),
            'permission_callback' => '__return_true',
        ) );
        
        // Dev seed endpoint
        register_rest_route('db/v1', '/dev/seed', array(
            'methods' => 'POST',
            'callback' => array($this, 'dev_seed'),
            'permission_callback' => function ($request) {
                return current_user_can('manage_options')
                    && wp_verify_nonce($request->get_header('X-WP-Nonce'), 'wp_rest');
            },
            'args' => array(
                'lat' => array('default' => 50.0755, 'type' => 'number'),
                'lng' => array('default' => 14.4378, 'type' => 'number')
            )
        ));
    }

    /**
     * Feedback REST endpoints (create/list/update)
     * Přidáno za účelem interní zpětné vazby na prvky UI
     */
    private function register_feedback_routes() {
        // Tuto metodu ponecháme připravenou pro případné oddělení do samostatné třídy
    }

    private function haversine_km( $lat1, $lon1, $lat2, $lon2 ) {
        $lat1 = deg2rad( $lat1 );
        $lon1 = deg2rad( $lon1 );
        $lat2 = deg2rad( $lat2 );
        $lon2 = deg2rad( $lon2 );

        $dlat = $lat2 - $lat1;
        $dlon = $lon2 - $lon1;

        $a = sin( $dlat / 2 ) * sin( $dlat / 2 ) + cos( $lat1 ) * cos( $lat2 ) * sin( $dlon / 2 ) * sin( $dlon / 2 );
        $c = 2 * atan2( sqrt( $a ), sqrt( 1 - $a ) );

        return 6371 * $c; // km
    }

    // Pomocná funkce: mapování meta klíčů podle typu
    private function get_latlng_keys_for_type( $pt ) {
        switch ($pt) {
            case 'charging_location': return ['lat' => '_db_lat', 'lng' => '_db_lng'];
            case 'rv_spot':           return ['lat' => '_rv_lat', 'lng' => '_rv_lng'];
            case 'poi':               return ['lat' => '_poi_lat', 'lng' => '_poi_lng'];
            default:                  return ['lat' => '_db_lat', 'lng' => '_db_lng'];
        }
    }

    private function maybe_decode_json_meta( $value ) {
        if ( empty( $value ) ) {
            return $value;
        }

        if ( is_string( $value ) ) {
            $decoded = json_decode( $value, true );
            if ( json_last_error() === JSON_ERROR_NONE ) {
                return $decoded;
            }
        }

        return $value;
    }

    private function format_timestamp_for_response( $timestamp ) {
        if ( ! $timestamp ) {
            return null;
        }

        return gmdate( 'c', intval( $timestamp ) );
    }

    public function handle_map( $request ) {
        // STRICT MODE: Endpoint vyžaduje lat, lng, radius_km (max 50 km)
        // Fetch je on-demand podle středu mapy, s minimálním payloadem
        
        // Robustní příjem parametrů - nejprve zkusit oddělené lat/lng, pak center
        $lat = $lng = null;
        $center = $request->get_param('center');
        
        // 1. Zkusit oddělené parametry lat/lng (preferované)
        if (isset($request['lat']) && isset($request['lng'])) {
            $lat = floatval($request['lat']);
            $lng = floatval($request['lng']);
        }
        // 2. Fallback na center="lat,lng" (pro kompatibilitu)
        elseif (is_string($center) && strpos($center, ',') !== false) {
            list($la, $lo) = array_map('trim', explode(',', $center, 2));
            if (is_numeric($la) && is_numeric($lo)) { 
                $lat = (float)$la; 
                $lng = (float)$lo; 
            }
        }
        
        $radius_km = floatval($request->get_param('radius_km'));
        $mode = $request->get_param('mode'); // 'special' pro special dataset bez radius
        
        // SPECIAL MODE: Pro db_recommended/free bez radius filtru
        if ($mode === 'special') {
            return $this->handle_map_special($request);
        }
        
        // STRICT VALIDATION: Vyžadovat lat, lng, radius_km (pro radius mode)
        if ($lat === null || $lng === null || $radius_km <= 0) {
            return new \WP_Error(
                'missing_required_params',
                'Endpoint vyžaduje parametry: lat, lng, radius_km (max 50 km)',
                array( 'status' => 400 )
            );
        }
        
        // Max radius 50 km
        $max_radius_km = 50;
        if ($radius_km > $max_radius_km) {
            return new \WP_Error(
                'radius_too_large',
                sprintf('Radius nesmí být větší než %d km', $max_radius_km),
                array( 'status' => 400 )
            );
        }
        
        // Types parametr: charger|poi|rv (nebo charging_location,rv_spot,poi)
        $types_param = $request->get_param('types') ?: $request->get_param('included') ?: $request->get_param('post_types');
        $post_type_map = [
            'charger' => 'charging_location',
            'rv_spot' => 'rv_spot',
            'poi'     => 'poi',
            'charging_location' => 'charging_location',
        ];
        
        if ($types_param) {
            $types = array_values(array_unique(array_map(function($t) use($post_type_map) {
                $t = trim($t);
                return $post_type_map[$t] ?? $t;
            }, explode(',', $types_param))));
        } else {
            $types = ['charging_location','rv_spot','poi'];
        }
        
        // Fields parametr: minimal|full (default minimal)
        $fields_mode = $request->get_param('fields') ?: 'minimal';
        if (!in_array($fields_mode, ['minimal', 'full'], true)) {
            $fields_mode = 'minimal';
        }
        
        // Limit výsledků (max 300)
        $limit = max(1, min(300, intval($request->get_param('limit') ?: 300)));

        // Filtry: providers, poi_types, amenities, connector_types, db_recommended, free
        $db_recommended = $request->get_param('db_recommended'); // '1' nebo null
        $free_only = $request->get_param('free'); // '1' nebo null
        
        // Taxonomy filtry
        $providers_param = $request->get_param('providers'); // csv
        $poi_types_param = $request->get_param('poi_types'); // csv
        $amenities_param = $request->get_param('amenities'); // csv
        $connector_types_param = $request->get_param('connector_types'); // csv
        
        $providers_filter = [];
        if ($providers_param) {
            $providers_filter = array_map('trim', explode(',', $providers_param));
            $providers_filter = array_filter($providers_filter);
        }
        
        $poi_types_filter = [];
        if ($poi_types_param) {
            $poi_types_filter = array_map('trim', explode(',', $poi_types_param));
            $poi_types_filter = array_filter($poi_types_filter);
        }
        
        $amenities_filter = [];
        if ($amenities_param) {
            $amenities_filter = array_map('trim', explode(',', $amenities_param));
            $amenities_filter = array_filter($amenities_filter);
        }
        
        $connector_types_filter = [];
        if ($connector_types_param) {
            $connector_types_filter = array_map('trim', explode(',', $connector_types_param));
            $connector_types_filter = array_filter($connector_types_filter);
        }
        
        // Cache podle hash(lat,lng,radius,types,fields,filters)
        $filters_hash = md5(sprintf('%s_%s_%s_%s_%s_%s', 
            implode(',', $providers_filter),
            implode(',', $poi_types_filter),
            implode(',', $amenities_filter),
            implode(',', $connector_types_filter),
            $db_recommended ? '1' : '0',
            $free_only ? '1' : '0'
        ));
        $cache_key = 'db_map_' . md5(sprintf('%.6f_%.6f_%.2f_%s_%s_%s', $lat, $lng, $radius_km, implode(',', $types), $fields_mode, $filters_hash));
        $cache_ttl = 45; // 45 sekund
        
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            return $cached_result;
        }

        // Vždy používáme radius režim (striktní endpoint)
        $use_radius = true;
        
        // cca převod km -> stupně (zvětšeno o 20% pro jistotu)
        $dLat  = ($radius_km * 1.2) / 111.0;
        $dLng  = ($radius_km * 1.2) / (111.0 * max(cos(deg2rad($lat)), 0.000001));
        $minLa = $lat - $dLat; $maxLa = $lat + $dLat;
        $minLo = $lng - $dLng; $maxLo = $lng + $dLng;

        $features = [];
        $debug_stats = [ 'per_type' => [], 'totals' => [ 'found' => 0, 'bbox' => 0, 'kept' => 0 ] ];
        
        // Inicializovat favorite_assignments před smyčkou přes typy
        $favorite_assignments = [];
        $favorite_folders_index = [];
        $current_user_id = get_current_user_id();
        if ($current_user_id > 0 && class_exists('\\DB\\Favorites_Manager')) {
            try {
                $favorites_manager = \DB\Favorites_Manager::get_instance();
                $state = $favorites_manager->get_state($current_user_id);
                $favorite_assignments = $state['assignments'] ?? [];
                $folders = $favorites_manager->get_folders($current_user_id);
                foreach ($folders as $folder) {
                    if (isset($folder['id'])) {
                        $favorite_folders_index[(string)$folder['id']] = $folder;
                    }
                }
            } catch (\Exception $e) {
                // Silent fail
            }
        }

        foreach ($types as $pt) {
            $keys = $this->get_latlng_keys_for_type($pt);
            // error_log('DB Map REST: Meta klíče pro ' . $pt . ': ' . print_r($keys, true));

            $args = [
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'no_found_rows'  => true,
                // V radius větvi necháme vyšší strop (prefilter), následně ořízneme až po Haversine
                'posts_per_page' => 5000, // Zvýšíme limit pro lepší pokrytí před Haversine filtrem
                'orderby'        => 'date', // Seřadit podle data pro lepší pokrytí
                'order'          => 'DESC',
            ];
            
            // Přidat meta_query pro filtry
            $meta_query = array();
            
            // Filtr "DB doporučuje" - pouze pro charging_location
            if ($db_recommended === '1' && $pt === 'charging_location') {
                $meta_query[] = array(
                    'key'     => '_db_recommended',
                    'value'   => '1',
                    'compare' => '='
                );
            }
            
            // Filtr "Zdarma" - pouze pro charging_location
            if ($free_only === '1' && $pt === 'charging_location') {
                $meta_query[] = array(
                    'key'     => '_db_price',
                    'value'   => 'free',
                    'compare' => '='
                );
            }
            
            if (!empty($meta_query)) {
                $args['meta_query'] = $meta_query;
            }
            
            // Přidat tax_query pro taxonomy filtry
            $tax_query = array();
            
            // Provider filtr - pouze pro charging_location
            if (!empty($providers_filter) && $pt === 'charging_location') {
                $tax_query[] = array(
                    'taxonomy' => 'provider',
                    'field'    => 'slug',
                    'terms'    => $providers_filter,
                    'operator' => 'IN',
                );
            }
            
            // POI types filtr - pouze pro poi
            if (!empty($poi_types_filter) && $pt === 'poi') {
                $tax_query[] = array(
                    'taxonomy' => 'poi_type',
                    'field'    => 'slug',
                    'terms'    => $poi_types_filter,
                    'operator' => 'IN',
                );
            }
            
            // Connector types filtr - pouze pro charging_location (přes charger_type taxonomii)
            if (!empty($connector_types_filter) && $pt === 'charging_location') {
                $tax_query[] = array(
                    'taxonomy' => 'charger_type',
                    'field'    => 'slug',
                    'terms'    => $connector_types_filter,
                    'operator' => 'IN',
                );
            }
            
            // Amenities filtr - pro všechny typy
            if (!empty($amenities_filter)) {
                $tax_query[] = array(
                    'taxonomy' => 'amenity',
                    'field'    => 'slug',
                    'terms'    => $amenities_filter,
                    'operator' => 'IN',
                );
            }
            
            if (!empty($tax_query)) {
                if (count($tax_query) > 1) {
                    $tax_query['relation'] = 'AND';
                }
                $args['tax_query'] = $tax_query;
            }

            // Dočasně vypneme meta query - necháme všechno na Haversine
            // if ($use_radius) {
            //     $args['meta_query'] = [
            //         'relation' => 'AND',
            //         [
            //             'key'     => $keys['lat'],
            //             'value'   => $minLa,
            //             'type'    => 'NUMERIC',
            //             'compare' => '>=',
            //         ],
            //         [
            //             'key'     => $keys['lat'],
            //             'value'   => $maxLa,
            //             'type'    => 'NUMERIC',
            //             'compare' => '<=',
            //         ],
            //         [
            //             'key'     => $keys['lng'],
            //             'value'   => $minLo,
            //             'type'    => 'NUMERIC',
            //             'compare' => '>=',
            //         ],
            //         [
            //             'key'     => $keys['lng'],
            //             'value'   => $maxLo,
            //             'type'    => 'NUMERIC',
            //             'compare' => '<=',
            //         ],
            //     ];
            //     error_log('DB Map REST: Meta query pro ' . $pt . ': ' . print_r($args['meta_query'], true));
            // }

            $q = new \WP_Query($args);
            // error_log('DB Map REST: WP_Query pro ' . $pt . ' - nalezeno postů: ' . $q->post_count);
            
            $bbox_count = 0;
            $haversine_count = 0;
            
            foreach ($q->posts as $post) {
                $laV = get_post_meta($post->ID, $keys['lat'], true);
                $loV = get_post_meta($post->ID, $keys['lng'], true);
                // if ($pt === 'poi') {
                //     error_log('DB Map REST: POI ' . $post->ID . ' (' . $post->post_title . ') - lat: ' . $laV . ', lng: ' . $loV);
                // }
                
                // Normalizace čísel s čárkou (např. "49,5827") → tečka
                if (is_string($laV)) { $laV = str_replace(',', '.', trim($laV)); }
                if (is_string($loV)) { $loV = str_replace(',', '.', trim($loV)); }

                if (!is_numeric($laV) || !is_numeric($loV)) { 
                    continue; 
                }
                $laF = (float)$laV; $loF = (float)$loV;
                $bbox_count++;

                // Vždy používáme radius režim - výpočet vzdálenosti
                // Ochrana před edge-case: pokud je některá meta NULL/0/nesmysl, přeskoč před Haversinem
                if (abs($laF) < 0.000001 && abs($loF) < 0.000001) {
                    continue;
                }
                $distance_km = $this->haversine_km($lat, $lng, $laF, $loF);
                if ($distance_km > $radius_km) {
                    continue;
                }
                $haversine_count++;

                // Načtení ikon a barev pomocí Icon_Registry
                $icon_registry = \DB\Icon_Registry::get_instance();
                $icon_data = $icon_registry->get_icon($post);
                
                // MINIMAL PAYLOAD: pouze základní data pro zobrazení markerů
                if ($fields_mode === 'minimal') {
                    $properties = [
                        'id' => $post->ID,
                        'post_type' => $pt,
                        'lat' => $laF,
                        'lng' => $loF,
                        'title' => get_the_title($post),
                        'distance_km' => round($distance_km, 2),
                        'db_recommended' => get_post_meta($post->ID, '_db_recommended', true) === '1' ? 1 : 0,
                    ];
                    
                    // Ikony a barvy pro zobrazení
                    $properties['icon_slug'] = $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true);
                    $properties['icon_color'] = $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true);
                    
                    // Pro charging_location: provider/charger_type pro barvu
                    if ($pt === 'charging_location') {
                        $provider_terms = wp_get_post_terms($post->ID, 'provider');
                        if (!empty($provider_terms) && !is_wp_error($provider_terms)) {
                            $properties['provider'] = $provider_terms[0]->name;
                            $properties['provider_slug'] = $provider_terms[0]->slug;
                        }
                        $charger_terms = wp_get_post_terms($post->ID, 'charger_type');
                        if (!empty($charger_terms) && !is_wp_error($charger_terms)) {
                            $properties['charger_type'] = $charger_terms[0]->name;
                            $properties['charger_type_slug'] = $charger_terms[0]->slug;
                        }
                    }
                } else {
                    // FULL PAYLOAD: všechna data (pro kompatibilitu nebo explicitní požadavek)
                    $properties = [
                        'id' => $post->ID,
                        'post_type' => $pt,
                        'title' => get_the_title($post),
                        'distance_km' => round($distance_km, 2),
                        'icon_slug' => $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true),
                        'icon_color' => $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true),
                        'svg_content' => $icon_data['svg_content'] ?? '',
                        'provider' => get_post_meta($post->ID, '_db_provider', true),
                        'speed' => get_post_meta($post->ID, '_db_speed', true),
                        'connectors' => get_post_meta($post->ID, '_db_connectors', true),
                        'konektory' => get_post_meta($post->ID, '_db_konektory', true),
                        'db_connectors' => get_post_meta($post->ID, '_db_connectors', true),
                        'db_recommended' => get_post_meta($post->ID, '_db_recommended', true) === '1' ? 1 : 0,
                        'business_status' => get_post_meta($post->ID, '_charging_business_status', true),
                        'charging_live_available' => get_post_meta($post->ID, '_charging_live_available', true),
                        'charging_live_total' => get_post_meta($post->ID, '_charging_live_total', true),
                        'charging_live_source' => get_post_meta($post->ID, '_charging_live_source', true),
                        'charging_live_updated' => get_post_meta($post->ID, '_charging_live_updated', true),
                        'charging_live_data_available' => get_post_meta($post->ID, '_charging_live_data_available', true),
                        'image' => get_post_meta($post->ID, '_db_image', true),
                        'address' => get_post_meta($post->ID, '_db_address', true),
                        'phone' => get_post_meta($post->ID, '_db_phone', true),
                        'website' => get_post_meta($post->ID, '_db_website', true),
                        'rating' => get_post_meta($post->ID, '_db_rating', true),
                        'description' => get_post_meta($post->ID, '_db_description', true),
                        'rv_type' => get_post_meta($post->ID, '_rv_type', true),
                        'rv_address' => get_post_meta($post->ID, '_rv_address', true),
                        'rv_phone' => get_post_meta($post->ID, '_rv_phone', true),
                        'rv_website' => get_post_meta($post->ID, '_rv_website', true),
                        'rv_rating' => get_post_meta($post->ID, '_rv_rating', true),
                        'rv_description' => get_post_meta($post->ID, '_rv_description', true),
                        'poi_type' => get_post_meta($post->ID, '_poi_type', true),
                        'poi_address' => get_post_meta($post->ID, '_poi_address', true),
                        'poi_phone' => get_post_meta($post->ID, '_poi_phone', true),
                        'poi_website' => get_post_meta($post->ID, '_poi_website', true),
                        'poi_rating' => get_post_meta($post->ID, '_poi_rating', true),
                        'poi_user_rating_count' => get_post_meta($post->ID, '_poi_user_rating_count', true),
                        'poi_price_level' => get_post_meta($post->ID, '_poi_price_level', true),
                        'poi_opening_hours' => get_post_meta($post->ID, '_poi_opening_hours', true),
                        'poi_reviews' => get_post_meta($post->ID, '_poi_reviews', true),
                        'poi_photos' => $this->maybe_decode_json_meta(get_post_meta($post->ID, '_poi_photos', true)),
                        'poi_photo_url' => get_post_meta($post->ID, '_poi_photo_url', true),
                        'poi_photo_license' => get_post_meta($post->ID, '_poi_photo_license', true),
                        'poi_photo_author' => get_post_meta($post->ID, '_poi_photo_author', true),
                        'poi_google_place_id' => get_post_meta($post->ID, '_poi_google_place_id', true) ?: get_post_meta($post->ID, '_poi_place_id', true),
                        'poi_tripadvisor_location_id' => get_post_meta($post->ID, '_poi_tripadvisor_location_id', true),
                        'poi_primary_external_source' => get_post_meta($post->ID, '_poi_primary_external_source', true) ?: 'google_places',
                        'poi_social_links' => $this->maybe_decode_json_meta(get_post_meta($post->ID, '_poi_social_links', true)),
                        'poi_external_cached_until' => array(
                            'google_places' => $this->format_timestamp_for_response(intval(get_post_meta($post->ID, '_poi_google_cache_expires', true))),
                            'tripadvisor'   => $this->format_timestamp_for_response(intval(get_post_meta($post->ID, '_poi_tripadvisor_cache_expires', true))),
                        ),
                        'poi_description' => get_post_meta($post->ID, '_poi_description', true),
                        'content' => $post->post_content,
                        'excerpt' => $post->post_excerpt,
                        'date' => $post->post_date,
                        'modified' => $post->post_modified,
                        'author' => $post->post_author,
                        'status' => $post->post_status,
                        'permalink' => get_permalink($post->ID),
                        'link' => get_permalink($post->ID),
                    ];

                if (!empty($favorite_assignments)) {
                    $fav_id = $favorite_assignments[$post->ID] ?? null;
                    if ($fav_id) {
                        $fav_id = (string) $fav_id;
                        $properties['favorite_folder_id'] = $fav_id;
                        if (isset($favorite_folders_index[$fav_id])) {
                            $folder_meta = $favorite_folders_index[$fav_id];
                            $properties['favorite_folder'] = [
                                'id' => $folder_meta['id'] ?? $fav_id,
                                'name' => $folder_meta['name'] ?? '',
                                'icon' => $folder_meta['icon'] ?? '',
                                'type' => $folder_meta['type'] ?? 'custom',
                                'limit' => $folder_meta['limit'] ?? 0,
                            ];
                        }
                    }
                }

                // Pro POI přidáme také term metadata pro ikony
                if ($pt === 'poi') {
                    $poi_terms = wp_get_post_terms($post->ID, 'poi_type');
                    if (!empty($poi_terms) && !is_wp_error($poi_terms)) {
                        $term = $poi_terms[0];
                        $properties['poi_type'] = $term->name; // Přepíšeme meta hodnotu term názvem
                        $properties['poi_type_slug'] = $term->slug;
                    }
                    
                    // Načtení amenities pro POI
                    $amenity_terms = wp_get_post_terms($post->ID, 'amenity');
                    if (!empty($amenity_terms) && !is_wp_error($amenity_terms)) {
                        $amenities = [];
                        foreach ($amenity_terms as $amenity_term) {
                            $amenities[] = [
                                'name' => $amenity_term->name,
                                'slug' => $amenity_term->slug,
                                'icon' => get_term_meta($amenity_term->term_id, 'icon', true),
                            ];
                        }
                        $properties['amenities'] = $amenities;
                    }
                    
                    // Načtení rating z term metadat pro POI
                    $rating_terms = wp_get_post_terms($post->ID, 'rating');
                    if (!empty($rating_terms) && !is_wp_error($rating_terms)) {
                        $term = $rating_terms[0];
                        $properties['rating'] = $term->name;
                        $properties['rating_slug'] = $term->slug;
                    }
                    
                    // POI nemají konektory - ty jsou pouze pro nabíječky
                }

                // Pro RV spots přidáme také term metadata pro ikony
                if ($pt === 'rv_spot') {
                    $rv_terms = wp_get_post_terms($post->ID, 'rv_type');
                    if (!empty($rv_terms) && !is_wp_error($rv_terms)) {
                        $term = $rv_terms[0];
                        $properties['rv_type'] = $term->name; // Přepíšeme meta hodnotu term názvem
                        $properties['rv_type_slug'] = $term->slug;
                    }
                    
                    // Načtení amenities pro RV spots
                    $amenity_terms = wp_get_post_terms($post->ID, 'amenity');
                    if (!empty($amenity_terms) && !is_wp_error($amenity_terms)) {
                        $amenities = [];
                        foreach ($amenity_terms as $amenity_term) {
                            $amenities[] = [
                                'name' => $amenity_term->name,
                                'slug' => $amenity_term->slug,
                                'icon' => get_term_meta($amenity_term->term_id, 'icon', true),
                            ];
                        }
                        $properties['amenities'] = $amenities;
                    }
                    
                    // Načtení rating z term metadat pro RV spots
                    $rating_terms = wp_get_post_terms($post->ID, 'rating');
                    if (!empty($rating_terms) && !is_wp_error($rating_terms)) {
                        $term = $rating_terms[0];
                        $properties['rating'] = $term->name;
                        $properties['rating_slug'] = $term->slug;
                    }
                    
                    // RV spots nemají konektory - ty jsou pouze pro nabíječky
                }

                // Pro charging_location přidáme také term metadata pro ikony
                if ($pt === 'charging_location') {
                    $charger_terms = wp_get_post_terms($post->ID, 'charger_type');
                    if (!empty($charger_terms) && !is_wp_error($charger_terms)) {
                        $term = $charger_terms[0];
                        $properties['charger_type'] = $term->name;
                        $properties['charger_type_slug'] = $term->slug;
                    }
                    
                    $provider_terms = wp_get_post_terms($post->ID, 'provider');
                    if (!empty($provider_terms) && !is_wp_error($provider_terms)) {
                        $term = $provider_terms[0];
                        $properties['provider'] = $term->name; // Přepíšeme meta hodnotu term názvem
                        $properties['provider_slug'] = $term->slug;
                    }
                    
                    // Načtení konektorů z charger_type taxonomie (správná taxonomie pro ikony)
                    $charger_type_terms = wp_get_post_terms($post->ID, 'charger_type');
                    $charger_counts = get_post_meta($post->ID, '_db_charger_counts', true);
                    $charger_powers = get_post_meta($post->ID, '_db_charger_power', true); // Načíst výkony z post meta
                    
                    if (!empty($charger_type_terms) && !is_wp_error($charger_type_terms)) {
                        $connectors = [];
                        foreach ($charger_type_terms as $charger_term) {
                            // Získat počet z _db_charger_counts (hledat podle term ID, ne názvu)
                            $quantity = 1; // výchozí hodnota
                            if (is_array($charger_counts)) {
                                // Zkusit najít podle term ID
                                if (isset($charger_counts[$charger_term->term_id])) {
                                    $quantity = intval($charger_counts[$charger_term->term_id]);
                                }
                                // Fallback: zkusit najít podle názvu
                                elseif (isset($charger_counts[$charger_term->name])) {
                                    $quantity = intval($charger_counts[$charger_term->name]);
                                }
                            }
                            
                            // Získat výkon z _db_charger_power (hledat podle term ID, ne názvu)
                            $power = 0; // výchozí hodnota
                            if (is_array($charger_powers)) {
                                // Zkusit najít podle term ID
                                if (isset($charger_powers[$charger_term->term_id])) {
                                    $power = floatval($charger_powers[$charger_term->term_id]);
                                }
                                // Fallback: zkusit najít podle názvu
                                elseif (isset($charger_powers[$charger_term->name])) {
                                    $power = floatval($charger_powers[$charger_term->name]);
                                }
                            }
                            // Fallback: zkusit získat z taxonomy term meta, pokud není v post meta
                            if ($power == 0) {
                                $term_power = get_term_meta($charger_term->term_id, 'power', true);
                                if ($term_power && is_numeric($term_power)) {
                                    $power = floatval($term_power);
                                }
                            }
                            
                            // SVG ikony dočasně zakázány - čekáme na správné ikony
                            $svg_icon = null;
                            
                            $connectors[] = [
                                'name' => $charger_term->name,
                                'slug' => $charger_term->slug,
                                'icon' => get_term_meta($charger_term->term_id, 'charger_icon', true), // Správný meta klíč pro ikony
                                'svg_icon' => $svg_icon, // Nový SVG systém
                                'type' => get_term_meta($charger_term->term_id, 'charger_current_type', true), // Správný meta klíč pro typ proudu
                                'power' => $power,
                                'power_kw' => $power, // Přidat power_kw pro kompatibilitu s getStationMaxKw()
                                'quantity' => $quantity, // Přidat počet
                                // Přidání vlastností pro kompatibilitu s JavaScript kódem
                                'connector_standard' => $charger_term->name,
                                'connector_type' => $charger_term->name,
                                'current_type' => get_term_meta($charger_term->term_id, 'charger_current_type', true),
                                'current' => get_term_meta($charger_term->term_id, 'charger_current_type', true),
                                'proud' => get_term_meta($charger_term->term_id, 'charger_current_type', true),
                                'typ' => $charger_term->name,
                            ];
                        }
                        $properties['connectors'] = $connectors;
                        $properties['konektory'] = $connectors; // Pro kompatibilitu
                    }
                    
                    // Fallback: načtení konektorů z post metadat, pokud nejsou definovány jako taxonomy terms
                    if (empty($properties['connectors'])) {
                        $meta_connectors = get_post_meta($post->ID, '_db_connectors', true);
                        $charger_counts = get_post_meta($post->ID, '_db_charger_counts', true);
                        $charger_powers = get_post_meta($post->ID, '_db_charger_power', true);
                        
                        // Debug: zkontrolovat, co se načetlo
                        if ($post->ID == 4436) { // Lidl stanice z logů
                        }
                        
                        
                        if (!empty($meta_connectors)) {
                            if (is_array($meta_connectors)) {
                                // Přidat počty a výkony z databáze
                                foreach ($meta_connectors as &$connector) {
                                    if (isset($charger_counts[$connector['type']])) {
                                        $connector['quantity'] = $charger_counts[$connector['type']];
                                    }
                                    if (isset($charger_powers[$connector['type']])) {
                                        $connector['power'] = $charger_powers[$connector['type']];
                                        $connector['power_kw'] = $charger_powers[$connector['type']];
                                        
                                        // Debug: zkontrolovat, co se přidalo
                                        if ($post->ID == 4436) {
                                        }
                                    }
                                }
                                $properties['connectors'] = $meta_connectors;
                                $properties['konektory'] = $meta_connectors;
                                $properties['db_connectors'] = $meta_connectors;
                            } else {
                                // Pokud je string, zkusit parsovat jako JSON
                                $parsed = json_decode($meta_connectors, true);
                                if (is_array($parsed)) {
                                    // Přidat počty a výkony z databáze
                                    foreach ($parsed as &$connector) {
                                        if (isset($charger_counts[$connector['type']])) {
                                            $connector['quantity'] = $charger_counts[$connector['type']];
                                        }
                                        if (isset($charger_powers[$connector['type']])) {
                                            $connector['power'] = $charger_powers[$connector['type']];
                                            $connector['power_kw'] = $charger_powers[$connector['type']];
                                        }
                                    }
                                    $properties['connectors'] = $parsed;
                                    $properties['konektory'] = $parsed;
                                    $properties['db_connectors'] = $parsed;
                                }
                            }
                        }
                        
                        // Další fallback - zkusit jiné meta klíče
                        if (empty($properties['connectors'])) {
                            $ocm_connectors = get_post_meta($post->ID, '_ocm_connectors', true);
                            $charger_counts = get_post_meta($post->ID, '_db_charger_counts', true);
                            $charger_powers = get_post_meta($post->ID, '_db_charger_power', true);
                            
                            if (!empty($ocm_connectors) && is_array($ocm_connectors)) {
                                // Přidat počty a výkony z databáze
                                foreach ($ocm_connectors as &$connector) {
                                    if (isset($charger_counts[$connector['type']])) {
                                        $connector['quantity'] = $charger_counts[$connector['type']];
                                    }
                                    if (isset($charger_powers[$connector['type']])) {
                                        $connector['power'] = $charger_powers[$connector['type']];
                                    }
                                }
                                $properties['connectors'] = $ocm_connectors;
                                $properties['konektory'] = $ocm_connectors;
                                $properties['db_connectors'] = $ocm_connectors;
                            }
                        }
                        
                        // Další fallback - zkusit _connectors
                        if (empty($properties['connectors'])) {
                            $connectors_meta = get_post_meta($post->ID, '_connectors', true);
                            
                            if (!empty($connectors_meta) && is_array($connectors_meta)) {
                                $properties['connectors'] = $connectors_meta;
                                $properties['konektory'] = $connectors_meta;
                                $properties['db_connectors'] = $connectors_meta;
                            }
                        }
                        
                        // Další fallback - zkusit _mpo_connectors
                        if (empty($properties['connectors'])) {
                            $mpo_connectors = get_post_meta($post->ID, '_mpo_connectors', true);
                            
                            if (!empty($mpo_connectors) && is_array($mpo_connectors)) {
                                $properties['connectors'] = $mpo_connectors;
                                $properties['konektory'] = $mpo_connectors;
                                $properties['db_connectors'] = $mpo_connectors;
                            }
                        }
                    }
                    
                    // Duplicitní fallback odstraněn - už je výše
                    
                    // Načtení amenities pro charging_location
                    $amenity_terms = wp_get_post_terms($post->ID, 'amenity');
                    if (!empty($amenity_terms) && !is_wp_error($amenity_terms)) {
                        $amenities = [];
                        foreach ($amenity_terms as $amenity_term) {
                            $amenities[] = [
                                'name' => $amenity_term->name,
                                'slug' => $amenity_term->slug,
                                'icon' => get_term_meta($amenity_term->term_id, 'icon', true),
                            ];
                        }
                        $properties['amenities'] = $amenities;
                    }
                    
                    // Načtení rating z term metadat pro charging_location
                    $rating_terms = wp_get_post_terms($post->ID, 'rating');
                    if (!empty($rating_terms) && !is_wp_error($rating_terms)) {
                        $term = $rating_terms[0];
                        $properties['rating'] = $term->name;
                        $properties['rating_slug'] = $term->slug;
                    }
                    
                    // DŮLEŽITÉ: Pro nabíječky NEPŘEPISUJEME icon_color, aby JavaScript mohl určit barvu podle AC/DC logiky
                    // Icon_Registry vrací null pro icon_color u nabíječek, což je správně
                    // JavaScript kód pak použije getChargerFill() pro určení barvy
                }

                $features[] = [
                    'type' => 'Feature',
                    'geometry' => ['type'=>'Point','coordinates'=>[$loF,$laF]],
                    'properties' => $properties,
                ];
            }
            // Uložení debug statistik pro aktuální post_type
            $debug_stats['per_type'][$pt] = [
                'found' => (int)$q->post_count,
                'bbox'  => (int)$bbox_count,
                'kept'  => (int)$haversine_count,
            ];
            $debug_stats['totals']['found'] += (int)$q->post_count;
            $debug_stats['totals']['bbox']  += (int)$bbox_count;
            $debug_stats['totals']['kept']  += (int)$haversine_count;
            
        }

        // Seřadit podle vzdálenosti (distance_km) a aplikovat limit
        usort($features, function($a, $b) {
            $aDist = $a['properties']['distance_km'] ?? PHP_INT_MAX;
            $bDist = $b['properties']['distance_km'] ?? PHP_INT_MAX;
            return $aDist <=> $bDist;
        });
        
        // Aplikovat limit (max 300)
        $total_before_limit = count($features);
        if (count($features) > $limit) {
            $features = array_slice($features, 0, $limit);
        }

        // Přidání meta informací pro diagnostiku
        $response_data = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'mode' => 'radius',
                'center' => [$lat, $lng],
                'radius_km' => $radius_km,
                'count' => count($features),
                'post_types' => $types,
                'fields_mode' => $fields_mode,
                'limit_used' => $limit,
                'total_before_limit' => $total_before_limit,
            ]
        ];
        
        // Uložit do cache
        set_transient($cache_key, $response_data, $cache_ttl);
        
        $resp = rest_ensure_response($response_data);
        $resp->header('Cache-Control','public, max-age=' . $cache_ttl);
        return $resp;
    }

    /**
     * Detail endpoint pro full data - volá se při kliknutí na pin
     * Endpoint: db/v1/map-detail/<type>/<id>
     */
    public function handle_map_detail( $request ) {
        $type = $request->get_param('type');
        $id = intval($request->get_param('id'));
        
        // Mapování typů
        $post_type_map = [
            'charger' => 'charging_location',
            'rv_spot' => 'rv_spot',
            'poi' => 'poi',
            'charging_location' => 'charging_location',
        ];
        
        $post_type = $post_type_map[$type] ?? $type;
        
        if (!in_array($post_type, ['charging_location', 'rv_spot', 'poi'], true)) {
            return new \WP_Error(
                'invalid_type',
                'Neplatný typ. Povolené typy: charger, poi, rv_spot',
                array( 'status' => 400 )
            );
        }
        
        if ($id <= 0) {
            return new \WP_Error(
                'invalid_id',
                'Neplatné ID',
                array( 'status' => 400 )
            );
        }
        
        $post = get_post($id);
        if (!$post || $post->post_type !== $post_type || $post->post_status !== 'publish') {
            return new \WP_Error(
                'not_found',
                'Bod nebyl nalezen',
                array( 'status' => 404 )
            );
        }
        
        // Použít stejnou logiku jako handle_map pro full payload
        // Vytvořit dočasný request s fields=full
        $temp_request = new \WP_REST_Request('GET', '/db/v1/map');
        $temp_request->set_param('ids', (string)$id);
        $temp_request->set_param('fields', 'full');
        
        // Získat souřadnice pro výpočet vzdálenosti (pokud je poslán center)
        $center = $request->get_param('center');
        $lat = $lng = null;
        if ($center && is_string($center) && strpos($center, ',') !== false) {
            list($la, $lo) = array_map('trim', explode(',', $center, 2));
            if (is_numeric($la) && is_numeric($lo)) {
                $lat = (float)$la;
                $lng = (float)$lo;
            }
        }
        
        // Načíst data pomocí existující logiky
        $keys = $this->get_latlng_keys_for_type($post_type);
        $laV = get_post_meta($post->ID, $keys['lat'], true);
        $loV = get_post_meta($post->ID, $keys['lng'], true);
        
        if (is_string($laV)) { $laV = str_replace(',', '.', trim($laV)); }
        if (is_string($loV)) { $loV = str_replace(',', '.', trim($loV)); }
        
        if (!is_numeric($laV) || !is_numeric($loV)) {
            return new \WP_Error(
                'invalid_coordinates',
                'Bod nemá platné souřadnice',
                array( 'status' => 400 )
            );
        }
        
        $laF = (float)$laV;
        $loF = (float)$loV;
        
        // Vytvořit feature s full payloadem
        // Použít stejnou logiku jako v handle_map pro full mode
        $icon_registry = \DB\Icon_Registry::get_instance();
        $icon_data = $icon_registry->get_icon($post);
        
        $properties = [
            'id' => $post->ID,
            'post_type' => $post_type,
            'title' => get_the_title($post),
            'icon_slug' => $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true),
            'icon_color' => $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true),
            'svg_content' => $icon_data['svg_content'] ?? '',
            'provider' => get_post_meta($post->ID, '_db_provider', true),
            'speed' => get_post_meta($post->ID, '_db_speed', true),
            'connectors' => get_post_meta($post->ID, '_db_connectors', true),
            'konektory' => get_post_meta($post->ID, '_db_konektory', true),
            'db_connectors' => get_post_meta($post->ID, '_db_connectors', true),
            'db_recommended' => get_post_meta($post->ID, '_db_recommended', true) === '1' ? 1 : 0,
            'business_status' => get_post_meta($post->ID, '_charging_business_status', true),
            'charging_live_available' => get_post_meta($post->ID, '_charging_live_available', true),
            'charging_live_total' => get_post_meta($post->ID, '_charging_live_total', true),
            'charging_live_source' => get_post_meta($post->ID, '_charging_live_source', true),
            'charging_live_updated' => get_post_meta($post->ID, '_charging_live_updated', true),
            'charging_live_data_available' => get_post_meta($post->ID, '_charging_live_data_available', true),
            'image' => get_post_meta($post->ID, '_db_image', true),
            'address' => get_post_meta($post->ID, '_db_address', true),
            'phone' => get_post_meta($post->ID, '_db_phone', true),
            'website' => get_post_meta($post->ID, '_db_website', true),
            'rating' => get_post_meta($post->ID, '_db_rating', true),
            'description' => get_post_meta($post->ID, '_db_description', true),
            'rv_type' => get_post_meta($post->ID, '_rv_type', true),
            'rv_address' => get_post_meta($post->ID, '_rv_address', true),
            'rv_phone' => get_post_meta($post->ID, '_rv_phone', true),
            'rv_website' => get_post_meta($post->ID, '_rv_website', true),
            'rv_rating' => get_post_meta($post->ID, '_rv_rating', true),
            'rv_description' => get_post_meta($post->ID, '_rv_description', true),
            'poi_type' => get_post_meta($post->ID, '_poi_type', true),
            'poi_address' => get_post_meta($post->ID, '_poi_address', true),
            'poi_phone' => get_post_meta($post->ID, '_poi_phone', true),
            'poi_website' => get_post_meta($post->ID, '_poi_website', true),
            'poi_rating' => get_post_meta($post->ID, '_poi_rating', true),
            'poi_user_rating_count' => get_post_meta($post->ID, '_poi_user_rating_count', true),
            'poi_price_level' => get_post_meta($post->ID, '_poi_price_level', true),
            'poi_opening_hours' => get_post_meta($post->ID, '_poi_opening_hours', true),
            'poi_reviews' => get_post_meta($post->ID, '_poi_reviews', true),
            'poi_photos' => $this->maybe_decode_json_meta(get_post_meta($post->ID, '_poi_photos', true)),
            'poi_photo_url' => get_post_meta($post->ID, '_poi_photo_url', true),
            'poi_photo_license' => get_post_meta($post->ID, '_poi_photo_license', true),
            'poi_photo_author' => get_post_meta($post->ID, '_poi_photo_author', true),
            'poi_google_place_id' => get_post_meta($post->ID, '_poi_google_place_id', true) ?: get_post_meta($post->ID, '_poi_place_id', true),
            'poi_tripadvisor_location_id' => get_post_meta($post->ID, '_poi_tripadvisor_location_id', true),
            'poi_primary_external_source' => get_post_meta($post->ID, '_poi_primary_external_source', true) ?: 'google_places',
            'poi_social_links' => $this->maybe_decode_json_meta(get_post_meta($post->ID, '_poi_social_links', true)),
            'poi_external_cached_until' => array(
                'google_places' => $this->format_timestamp_for_response(intval(get_post_meta($post->ID, '_poi_google_cache_expires', true))),
                'tripadvisor'   => $this->format_timestamp_for_response(intval(get_post_meta($post->ID, '_poi_tripadvisor_cache_expires', true))),
            ),
            'poi_description' => get_post_meta($post->ID, '_poi_description', true),
            'content' => $post->post_content,
            'excerpt' => $post->post_excerpt,
            'date' => $post->post_date,
            'modified' => $post->post_modified,
            'author' => $post->post_author,
            'status' => $post->post_status,
            'permalink' => get_permalink($post->ID),
            'link' => get_permalink($post->ID),
        ];
        
        // Výpočet vzdálenosti pokud je poslán center
        if ($lat !== null && $lng !== null) {
            $properties['distance_km'] = round($this->haversine_km($lat, $lng, $laF, $loF), 2);
        }
        
        // Načíst term metadata (stejně jako v handle_map)
        if ($post_type === 'poi') {
            $poi_terms = wp_get_post_terms($post->ID, 'poi_type');
            if (!empty($poi_terms) && !is_wp_error($poi_terms)) {
                $term = $poi_terms[0];
                $properties['poi_type'] = $term->name;
                $properties['poi_type_slug'] = $term->slug;
            }
            
            $amenity_terms = wp_get_post_terms($post->ID, 'amenity');
            if (!empty($amenity_terms) && !is_wp_error($amenity_terms)) {
                $amenities = [];
                foreach ($amenity_terms as $amenity_term) {
                    $amenities[] = [
                        'name' => $amenity_term->name,
                        'slug' => $amenity_term->slug,
                        'icon' => get_term_meta($amenity_term->term_id, 'icon', true),
                    ];
                }
                $properties['amenities'] = $amenities;
            }
            
            $rating_terms = wp_get_post_terms($post->ID, 'rating');
            if (!empty($rating_terms) && !is_wp_error($rating_terms)) {
                $term = $rating_terms[0];
                $properties['rating'] = $term->name;
                $properties['rating_slug'] = $term->slug;
            }
        } elseif ($post_type === 'rv_spot') {
            $rv_terms = wp_get_post_terms($post->ID, 'rv_type');
            if (!empty($rv_terms) && !is_wp_error($rv_terms)) {
                $term = $rv_terms[0];
                $properties['rv_type'] = $term->name;
                $properties['rv_type_slug'] = $term->slug;
            }
            
            $amenity_terms = wp_get_post_terms($post->ID, 'amenity');
            if (!empty($amenity_terms) && !is_wp_error($amenity_terms)) {
                $amenities = [];
                foreach ($amenity_terms as $amenity_term) {
                    $amenities[] = [
                        'name' => $amenity_term->name,
                        'slug' => $amenity_term->slug,
                        'icon' => get_term_meta($amenity_term->term_id, 'icon', true),
                    ];
                }
                $properties['amenities'] = $amenities;
            }
            
            $rating_terms = wp_get_post_terms($post->ID, 'rating');
            if (!empty($rating_terms) && !is_wp_error($rating_terms)) {
                $term = $rating_terms[0];
                $properties['rating'] = $term->name;
                $properties['rating_slug'] = $term->slug;
            }
        } elseif ($post_type === 'charging_location') {
            $charger_terms = wp_get_post_terms($post->ID, 'charger_type');
            if (!empty($charger_terms) && !is_wp_error($charger_terms)) {
                $term = $charger_terms[0];
                $properties['charger_type'] = $term->name;
                $properties['charger_type_slug'] = $term->slug;
            }
            
            $provider_terms = wp_get_post_terms($post->ID, 'provider');
            if (!empty($provider_terms) && !is_wp_error($provider_terms)) {
                $term = $provider_terms[0];
                $properties['provider'] = $term->name;
                $properties['provider_slug'] = $term->slug;
            }
            
            // Načíst konektory (stejně jako v handle_map)
            $charger_type_terms = wp_get_post_terms($post->ID, 'charger_type');
            $charger_counts = get_post_meta($post->ID, '_db_charger_counts', true);
            $charger_powers = get_post_meta($post->ID, '_db_charger_power', true);
            
            if (!empty($charger_type_terms) && !is_wp_error($charger_type_terms)) {
                $connectors = [];
                foreach ($charger_type_terms as $charger_term) {
                    $quantity = 1;
                    if (is_array($charger_counts) && isset($charger_counts[$charger_term->term_id])) {
                        $quantity = intval($charger_counts[$charger_term->term_id]);
                    } elseif (is_array($charger_counts) && isset($charger_counts[$charger_term->name])) {
                        $quantity = intval($charger_counts[$charger_term->name]);
                    }
                    
                    $power = 0;
                    if (is_array($charger_powers) && isset($charger_powers[$charger_term->term_id])) {
                        $power = floatval($charger_powers[$charger_term->term_id]);
                    } elseif (is_array($charger_powers) && isset($charger_powers[$charger_term->name])) {
                        $power = floatval($charger_powers[$charger_term->name]);
                    }
                    if ($power == 0) {
                        $term_power = get_term_meta($charger_term->term_id, 'power', true);
                        if ($term_power && is_numeric($term_power)) {
                            $power = floatval($term_power);
                        }
                    }
                    
                    $connectors[] = [
                        'name' => $charger_term->name,
                        'slug' => $charger_term->slug,
                        'icon' => get_term_meta($charger_term->term_id, 'charger_icon', true),
                        'type' => get_term_meta($charger_term->term_id, 'charger_current_type', true),
                        'power' => $power,
                        'power_kw' => $power,
                        'quantity' => $quantity,
                    ];
                }
                $properties['connectors'] = $connectors;
                $properties['konektory'] = $connectors;
            }
            
            $amenity_terms = wp_get_post_terms($post->ID, 'amenity');
            if (!empty($amenity_terms) && !is_wp_error($amenity_terms)) {
                $amenities = [];
                foreach ($amenity_terms as $amenity_term) {
                    $amenities[] = [
                        'name' => $amenity_term->name,
                        'slug' => $amenity_term->slug,
                        'icon' => get_term_meta($amenity_term->term_id, 'icon', true),
                    ];
                }
                $properties['amenities'] = $amenities;
            }
            
            $rating_terms = wp_get_post_terms($post->ID, 'rating');
            if (!empty($rating_terms) && !is_wp_error($rating_terms)) {
                $term = $rating_terms[0];
                $properties['rating'] = $term->name;
                $properties['rating_slug'] = $term->slug;
            }
        }
        
        // Favorites (pokud je uživatel přihlášen)
        $current_user_id = get_current_user_id();
        if ($current_user_id > 0 && class_exists('\\DB\\Favorites_Manager')) {
            try {
                $favorites_manager = \DB\Favorites_Manager::get_instance();
                $state = $favorites_manager->get_state($current_user_id);
                $favorite_assignments = $state['assignments'] ?? [];
                $fav_id = $favorite_assignments[$post->ID] ?? null;
                if ($fav_id) {
                    $fav_id = (string) $fav_id;
                    $properties['favorite_folder_id'] = $fav_id;
                    $folders = $favorites_manager->get_folders($current_user_id);
                    foreach ($folders as $folder) {
                        if (isset($folder['id']) && (string)$folder['id'] === $fav_id) {
                            $properties['favorite_folder'] = [
                                'id' => $folder['id'] ?? $fav_id,
                                'name' => $folder['name'] ?? '',
                                'icon' => $folder['icon'] ?? '',
                                'type' => $folder['type'] ?? 'custom',
                                'limit' => $folder['limit'] ?? 0,
                            ];
                            break;
                        }
                    }
                }
            } catch ( \Throwable $e ) {
                // Ignorovat chyby favorites
            }
        }
        
        $feature = [
            'type' => 'Feature',
            'geometry' => ['type' => 'Point', 'coordinates' => [$loF, $laF]],
            'properties' => $properties,
        ];
        
        return rest_ensure_response([
            'type' => 'FeatureCollection',
            'features' => [$feature],
        ]);
    }

    public function handle_map_search( $request ) {
        $raw_query = $request->get_param( 'query' );
        $query = is_string( $raw_query ) ? sanitize_text_field( wp_unslash( $raw_query ) ) : '';
        $query = trim( $query );

        if ( ( function_exists( 'mb_strlen' ) ? mb_strlen( $query ) : strlen( $query ) ) < 2 ) {
            return rest_ensure_response( array( 'results' => array() ) );
        }

        $limit_param = $request->get_param( 'limit' );
        $limit = is_numeric( $limit_param ) ? (int) $limit_param : 8;
        $limit = max( 1, min( 25, $limit ) );

        $post_types = $this->determine_search_post_types( $request->get_param( 'post_types' ) );

        $results = array();
        $seen_ids = array();

        $title_query = new \WP_Query( array(
            'post_type'      => $post_types,
            'post_status'    => 'publish',
            'posts_per_page' => $limit,
            's'              => $query,
            'orderby'        => 'relevance',
            'order'          => 'DESC',
            'no_found_rows'  => true,
        ) );

        if ( $title_query->have_posts() ) {
            foreach ( $title_query->posts as $post ) {
                $formatted = $this->format_search_result( $post, $query );
                if ( $formatted ) {
                    $results[] = $formatted;
                    $seen_ids[ $post->ID ] = true;
                    if ( count( $results ) >= $limit ) {
                        break;
                    }
                }
            }
        }
        wp_reset_postdata();

        if ( count( $results ) < $limit ) {
            $remaining = $limit - count( $results );
            $meta_keys = array( '_db_address', '_poi_address', '_rv_address' );
            $meta_query = array( 'relation' => 'OR' );
            foreach ( $meta_keys as $key ) {
                $meta_query[] = array(
                    'key'     => $key,
                    'value'   => $query,
                    'compare' => 'LIKE',
                );
            }

            $meta_query_args = array(
                'post_type'      => $post_types,
                'post_status'    => 'publish',
                'posts_per_page' => $remaining * 3,
                'post__not_in'   => array_keys( $seen_ids ),
                'meta_query'     => $meta_query,
                'orderby'        => 'modified',
                'order'          => 'DESC',
                'no_found_rows'  => true,
            );

            $meta_query_posts = new \WP_Query( $meta_query_args );
            if ( $meta_query_posts->have_posts() ) {
                foreach ( $meta_query_posts->posts as $post ) {
                    if ( isset( $seen_ids[ $post->ID ] ) ) {
                        continue;
                    }
                    $formatted = $this->format_search_result( $post, $query );
                    if ( $formatted ) {
                        $results[] = $formatted;
                        $seen_ids[ $post->ID ] = true;
                        if ( count( $results ) >= $limit * 2 ) {
                            break;
                        }
                    }
                }
            }
            wp_reset_postdata();
        }

        if ( empty( $results ) ) {
            return rest_ensure_response( array( 'results' => array() ) );
        }

        usort( $results, function( $a, $b ) {
            $score_compare = ( $b['score'] ?? 0 ) <=> ( $a['score'] ?? 0 );
            if ( 0 !== $score_compare ) {
                return $score_compare;
            }

            return strcmp( $a['title'] ?? '', $b['title'] ?? '' );
        } );

        $results = array_slice( $results, 0, $limit );
        $results = array_map( function( $item ) {
            if ( isset( $item['score'] ) ) {
                $item['confidence'] = $this->score_to_confidence( $item['score'] );
                unset( $item['score'] );
            }

            return $item;
        }, $results );

        return rest_ensure_response( array( 'results' => $results ) );
    }

    private function score_to_confidence( $score ) {
        if ( ! is_numeric( $score ) ) {
            return null;
        }

        $clamped = max( 0, min( 180, (float) $score ) );
        $normalized = (int) round( $clamped / 1.8 );

        return max( 0, min( 100, $normalized ) );
    }

    private function determine_search_post_types( $types_param ) {
        $allowed = array( 'charging_location', 'rv_spot', 'poi' );
        $map = array(
            'charger'            => 'charging_location',
            'charging_location'  => 'charging_location',
            'charging-location'  => 'charging_location',
            'rv'                 => 'rv_spot',
            'rv_spot'            => 'rv_spot',
            'poi'                => 'poi',
        );

        if ( is_string( $types_param ) && $types_param !== '' ) {
            $raw_types = array_filter( array_map( 'trim', explode( ',', $types_param ) ) );
            $normalized = array();
            foreach ( $raw_types as $type ) {
                $normalized[] = $map[ strtolower( $type ) ] ?? $type;
            }
            $normalized = array_values( array_unique( $normalized ) );
            $filtered = array_intersect( $normalized, $allowed );
            if ( ! empty( $filtered ) ) {
                return array_values( $filtered );
            }
        }

        return $allowed;
    }

    private function format_search_result( $post, $query ) {
        if ( ! ( $post instanceof \WP_Post ) ) {
            return null;
        }

        $keys = $this->get_latlng_keys_for_type( $post->post_type );
        $lat_raw = get_post_meta( $post->ID, $keys['lat'], true );
        $lng_raw = get_post_meta( $post->ID, $keys['lng'], true );
        $lat = $this->normalize_coordinate( $lat_raw );
        $lng = $this->normalize_coordinate( $lng_raw );

        if ( null === $lat || null === $lng ) {
            return null;
        }

        $title = get_the_title( $post );
        $address = $this->get_address_for_post( $post );
        $score = $this->compute_search_score( $post, $query, $title, $address );

        return array(
            'id'             => $post->ID,
            'post_type'      => $post->post_type,
            'type_label'     => $this->get_type_label( $post->post_type ),
            'title'          => $title,
            'address'        => $address,
            'lat'            => $lat,
            'lng'            => $lng,
            'is_recommended' => $this->is_post_recommended( $post ),
            'score'          => $score,
        );
    }

    private function compute_search_score( $post, $search, $title, $address ) {
        $score = 0;
        $search_lc = $this->lowercase( $search );
        $title_lc = $this->lowercase( $title );
        $address_lc = $this->lowercase( $address );

        if ( $title_lc === $search_lc && $title_lc !== '' ) {
            $score += 120;
        } elseif ( $title_lc !== '' && strpos( $title_lc, $search_lc ) === 0 ) {
            $score += 90;
        } elseif ( $title_lc !== '' && strpos( $title_lc, $search_lc ) !== false ) {
            $score += 60;
        }

        if ( $address_lc !== '' && strpos( $address_lc, $search_lc ) === 0 ) {
            $score += 45;
        } elseif ( $address_lc !== '' && strpos( $address_lc, $search_lc ) !== false ) {
            $score += 30;
        }

        $provider = get_post_meta( $post->ID, '_db_provider', true );
        if ( $provider ) {
            $provider_lc = $this->lowercase( $provider );
            if ( strpos( $provider_lc, $search_lc ) !== false ) {
                $score += 10;
            }
        }

        if ( 'charging_location' === $post->post_type ) {
            $score += 12;
        } elseif ( 'rv_spot' === $post->post_type ) {
            $score += 6;
        }

        if ( $this->is_post_recommended( $post ) ) {
            $score += 20;
        }

        $modified = strtotime( $post->post_modified_gmt );
        if ( $modified ) {
            $age_days = ( time() - $modified ) / DAY_IN_SECONDS;
            $score += max( 0, 15 - min( 15, (int) floor( $age_days / 30 ) ) );
        }

        $title_len = function_exists( 'mb_strlen' ) ? mb_strlen( $title ) : strlen( $title );
        $search_len = function_exists( 'mb_strlen' ) ? mb_strlen( $search ) : strlen( $search );
        $score += max( 0, 20 - min( 20, abs( $title_len - $search_len ) ) );

        return $score;
    }

    private function lowercase( $value ) {
        $value = is_string( $value ) ? $value : '';
        if ( function_exists( 'mb_strtolower' ) ) {
            return mb_strtolower( $value, 'UTF-8' );
        }
        return strtolower( $value );
    }

    private function normalize_coordinate( $value ) {
        if ( is_string( $value ) ) {
            $value = str_replace( ',', '.', trim( $value ) );
        }

        if ( '' === $value || null === $value ) {
            return null;
        }

        return is_numeric( $value ) ? (float) $value : null;
    }

    private function get_address_for_post( $post ) {
        if ( ! ( $post instanceof \WP_Post ) ) {
            return '';
        }

        switch ( $post->post_type ) {
            case 'charging_location':
                $address = get_post_meta( $post->ID, '_db_address', true );
                break;
            case 'rv_spot':
                $address = get_post_meta( $post->ID, '_rv_address', true );
                break;
            case 'poi':
                $address = get_post_meta( $post->ID, '_poi_address', true );
                break;
            default:
                $address = '';
        }

        return is_string( $address ) ? trim( $address ) : '';
    }

    private function get_type_label( $post_type ) {
        switch ( $post_type ) {
            case 'charging_location':
                return __( 'Nabíjecí stanice', 'dobity-baterky' );
            case 'rv_spot':
                return __( 'Stání pro karavany', 'dobity-baterky' );
            case 'poi':
                return __( 'Místo zájmu', 'dobity-baterky' );
            default:
                return ucfirst( str_replace( '_', ' ', (string) $post_type ) );
        }
    }

    private function is_post_recommended( $post ) {
        if ( ! ( $post instanceof \WP_Post ) ) {
            return false;
        }

        $meta_keys = array( '_db_recommended', '_poi_recommended', '_rv_recommended' );
        foreach ( $meta_keys as $key ) {
            $value = get_post_meta( $post->ID, $key, true );
            if ( in_array( $value, array( '1', 1, true, 'true', 'yes' ), true ) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * Handle adding new POI
     */
    public function handle_add_poi( $request ) {
        $params = $request->get_json_params();
        $title = sanitize_text_field($params['title'] ?? '');
        $desc = sanitize_textarea_field($params['description'] ?? '');
        $lat = floatval($params['lat'] ?? 0);
        $lng = floatval($params['lng'] ?? 0);
        $poi_type = sanitize_text_field($params['poi_type'] ?? '');
        $address = sanitize_text_field($params['address'] ?? '');
        
        // Nová pole z Google Places API
        $place_id = sanitize_text_field($params['place_id'] ?? '');
        $types = is_array($params['types'] ?? []) ? array_map('sanitize_text_field', $params['types']) : [];
        $phone = sanitize_text_field($params['phone'] ?? '');
        $website = esc_url_raw($params['website'] ?? '');
        $rating = floatval($params['rating'] ?? 0);
        $user_rating_count = intval($params['user_rating_count'] ?? 0);
        $price_level = sanitize_text_field($params['price_level'] ?? '');
        $opening_hours = sanitize_textarea_field($params['opening_hours'] ?? '');
        $photos = sanitize_textarea_field($params['photos'] ?? '');
        $icon = esc_url_raw($params['icon'] ?? '');
        $icon_background_color = sanitize_hex_color($params['icon_background_color'] ?? '');
        $icon_mask_uri = esc_url_raw($params['icon_mask_uri'] ?? '');
        
        // Nová rozšířená data
        $url = esc_url_raw($params['url'] ?? '');
        $vicinity = sanitize_text_field($params['vicinity'] ?? '');
        $utc_offset = sanitize_text_field($params['utc_offset'] ?? '');
        $business_status = sanitize_text_field($params['business_status'] ?? '');
        $reviews = sanitize_textarea_field($params['reviews'] ?? '');
        
        // Restaurační služby
        $delivery = boolval($params['delivery'] ?? false);
        $dine_in = boolval($params['dine_in'] ?? false);
        $takeout = boolval($params['takeout'] ?? false);
        $serves_beer = boolval($params['serves_beer'] ?? false);
        $serves_wine = boolval($params['serves_wine'] ?? false);
        $serves_breakfast = boolval($params['serves_breakfast'] ?? false);
        $serves_lunch = boolval($params['serves_lunch'] ?? false);
        $serves_dinner = boolval($params['serves_dinner'] ?? false);
        
        // Přístupnost
        $wheelchair_accessible_entrance = boolval($params['wheelchair_accessible_entrance'] ?? false);
        $curbside_pickup = boolval($params['curbside_pickup'] ?? false);
        $reservable = boolval($params['reservable'] ?? false);
        
        // Otevírací hodiny
        $current_opening_hours = sanitize_textarea_field($params['current_opening_hours'] ?? '');
        $secondary_opening_hours = sanitize_textarea_field($params['secondary_opening_hours'] ?? '');
        $special_days = sanitize_textarea_field($params['special_days'] ?? '');
        
        // Raw data
        $raw_data = sanitize_textarea_field($params['raw_data'] ?? '');
        
        if (!$title || !$lat || !$lng) {
            return new \WP_Error('missing_data', 'Chybí povinná data', array('status' => 400));
        }
        
        $post_id = wp_insert_post(array(
            'post_type' => 'poi',
            'post_title' => $title,
            'post_content' => $desc,
            'post_status' => 'publish',
        ));
        
        if (is_wp_error($post_id)) {
            return $post_id;
        }
        
        // Základní metadata
        update_post_meta($post_id, '_poi_lat', $lat);
        update_post_meta($post_id, '_poi_lng', $lng);
        update_post_meta($post_id, '_poi_address', $address);
        
        // Google Places API metadata
        if (isset($params['phone'])) {
            update_post_meta($post_id, '_poi_phone', sanitize_text_field($params['phone']));
        }
        if (isset($params['website'])) {
            update_post_meta($post_id, '_poi_website', esc_url_raw($params['website']));
        }
        if (isset($params['rating'])) {
            update_post_meta($post_id, '_poi_rating', floatval($params['rating']));
        }
        if (isset($params['user_rating_count'])) {
            update_post_meta($post_id, '_poi_user_rating_count', intval($params['user_rating_count']));
        }
        if (isset($params['price_level'])) {
            update_post_meta($post_id, '_poi_price_level', sanitize_text_field($params['price_level']));
        }
        if (isset($params['opening_hours'])) {
            update_post_meta($post_id, '_poi_opening_hours', sanitize_textarea_field($params['opening_hours']));
        }
        if (isset($params['photos'])) {
            update_post_meta($post_id, '_poi_photos', sanitize_textarea_field($params['photos']));
        }
        if (isset($params['icon'])) {
            update_post_meta($post_id, '_poi_icon', esc_url_raw($params['icon']));
        }
        if (isset($params['icon_background_color'])) {
            update_post_meta($post_id, '_poi_icon_background_color', sanitize_hex_color($params['icon_background_color']));
        }
        if (isset($params['icon_mask_uri'])) {
            update_post_meta($post_id, '_poi_icon_mask_uri', esc_url_raw($params['icon_mask_uri']));
        }
        
        // Univerzální pole pro všechny typy POI
        if (isset($params['url'])) {
            update_post_meta($post_id, '_poi_url', esc_url_raw($params['url']));
        }
        if (isset($params['vicinity'])) {
            update_post_meta($post_id, '_poi_vicinity', sanitize_text_field($params['vicinity']));
        }
        if (isset($params['utc_offset'])) {
            update_post_meta($post_id, '_poi_utc_offset', sanitize_text_field($params['utc_offset']));
        }
        if (isset($params['business_status'])) {
            update_post_meta($post_id, '_poi_business_status', sanitize_text_field($params['business_status']));
        }
        if (isset($params['reviews'])) {
            update_post_meta($post_id, '_poi_reviews', sanitize_textarea_field($params['reviews']));
        }
        if (isset($params['types'])) {
            // Uložit typy jako array
            if (is_array($params['types'])) {
                update_post_meta($post_id, '_poi_types', $params['types']);
            } else {
                // Pokud je string, rozdělit podle čárky
                $types_array = array_map('trim', explode(',', $params['types']));
                update_post_meta($post_id, '_poi_types', $types_array);
            }
        }
        
        // Restaurační služby
        if (isset($params['delivery'])) {
            update_post_meta($post_id, '_poi_delivery', $params['delivery'] ? '1' : '0');
        }
        if (isset($params['dine_in'])) {
            update_post_meta($post_id, '_poi_dine_in', $params['dine_in'] ? '1' : '0');
        }
        if (isset($params['takeout'])) {
            update_post_meta($post_id, '_poi_takeout', $params['takeout'] ? '1' : '0');
        }
        if (isset($params['serves_beer'])) {
            update_post_meta($post_id, '_poi_serves_beer', $params['serves_beer'] ? '1' : '0');
        }
        if (isset($params['serves_wine'])) {
            update_post_meta($post_id, '_poi_serves_wine', $params['serves_wine'] ? '1' : '0');
        }
        if (isset($params['serves_breakfast'])) {
            update_post_meta($post_id, '_poi_serves_breakfast', $params['serves_breakfast'] ? '1' : '0');
        }
        if (isset($params['serves_lunch'])) {
            update_post_meta($post_id, '_poi_serves_lunch', $params['serves_lunch'] ? '1' : '0');
        }
        if (isset($params['serves_dinner'])) {
            update_post_meta($post_id, '_poi_serves_dinner', $params['serves_dinner'] ? '1' : '0');
        }
        
        // Přístupnost
        if (isset($params['wheelchair_accessible_entrance'])) {
            update_post_meta($post_id, '_poi_wheelchair_accessible_entrance', $params['wheelchair_accessible_entrance'] ? '1' : '0');
        }
        if (isset($params['curbside_pickup'])) {
            update_post_meta($post_id, '_poi_curbside_pickup', $params['curbside_pickup'] ? '1' : '0');
        }
        if (isset($params['reservable'])) {
            update_post_meta($post_id, '_poi_reservable', $params['reservable'] ? '1' : '0');
        }
        
        // Otevírací hodiny
        if ($current_opening_hours) update_post_meta($post_id, '_poi_current_opening_hours', $current_opening_hours);
        if ($secondary_opening_hours) update_post_meta($post_id, '_poi_secondary_opening_hours', $secondary_opening_hours);
        if ($special_days) update_post_meta($post_id, '_poi_special_days', $special_days);
        
        // Raw data
        if ($raw_data) update_post_meta($post_id, '_poi_raw_data', $raw_data);
        
        // Uložení typů jako taxonomy terms
        if (!empty($types)) {
            // Překlad typů do češtiny
            $type_translations = array(
                'restaurant' => 'restaurace',
                'cafe' => 'kavárna',
                'bar' => 'bar',
                'bakery' => 'pekařství',
                'store' => 'obchod',
                'shopping_mall' => 'nákupní centrum',
                'supermarket' => 'supermarket',
                'museum' => 'muzeum',
                'art_gallery' => 'galerie',
                'theater' => 'divadlo',
                'cinema' => 'kino',
                'gym' => 'posilovna',
                'stadium' => 'stadion',
                'park' => 'park',
                'airport' => 'letiště',
                'train_station' => 'nádraží',
                'bus_station' => 'autobusové nádraží',
                'hospital' => 'nemocnice',
                'pharmacy' => 'lékárna',
                'doctor' => 'lékař',
                'hotel' => 'hotel',
                'lodging' => 'ubytování',
                'amusement_park' => 'zábavní park',
                'aquarium' => 'akvárium',
                'zoo' => 'zoo'
            );
            
            $translated_types = array();
            foreach ($types as $type) {
                $display_type = isset($type_translations[$type]) ? $type_translations[$type] : $type;
                $translated_types[] = $display_type;
                
                // Pokus o nalezení existujícího termu
                $existing_term = get_term_by('name', $display_type, 'poi_type');
                if (!$existing_term) {
                    // Vytvoření nového termu
                    $term_result = wp_insert_term($display_type, 'poi_type');
                    if (!is_wp_error($term_result)) {
                        $term_id = is_array($term_result) ? $term_result['term_id'] : $term_result;
                        // Uložit Google typ jako meta
                        update_term_meta($term_id, 'google_type', $type);
                    }
                }
            }
            // Přiřazení přeložených typů k postu
            wp_set_post_terms($post_id, $translated_types, 'poi_type');
        }
        
        // Pokud není specifikován typ, použij první z Google Places
        if (!$poi_type && !empty($types)) {
            $poi_type = $types[0];
        }
        
        if ($poi_type) {
            // Přeložit poi_type do češtiny
            $type_translations = array(
                'restaurant' => 'restaurace',
                'cafe' => 'kavárna',
                'bar' => 'bar',
                'bakery' => 'pekařství',
                'store' => 'obchod',
                'shopping_mall' => 'nákupní centrum',
                'supermarket' => 'supermarket',
                'museum' => 'muzeum',
                'art_gallery' => 'galerie',
                'theater' => 'divadlo',
                'cinema' => 'kino',
                'gym' => 'posilovna',
                'stadium' => 'stadion',
                'park' => 'park',
                'airport' => 'letiště',
                'train_station' => 'nádraží',
                'bus_station' => 'autobusové nádraží',
                'hospital' => 'nemocnice',
                'pharmacy' => 'lékárna',
                'doctor' => 'lékař',
                'hotel' => 'hotel',
                'lodging' => 'ubytování',
                'amusement_park' => 'zábavní park',
                'aquarium' => 'akvárium',
                'zoo' => 'zoo'
            );
            
            $display_poi_type = isset($type_translations[$poi_type]) ? $type_translations[$poi_type] : $poi_type;
            
            // Vytvořit term, pokud neexistuje
            $existing_term = get_term_by('name', $display_poi_type, 'poi_type');
            if (!$existing_term) {
                $term_result = wp_insert_term($display_poi_type, 'poi_type');
                if (!is_wp_error($term_result)) {
                    $term_id = is_array($term_result) ? $term_result['term_id'] : $term_result;
                    // Uložit Google typ jako meta
                    update_term_meta($term_id, 'google_type', $poi_type);
                }
            }
            
            wp_set_post_terms($post_id, array($display_poi_type), 'poi_type');
        }
        
        return array(
            'success' => true, 
            'id' => $post_id,
            'message' => 'POI bylo úspěšně vytvořeno'
        );
    }

    public function handle_google_places( $request ) {
        $lat = $request->get_param('lat');
        $lng = $request->get_param('lng');
        $radius = $request->get_param('radius') ?: 100;
        if (!$lat || !$lng) {
            return new \WP_Error('missing_coords', 'Chybí souřadnice', array('status' => 400));
        }
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 503));
        }
        
        // Kontrola kvóty
        $quota = new \DB\Jobs\Google_Quota_Manager();
        $quota_check = $quota->reserve_quota(1);
        if (is_wp_error($quota_check)) {
            return $quota_check;
        }
        
        $url = "https://maps.googleapis.com/maps/api/place/nearbysearch/json?location={$lat},{$lng}&radius={$radius}&key={$api_key}&language=cs";
        $response = wp_remote_get($url);
        if (is_wp_error($response)) {
            return new \WP_Error('google_api_error', 'Chyba při volání Google API', array('status' => 500));
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        return rest_ensure_response($data);
    }

    /**
     * Handle Google Places Search (Text Search + Nearby Search)
     */
    public function handle_google_places_search( $request ) {
        $params = $request->get_json_params();
        $input = sanitize_text_field($params['input'] ?? '');
        $lat = floatval($params['lat'] ?? 0);
        $lng = floatval($params['lng'] ?? 0);
        $radius = intval($params['radius'] ?? 500);
        $max_results = intval($params['maxResults'] ?? 5);
        
        if (!$input) {
            return new \WP_Error('missing_input', 'Chybí vstupní text', array('status' => 400));
        }
        
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 503));
        }
        
        // Kontrola kvóty
        $quota = new \DB\Jobs\Google_Quota_Manager();
        $places = array();
        $quota_used = 0;
        
        // Pokud máme GPS souřadnice, použijeme Nearby Search
        if ($lat && $lng) {
            // Rezervovat kvótu před voláním
            $quota_check = $quota->reserve_quota(1);
            if (is_wp_error($quota_check)) {
                return $quota_check;
            }
            $quota_used++;
            
            $nearby_url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
            $nearby_args = array(
                'location' => $lat . ',' . $lng,
                'radius' => $radius,
                'type' => 'establishment',
                'key' => $api_key,
                'language' => 'cs'
            );
            
            $nearby_url = add_query_arg($nearby_args, $nearby_url);
            $nearby_response = wp_remote_get($nearby_url, array('timeout' => 30));
            
            if (!is_wp_error($nearby_response)) {
                $nearby_data = json_decode(wp_remote_retrieve_body($nearby_response), true);
                if ($nearby_data['status'] === 'OK' && isset($nearby_data['results'])) {
                    foreach ($nearby_data['results'] as $result) {
                        $places[] = array(
                            'placeId' => $result['place_id'],
                            'displayName' => array('text' => $result['name']),
                            'formattedAddress' => $result['vicinity'] ?? $result['formatted_address'] ?? '',
                            'types' => $result['types'] ?? array(),
                            'location' => array(
                                'latitude' => $result['geometry']['location']['lat'],
                                'longitude' => $result['geometry']['location']['lng']
                            )
                        );
                    }
                }
            }
        }
        
        // Vytvoříme různé varianty názvu pro hledání
        $search_variants = array();
        
        // 1. Původní název
        $search_variants[] = $input;
        
        // 2. Název s vykřičníkem (pokud chybí)
        if (!str_contains($input, '!')) {
            $search_variants[] = $input . '!';
        }
        
        // 3. Název s lokací
        $search_variants[] = $input . ' Praha';
        $search_variants[] = $input . ' Smíchov';
        
        // 4. Název s vykřičníkem a lokací
        if (!str_contains($input, '!')) {
            $search_variants[] = $input . '! Praha';
            $search_variants[] = $input . '! Smíchov';
        }
        
        // 5. Opravený název (první písmeno velké)
        $corrected = ucfirst(strtolower($input));
        if ($corrected !== $input) {
            $search_variants[] = $corrected;
            if (!str_contains($corrected, '!')) {
                $search_variants[] = $corrected . '!';
            }
        }
        
        // 6. Částečný název (bez diakritiky a speciálních znaků)
        $partial = preg_replace('/[^a-zA-Z0-9\s]/', '', $input);
        if ($partial !== $input && strlen($partial) > 2) {
            $search_variants[] = $partial;
        }
        
        // Odstraníme duplicity
        $search_variants = array_unique($search_variants);
        
        // Pro každou variantu zkusíme Text Search
        foreach ($search_variants as $variant) {
            // Rezervovat kvótu před každým voláním
            $quota_check = $quota->reserve_quota(1);
            if (is_wp_error($quota_check)) {
                // Pokud došla kvóta, vrátit co máme
                break;
            }
            $quota_used++;
            
            $text_url = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
            $text_args = array(
                'query' => $variant,
                'key' => $api_key,
                'language' => 'cs'
            );
            
            $text_url = add_query_arg($text_args, $text_url);
            $text_response = wp_remote_get($text_url, array('timeout' => 30));
            
            if (!is_wp_error($text_response)) {
                $text_data = json_decode(wp_remote_retrieve_body($text_response), true);
                if ($text_data['status'] === 'OK' && isset($text_data['results'])) {
                    foreach ($text_data['results'] as $result) {
                        // Kontrola, zda už není v seznamu
                        $exists = false;
                        foreach ($places as $existing) {
                            if ($existing['placeId'] === $result['place_id']) {
                                $exists = true;
                                break;
                            }
                        }
                        
                        if (!$exists) {
                            $places[] = array(
                                'placeId' => $result['place_id'],
                                'displayName' => array('text' => $result['name']),
                                'formattedAddress' => $result['formatted_address'] ?? '',
                                'types' => $result['types'] ?? array(),
                                'location' => array(
                                    'latitude' => $result['geometry']['location']['lat'],
                                    'longitude' => $result['geometry']['location']['lng']
                                )
                            );
                        }
                    }
                }
            }
            
            // Pokud už máme dostatek výsledků, přestaneme hledat
            if (count($places) >= $max_results) {
                break;
            }
        }
        
        // Omezíme počet výsledků
        $places = array_slice($places, 0, $max_results);
        
        return rest_ensure_response(array('places' => $places));
    }

    /**
     * Handle Google Places Nearby Search (New API)
     */
    public function handle_google_places_nearby( $request ) {
        $params = $request->get_json_params();
        $lat = floatval($params['lat'] ?? 0);
        $lng = floatval($params['lng'] ?? 0);
        $radius = intval($params['radius'] ?? 500);
        $max_results = intval($params['maxResults'] ?? 5);
        
        if (!$lat || !$lng) {
            return new \WP_Error('missing_coords', 'Chybí souřadnice', array('status' => 400));
        }
        
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 503));
        }
        
        // Kontrola kvóty
        $quota = new \DB\Jobs\Google_Quota_Manager();
        $quota_check = $quota->reserve_quota(1);
        if (is_wp_error($quota_check)) {
            return $quota_check;
        }
        
        // Použijeme starší verzi Google Places API (Nearby Search)
        $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json';
        $args = array(
            'location' => $lat . ',' . $lng,
            'radius' => $radius,
            'type' => 'establishment',
            'key' => $api_key,
            'language' => 'cs'
        );
        
        $url = add_query_arg($args, $url);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('google_api_error', 'Chyba při volání Google API: ' . $response->get_error_message(), array('status' => 500));
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        // Log pro debugging
        error_log('Google Places API Response: ' . $body);
        
        if ($status_code !== 200) {
            return new \WP_Error('google_api_error', 'Google API chyba: HTTP ' . $status_code, array('status' => 500));
        }
        
        if (isset($data['error_message'])) {
            return new \WP_Error('google_api_error', 'Google API chyba: ' . $data['error_message'], array('status' => 500));
        }
        
        if ($data['status'] !== 'OK' && $data['status'] !== 'ZERO_RESULTS') {
            return new \WP_Error('google_api_error', 'Google API chyba: ' . $data['status'], array('status' => 500));
        }
        
        // Převedeme starší formát na nový formát pro frontend
        $places = array();
        if (isset($data['results']) && is_array($data['results'])) {
            foreach ($data['results'] as $result) {
                $places[] = array(
                    'placeId' => $result['place_id'],
                    'displayName' => array('text' => $result['name']),
                    'formattedAddress' => $result['vicinity'] ?? $result['formatted_address'] ?? '',
                    'types' => $result['types'] ?? array(),
                    'location' => array(
                        'latitude' => $result['geometry']['location']['lat'],
                        'longitude' => $result['geometry']['location']['lng']
                    )
                );
            }
        }
        
        return rest_ensure_response(array('places' => $places));
    }
    
    /**
     * Handle Google Place Details (New API)
     */
    public function handle_google_place_details( $request ) {
        $params = $request->get_json_params();
        $place_id = sanitize_text_field($params['placeId'] ?? '');

        if (!$place_id) {
            return new \WP_Error('missing_place_id', 'Chybí Place ID', array('status' => 400));
        }
        $place_data = $this->fetch_google_place_details_raw( $place_id );
        if ( is_wp_error( $place_data ) ) {
            return $place_data;
        }

        return rest_ensure_response( $place_data );
    }

    private function fetch_google_place_details_raw( $place_id ) {
        $response = $this->enrichment_service->request_place_details( $place_id, array(
            'endpoint' => 'places_details',
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( is_array( $response ) && isset( $response['enriched'] ) && $response['enriched'] === false ) {
            // Preserve HTTP 429 when quota is exceeded
            if ( isset( $response['reason'] ) && $response['reason'] === 'quota_exceeded' ) {
                return new \WP_Error(
                    'places_quota_exceeded',
                    'Denní limit Google Places byl vyčerpán.',
                    array( 'status' => 429 )
                );
            }
            return $response;
        }

        return $response['data'] ?? $response;
    }

    private function normalize_google_payload( $place_data ) {
        $photos = array();
        if ( ! empty( $place_data['photos'] ) && is_array( $place_data['photos'] ) ) {
            $api_key = get_option('db_google_api_key');
            $max = 6; // vyrobíme až 6 fotek, FE si vezme první 3
            $count = 0;
            foreach ( $place_data['photos'] as $photo ) {
                $ref = $photo['photoReference'] ?? ($photo['photo_reference'] ?? '');
                $item = array(
                    'provider' => 'google_places',
                    'photo_reference' => $ref,
                    'width' => $photo['width'] ?? 0,
                    'height' => $photo['height'] ?? 0,
                    'html_attributions' => $photo['htmlAttributions'] ?? ($photo['html_attributions'] ?? array())
                );
                if ( $ref !== '' && ! empty( $api_key ) ) {
                    $item['url'] = add_query_arg( array(
                        'maxwidth' => min( 1200, intval($photo['width'] ?? 1200) ?: 1200 ),
                        'photo_reference' => $ref,
                        'key' => $api_key,
                    ), 'https://maps.googleapis.com/maps/api/place/photo' );
                }
                $photos[] = $item;
                $count++;
                if ( $count >= $max ) break;
            }
        }

        // Převod opening hours: sjednotit na weekdayDescriptions
        $opening = $place_data['regularOpeningHours'] ?? null;
        if (!$opening && isset($place_data['opening_hours'])) {
            $oh = $place_data['opening_hours'];
            if (is_array($oh) && !empty($oh['weekday_text']) && is_array($oh['weekday_text'])) {
                $opening = array('weekdayDescriptions' => $oh['weekday_text']);
            }
        }
        // Podpora pro Places v1: currentOpeningHours.weekdayDescriptions
        if (!$opening && isset($place_data['currentOpeningHours']) && is_array($place_data['currentOpeningHours'])) {
            $coh = $place_data['currentOpeningHours'];
            if (!empty($coh['weekdayDescriptions']) && is_array($coh['weekdayDescriptions'])) {
                $opening = array('weekdayDescriptions' => $coh['weekdayDescriptions']);
            }
        }
        // Pokud je struktura k dispozici, ale používá weekday_text, doplň i weekdayDescriptions
        if (is_array($opening) && empty($opening['weekdayDescriptions']) && !empty($opening['weekday_text']) && is_array($opening['weekday_text'])) {
            $opening['weekdayDescriptions'] = $opening['weekday_text'];
        }

        $payload = array(
            'name' => $place_data['displayName']['text'] ?? '',
            'address' => $place_data['formattedAddress'] ?? '',
            'phone' => $place_data['nationalPhoneNumber'] ?? '',
            'internationalPhone' => $place_data['internationalPhoneNumber'] ?? '',
            'website' => $place_data['websiteUri'] ?? '',
            'rating' => isset($place_data['rating']) ? floatval($place_data['rating']) : null,
            'userRatingCount' => isset($place_data['userRatingCount']) ? intval($place_data['userRatingCount']) : null,
            'priceLevel' => $place_data['priceLevel'] ?? '',
            'openingHours' => $opening,
            'photos' => $photos,
            'mapUrl' => $place_data['url'] ?? '',
            'sourceUrl' => $place_data['url'] ?? '',
            'socialLinks' => array(),
            'raw' => $place_data,
        );

        // Vytvořit přímou URL pro první fotku, pokud je photo_reference k dispozici a máme API key.
        $api_key = get_option('db_google_api_key');
        if (!empty($api_key) && !empty($photos) && is_array($photos)) {
            $ref = $photos[0]['photo_reference'] ?? '';
            if ($ref !== '') {
                // Uložit i přímou URL pro FE hero image bez nutnosti znát klíč na FE
                $payload['photoUrl'] = add_query_arg(array(
                    'maxwidth' => 1200,
                    'photo_reference' => $ref,
                    'key' => $api_key,
                ), 'https://maps.googleapis.com/maps/api/place/photo');
            }
        }

        return $payload;
    }

    private function request_tripadvisor_details( $location_id ) {
        $api_key = get_option('db_tripadvisor_api_key');
        if (!$api_key) {
            return new \WP_Error('no_api_key', 'Tripadvisor API klíč není nastaven', array('status' => 500));
        }

        $url = sprintf('https://api.content.tripadvisor.com/api/v1/location/%s/details', rawurlencode($location_id));
        $url = add_query_arg(array(
            'key' => $api_key,
            'language' => 'cs',
            'currency' => 'CZK'
        ), $url);

        $response = wp_remote_get($url, array('timeout' => 30));
        if (is_wp_error($response)) {
            return new \WP_Error('tripadvisor_api_error', 'Chyba při volání Tripadvisor API: ' . $response->get_error_message(), array('status' => 500));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);

        if ($status_code !== 200) {
            return new \WP_Error('tripadvisor_api_error', 'Tripadvisor API chyba: HTTP ' . $status_code, array('status' => 500));
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            return new \WP_Error('tripadvisor_invalid', 'Neplatná odpověď Tripadvisor API', array('status' => 500));
        }

        return $data;
    }

    private function normalize_tripadvisor_payload( $data ) {
        $address = '';
        if ( ! empty( $data['address_obj']['address_string'] ) ) {
            $address = $data['address_obj']['address_string'];
        } elseif ( ! empty( $data['address'] ) ) {
            $address = $data['address'];
        }

        $photos = array();
        if ( ! empty( $data['photo']['images'] ) && is_array( $data['photo']['images'] ) ) {
            foreach ( $data['photo']['images'] as $size => $img ) {
                if ( ! empty( $img['url'] ) ) {
                    $photos[] = array(
                        'provider' => 'tripadvisor',
                        'url' => $img['url'],
                        'width' => $img['width'] ?? 0,
                        'height' => $img['height'] ?? 0,
                        'caption' => $data['photo']['caption'] ?? '',
                    );
                    break; // pouze jednu reprezentativní velikost
                }
            }
        }
        if ( empty( $photos ) && ! empty( $data['additional_photos'] ) && is_array( $data['additional_photos'] ) ) {
            foreach ( $data['additional_photos'] as $photo ) {
                if ( ! empty( $photo['images']['large']['url'] ) ) {
                    $photos[] = array(
                        'provider' => 'tripadvisor',
                        'url' => $photo['images']['large']['url'],
                        'width' => $photo['images']['large']['width'] ?? 0,
                        'height' => $photo['images']['large']['height'] ?? 0,
                        'caption' => $photo['caption'] ?? '',
                    );
                }
            }
        }

        $social = array();
        if ( ! empty( $data['social_media'] ) && is_array( $data['social_media'] ) ) {
            foreach ( $data['social_media'] as $social_item ) {
                if ( ! empty( $social_item['type'] ) && ! empty( $social_item['url'] ) ) {
                    $social[ strtolower( $social_item['type'] ) ] = $social_item['url'];
                }
            }
        }

        if ( ! empty( $data['email'] ) ) {
            $social['email'] = $data['email'];
        }

        return array(
            'name' => $data['name'] ?? '',
            'address' => $address,
            'phone' => $data['phone'] ?? '',
            'internationalPhone' => $data['phone'] ?? '',
            'website' => $data['website'] ?? '',
            'rating' => isset($data['rating']) ? floatval($data['rating']) : null,
            'userRatingCount' => isset($data['num_reviews']) ? intval($data['num_reviews']) : null,
            'priceLevel' => $data['price_level'] ?? ($data['price'] ?? ''),
            'openingHours' => $data['hours'] ?? null,
            'photos' => $photos,
            'mapUrl' => $data['web_url'] ?? '',
            'sourceUrl' => $data['website'] ?? ($data['web_url'] ?? ''),
            'socialLinks' => $social,
            'raw' => $data,
        );
    }

    private function get_service_preference( $post_id ) {
        $preferred = get_post_meta( $post_id, '_poi_primary_external_source', true );
        $preferred = $preferred ?: 'google_places';

        $order = array('google_places', 'tripadvisor');
        if ($preferred === 'tripadvisor') {
            $order = array('tripadvisor', 'google_places');
        }

        $available = array();
        foreach ( $order as $service ) {
            if ( $service === 'google_places' ) {
                if ( $this->get_google_place_id( $post_id ) ) {
                    $available[] = $service;
                }
            } elseif ( $service === 'tripadvisor' ) {
                if ( $this->get_tripadvisor_location_id( $post_id ) ) {
                    $available[] = $service;
                }
            }
        }

        return $available;
    }

    private function get_google_place_id( $post_id ) {
        $google_place_id = get_post_meta( $post_id, '_poi_google_place_id', true );
        if ( $google_place_id ) {
            return $google_place_id;
        }

        return get_post_meta( $post_id, '_poi_place_id', true );
    }

    private function get_tripadvisor_location_id( $post_id ) {
        return get_post_meta( $post_id, '_poi_tripadvisor_location_id', true );
    }

    private function maybe_fetch_google_place_data( $post_id, $force = false ) {
        $place_id = $this->get_google_place_id( $post_id );
        if ( ! $place_id ) {
            return null;
        }

        $cache_key = '_poi_google_cache';
        $cache_exp_key = '_poi_google_cache_expires';
        $cached_payload = get_post_meta( $post_id, $cache_key, true );
        $cached_expires = intval( get_post_meta( $post_id, $cache_exp_key, true ) );
        $last_enriched = get_post_meta( $post_id, '_poi_last_enriched_at', true );
        $now = current_time( 'timestamp' );

        if ( $cached_payload && $cached_expires > $now ) {
            $decoded = json_decode( $cached_payload, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        $recent_window = $this->enrichment_service->get_recent_days() * DAY_IN_SECONDS;
        if ( ! $force && $last_enriched ) {
            $last_ts = strtotime( $last_enriched );
            if ( $last_ts && ( $now - $last_ts ) < $recent_window ) {
                if ( $cached_payload ) {
                    $decoded = json_decode( $cached_payload, true );
                    if ( is_array( $decoded ) ) {
                        return $decoded;
                    }
                }

                return array(
                    'enriched' => false,
                    'reason'   => 'recently_enriched',
                );
            }
        }

        $response = $this->enrichment_service->request_place_details( $place_id, array(
            'post_id'  => $post_id,
            'force'    => $force,
            'endpoint' => 'places_details',
        ) );

        if ( is_wp_error( $response ) ) {
            return $response;
        }

        if ( is_array( $response ) && empty( $response['enriched'] ) ) {
            // Preserve HTTP 429 when quota is exceeded
            if ( isset( $response['reason'] ) && $response['reason'] === 'quota_exceeded' ) {
                return new \WP_Error(
                    'places_quota_exceeded',
                    'Denní limit Google Places byl vyčerpán.',
                    array( 'status' => 429 )
                );
            }
            return $response;
        }

        $raw = $response['data'] ?? $response;

        $normalized = $this->normalize_google_payload( $raw );
        $normalized['fetchedAt'] = gmdate( 'c', current_time( 'timestamp', true ) );

        update_post_meta( $post_id, $cache_key, wp_json_encode( $normalized ) );
        update_post_meta( $post_id, $cache_exp_key, $now + self::GOOGLE_CACHE_TTL );
        update_post_meta( $post_id, '_poi_last_enriched_at', current_time( 'mysql' ) );
        update_post_meta( $post_id, '_poi_enriched', '1' );

        $this->persist_enriched_fields( $post_id, $normalized );

        return $normalized;
    }

    private function maybe_fetch_tripadvisor_data( $post_id ) {
        $location_id = $this->get_tripadvisor_location_id( $post_id );
        if ( ! $location_id ) {
            return null;
        }

        $cache_key = '_poi_tripadvisor_cache';
        $cache_exp_key = '_poi_tripadvisor_cache_expires';
        $cached_payload = get_post_meta( $post_id, $cache_key, true );
        $cached_expires = intval( get_post_meta( $post_id, $cache_exp_key, true ) );
        $now = current_time( 'timestamp' );

        if ( $cached_payload && $cached_expires > $now ) {
            $decoded = json_decode( $cached_payload, true );
            if ( is_array( $decoded ) ) {
                return $decoded;
            }
        }

        if ( ! $this->consume_quota( 'tripadvisor' ) ) {
            return new \WP_Error( 'quota_exceeded', 'Denní limit Tripadvisor API byl vyčerpán.', array( 'status' => 429 ) );
        }

        $raw = $this->request_tripadvisor_details( $location_id );
        if ( is_wp_error( $raw ) ) {
            return $raw;
        }

        $normalized = $this->normalize_tripadvisor_payload( $raw );
        $normalized['fetchedAt'] = gmdate( 'c', current_time( 'timestamp', true ) );

        update_post_meta( $post_id, $cache_key, wp_json_encode( $normalized ) );
        update_post_meta( $post_id, $cache_exp_key, $now + self::TRIPADVISOR_CACHE_TTL );

        $this->persist_enriched_fields( $post_id, $normalized );

        return $normalized;
    }

    private function consume_quota( $service ) {
        $limit = $this->get_service_daily_limit( $service );
        if ( $limit === 0 ) {
            return true;
        }

        $usage = get_option( self::USAGE_OPTION, array() );
        if ( ! is_array( $usage ) ) {
            $usage = array();
        }

        $now = current_time( 'timestamp' );
        if ( empty( $usage[ $service ] ) || ! is_array( $usage[ $service ] ) ) {
            $usage[ $service ] = array( 'count' => 0, 'day_start' => $now );
        }

        $day_start = intval( $usage[ $service ]['day_start'] );
        if ( $now - $day_start >= DAY_IN_SECONDS ) {
            $usage[ $service ] = array( 'count' => 0, 'day_start' => $now );
        }

        if ( $usage[ $service ]['count'] >= $limit ) {
            update_option( self::USAGE_OPTION, $usage, false );
            return false;
        }

        $usage[ $service ]['count']++;
        update_option( self::USAGE_OPTION, $usage, false );
        return true;
    }

    private function get_service_daily_limit( $service ) {
        $defaults = array(
            'google_places' => self::GOOGLE_DAILY_LIMIT,
            'tripadvisor' => self::TRIPADVISOR_DAILY_LIMIT,
        );

        $default = isset( $defaults[ $service ] ) ? $defaults[ $service ] : 0;
        return (int) apply_filters( 'db_poi_service_daily_limit', $default, $service );
    }

    public function handle_poi_external( $request ) {
        $post_id = intval( $request['id'] );
        if ( ! $post_id ) {
            return new \WP_Error( 'missing_id', 'Chybí ID příspěvku', array( 'status' => 400 ) );
        }

        $post = get_post( $post_id );
        if ( ! $post || $post->post_type !== 'poi' ) {
            return new \WP_Error( 'invalid_post', 'Příspěvek není typu POI', array( 'status' => 404 ) );
        }

        $services = $this->get_service_preference( $post_id );
        $errors = array();
        $payload = null;

        // If neither Google nor Tripadvisor ID is available, attempt on-demand discovery
        if (empty($services)) {
            $google_id = $this->get_google_place_id($post_id);
            $ta_id = $this->get_tripadvisor_location_id($post_id);
            // Použít centralizovaný Google_Quota_Manager
            $quota = new \DB\Jobs\Google_Quota_Manager();
            $errors['on_demand'] = array();
            // Try Google discovery first if quota allows and no existing ID
            if (!$google_id && $quota->can_use_google()) {
                // Rezervovat kvótu před voláním
                $quota_check = $quota->reserve_quota(1);
                if (!is_wp_error($quota_check)) {
                    // Reuse POI_Discovery service to find IDs
                    if (class_exists('DB\POI_Discovery')) {
                        $svc = new \DB\POI_Discovery();
                        $res = $svc->discoverForPoi($post_id, true, false, true);
                        if (!empty($res['google_place_id'])) { $google_id = $res['google_place_id']; }
                    }
                }
            }
            // Try Tripadvisor if still missing and quota allows
            $poi_quota = new \DB\Jobs\POI_Quota_Manager();
            if (!$ta_id && $poi_quota->can_use_tripadvisor()) {
                if (class_exists('DB\POI_Discovery')) {
                    $svc = new \DB\POI_Discovery();
                    $res = $svc->discoverForPoi($post_id, true, true, false);
                    $poi_quota->record_tripadvisor(1); // Record quota usage for all API calls
                    if (!empty($res['tripadvisor_location_id'])) { $ta_id = $res['tripadvisor_location_id']; }
                }
            }
            // Recompute available services list
            $services = array();
            if ($google_id) $services[] = 'google_places';
            if ($ta_id) $services[] = 'tripadvisor';
            if (empty($services)) {
                $status = $quota->get_status();
                // Try to prepare review candidates
                $candidates = array();
                if ($quota->can_use_google()) {
                    // find_google_candidates_for_post už rezervuje kvótu interně
                    $candidates = array_merge($candidates, $this->find_google_candidates_for_post($post_id));
                }
                if ($poi_quota->can_use_tripadvisor()) { 
                    $candidates = array_merge($candidates, $this->find_tripadvisor_candidates_for_post($post_id)); 
                    $poi_quota->record_tripadvisor(1); // Record quota usage for candidate search
                }
                if (!empty($candidates)) {
                    update_post_meta($post_id, '_poi_review_candidates', wp_json_encode($candidates));
                    return rest_ensure_response(array(
                        'provider' => null,
                        'data' => null,
                        'status' => 'review_required',
                        'errors' => $errors,
                    ));
                }
                return rest_ensure_response(array(
                    'provider' => null,
                    'data' => null,
                    'status' => 'quota_blocked',
                    'errors' => $errors,
                    'quota' => $status,
                ));
            }
        }

        // Determine if we should try Google Places first
        $google_id = $this->get_google_place_id($post_id);
        $has_google = !empty($google_id) && in_array('google_places', $services);
        $has_tripadvisor = !empty($this->get_tripadvisor_location_id($post_id)) && in_array('tripadvisor', $services);

        // If POI has Google Places ID, try it first
        if ($has_google) {
            $data = $this->maybe_fetch_google_place_data( $post_id );

            if ( is_wp_error( $data ) ) {
                // Preserve HTTP 429 when quota is exceeded
                $error_data = $data->get_error_data();
                if ( isset( $error_data['status'] ) && $error_data['status'] === 429 ) {
                    return $data;
                }
                // For other errors, don't fallback to TripAdvisor
                $errors['google_places'] = $data->get_error_message();
            } elseif ( is_array( $data ) && isset( $data['enriched'] ) && $data['enriched'] === false ) {
                // Preserve HTTP 429 when quota is exceeded
                if ( isset( $data['reason'] ) && $data['reason'] === 'quota_exceeded' ) {
                    return new \WP_Error(
                        'places_quota_exceeded',
                        'Denní limit Google Places byl vyčerpán.',
                        array( 'status' => 429 )
                    );
                }
                // For non-quota blocks (e.g., feature flag disabled, recently enriched), 
                // don't fallback to TripAdvisor - return error response
                return rest_ensure_response(array(
                    'provider' => null,
                    'data' => null,
                    'status' => $data['reason'] ?? 'blocked',
                    'errors' => $errors,
                    'meta' => array(
                        'google_place_id' => $this->get_google_place_id( $post_id ),
                        'tripadvisor_location_id' => $this->get_tripadvisor_location_id( $post_id ),
                    ),
                ));
            } elseif ( $data ) {
                // Success - return Google Places data
                $payload = array(
                    'provider' => 'google_places',
                    'data' => $data,
                    'expiresAt' => $this->get_service_expiration( $post_id, 'google_places' ),
                    'meta' => array(
                        'google_place_id' => $this->get_google_place_id( $post_id ),
                        'tripadvisor_location_id' => $this->get_tripadvisor_location_id( $post_id ),
                    ),
                );
            }
        }

        // If Google Places failed or POI doesn't have Google Places ID, try TripAdvisor
        // But only if POI has TripAdvisor ID and we haven't already succeeded with Google
        if ( ! $payload && $has_tripadvisor ) {
            $data = $this->maybe_fetch_tripadvisor_data( $post_id );

            if ( is_wp_error( $data ) ) {
                $errors['tripadvisor'] = $data->get_error_message();
            } elseif ( $data ) {
                $payload = array(
                    'provider' => 'tripadvisor',
                    'data' => $data,
                    'expiresAt' => $this->get_service_expiration( $post_id, 'tripadvisor' ),
                    'meta' => array(
                        'google_place_id' => $this->get_google_place_id( $post_id ),
                        'tripadvisor_location_id' => $this->get_tripadvisor_location_id( $post_id ),
                    ),
                );
            }
        }

        if ( ! $payload ) {
            return rest_ensure_response( array(
                'provider' => null,
                'data' => null,
                'errors' => $errors,
            ) );
        }

        if ( ! empty( $errors ) ) {
            $payload['errors'] = $errors;
        }

        return rest_ensure_response( $payload );
    }

    private function get_service_expiration( $post_id, $service ) {
        if ( $service === 'google_places' ) {
            $expires = intval( get_post_meta( $post_id, '_poi_google_cache_expires', true ) );
            return $this->format_timestamp_for_response( $expires );
        }

        if ( $service === 'tripadvisor' ) {
            $expires = intval( get_post_meta( $post_id, '_poi_tripadvisor_cache_expires', true ) );
            return $this->format_timestamp_for_response( $expires );
        }

        return null;
    }

    /**
     * Najde Google kandidáty pro review
     */
    private function find_google_candidates_for_post($post_id) {
        $api_key = get_option('db_google_api_key');
        if (!$api_key) return array();
        $post = get_post($post_id);
        if (!$post) return array();
        
        // Kontrola kvóty
        $quota = new \DB\Jobs\Google_Quota_Manager();
        $quota_check = $quota->reserve_quota(1);
        if (is_wp_error($quota_check)) {
            return array();
        }
        
        $title = trim((string)$post->post_title);
        $lat = (float) get_post_meta($post_id, '_poi_lat', true);
        $lng = (float) get_post_meta($post_id, '_poi_lng', true);
        $body = array('textQuery' => $title, 'maxResultCount' => 5);
        if ($lat || $lng) { $body['locationBias'] = array('circle' => array('center' => array('latitude' => $lat, 'longitude' => $lng), 'radius' => 1000)); }
        $response = wp_remote_post('https://places.googleapis.com/v1/places:searchText', array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $api_key,
                'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.placeId,places.location'
            ),
            'body' => wp_json_encode($body), 'timeout' => 15,
        ));
        if (is_wp_error($response)) return array();
        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['places'])) return array();
        $out = array();
        foreach ($data['places'] as $p) {
            $pid = (string)($p['placeId'] ?? ''); if ($pid==='') continue;
            $pla = $p['location']['latitude'] ?? null; $plo = $p['location']['longitude'] ?? null;
            $dist = ($pla!==null && $plo!==null && ($lat || $lng)) ? intval($this->haversine_km($lat,$lng,(float)$pla,(float)$plo)*1000) : null;
            $score = 0.0;
            if ($title !== '' && !empty($p['displayName']['text'])) { $score += similar_text(mb_strtolower($title), mb_strtolower($p['displayName']['text'])); }
            if ($dist !== null) $score += max(0, 2000 - min(2000, $dist)) / 100.0;
            $out[] = array('provider'=>'google_places','id'=>$pid,'name'=>$p['displayName']['text'] ?? '','address'=>$p['formattedAddress'] ?? '','lat'=>$pla,'lng'=>$plo,'distance_m'=>$dist,'score'=>round($score,2));
        }
        return $out;
    }

    /**
     * Najde Tripadvisor kandidáty pro review
     */
    private function find_tripadvisor_candidates_for_post($post_id) {
        $api_key = get_option('db_tripadvisor_api_key');
        if (!$api_key) return array();
        $post = get_post($post_id);
        if (!$post) return array();
        $title = trim((string)$post->post_title);
        $lat = (float) get_post_meta($post_id, '_poi_lat', true);
        $lng = (float) get_post_meta($post_id, '_poi_lng', true);
        $args = array('key'=>$api_key,'searchQuery'=>$title,'language'=>'cs','category'=>'restaurants,coffee,attractions');
        if ($lat || $lng) $args['latLong'] = $lat . ',' . $lng;
        $url = add_query_arg($args, 'https://api.content.tripadvisor.com/api/v1/location/search');
        $response = wp_remote_get($url, array('timeout'=>15));
        if (is_wp_error($response)) return array();
        $data = json_decode((string)wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['data'])) return array();
        $out = array();
        foreach ($data['data'] as $row) {
            $id = (string)($row['location_id'] ?? ''); if ($id==='') continue;
            $pla = isset($row['latitude']) ? (float)$row['latitude'] : null; $plo = isset($row['longitude']) ? (float)$row['longitude'] : null;
            $dist = ($pla!==null && $plo!==null && ($lat || $lng)) ? intval($this->haversine_km($lat,$lng,$pla,$plo)*1000) : null;
            $score = 0.0;
            if ($title !== '' && !empty($row['name'])) $score += similar_text(mb_strtolower($title), mb_strtolower($row['name']));
            if ($dist !== null) $score += max(0, 2000 - min(2000, $dist)) / 100.0;
            $out[] = array('provider'=>'tripadvisor','id'=>$id,'name'=>$row['name'] ?? '','address'=>$row['address'] ?? '','lat'=>$pla,'lng'=>$plo,'distance_m'=>$dist,'score'=>round($score,2));
        }
        return $out;
    }
    private function persist_enriched_fields( $post_id, $data ) {
        $maybe_update_if_empty = function( $meta_key, $value ) use ( $post_id ) {
            if ( $value === null || $value === '' ) {
                return;
            }
            $existing = get_post_meta( $post_id, $meta_key, true );
            if ( $existing === '' ) {
                update_post_meta( $post_id, $meta_key, $value );
            }
        };

        $maybe_update_if_empty( '_poi_address', $data['address'] ?? '' );
        $maybe_update_if_empty( '_poi_phone', $data['phone'] ?? '' );
        $maybe_update_if_empty( '_poi_website', $data['website'] ?? '' );

        if ( isset( $data['rating'] ) ) {
            update_post_meta( $post_id, '_poi_rating', $data['rating'] );
        }
        if ( isset( $data['userRatingCount'] ) ) {
            update_post_meta( $post_id, '_poi_user_rating_count', $data['userRatingCount'] );
        }
        if ( ! empty( $data['openingHours'] ) ) {
            $encoded = is_string( $data['openingHours'] ) ? $data['openingHours'] : wp_json_encode( $data['openingHours'] );
            update_post_meta( $post_id, '_poi_opening_hours', $encoded );
        }
        if ( ! empty( $data['photos'] ) ) {
            update_post_meta( $post_id, '_poi_photos', wp_json_encode( $data['photos'] ) );
        }
        if ( ! empty( $data['socialLinks'] ) ) {
            update_post_meta( $post_id, '_poi_social_links', wp_json_encode( $data['socialLinks'] ) );
        }
        if ( ! empty( $data['mapUrl'] ) ) {
            update_post_meta( $post_id, '_poi_url', $data['mapUrl'] );
        }
        // Ulož přímou URL fotky pro snadné použití na FE
        if ( ! empty( $data['photoUrl'] ) && is_string( $data['photoUrl'] ) ) {
            update_post_meta( $post_id, '_poi_photo_url', esc_url_raw( $data['photoUrl'] ) );
        }
    }

    /**
     * AJAX handler for Google Places Nearby Search
     */
    public function ajax_google_places_nearby() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_die('Security check failed');
        }
        
        $lat = floatval($_POST['lat'] ?? 0);
        $lng = floatval($_POST['lng'] ?? 0);
        $radius = intval($_POST['radius'] ?? 500);
        $max_results = intval($_POST['maxResults'] ?? 5);
        
        if (!$lat || !$lng) {
            wp_send_json_error('Chybí souřadnice');
        }
        
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            wp_send_json_error('Google API klíč není nastaven');
        }
        
        // Kontrola kvóty
        $quota = new \DB\Jobs\Google_Quota_Manager();
        $quota_check = $quota->reserve_quota(1);
        if (is_wp_error($quota_check)) {
            wp_send_json_error(array(
                'message' => $quota_check->get_error_message(),
                'quota_status' => $quota_check->get_error_data('quota_status'),
            ));
        }
        
        // Nearby Search (New API) - POST request
        $url = 'https://places.googleapis.com/v1/places:searchNearby';
        $body = array(
            'includedTypes' => array('establishment'),
            'maxResultCount' => $max_results,
            'locationRestriction' => array(
                'circle' => array(
                    'center' => array(
                        'latitude' => $lat,
                        'longitude' => $lng
                    ),
                    'radius' => $radius
                )
            )
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'X-Goog-Api-Key' => $api_key,
                'X-Goog-FieldMask' => 'places.displayName,places.formattedAddress,places.types,places.placeId,places.location'
            ),
            'body' => json_encode($body)
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Chyba při volání Google API: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Google API chyba: ' . ($data['error']['message'] ?? 'Neznámá chyba'));
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * AJAX handler for Google Place Details
     */
    public function ajax_google_place_details() {
        // Check nonce
        if (!wp_verify_nonce($_POST['nonce'], 'wp_rest')) {
            wp_die('Security check failed');
        }
        
        $place_id = sanitize_text_field($_POST['placeId'] ?? '');
        
        if (!$place_id) {
            wp_send_json_error('Chybí Place ID');
        }
        
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            wp_send_json_error('Google API klíč není nastaven');
        }
        
        // Kontrola kvóty
        $quota = new \DB\Jobs\Google_Quota_Manager();
        $quota_check = $quota->reserve_quota(1);
        if (is_wp_error($quota_check)) {
            wp_send_json_error(array(
                'message' => $quota_check->get_error_message(),
                'quota_status' => $quota_check->get_error_data('quota_status'),
            ));
        }
        
        // Place Details (New API) - GET request
        $url = "https://places.googleapis.com/v1/places/{$place_id}";
        
        $response = wp_remote_get($url, array(
            'headers' => array(
                'X-Goog-Api-Key' => $api_key,
                'X-Goog-FieldMask' => 'displayName,formattedAddress,location,types,placeId,nationalPhoneNumber,internationalPhoneNumber,websiteUri,rating,userRatingCount,priceLevel,regularOpeningHours,photos,iconUri,iconBackgroundColor,iconMaskUri,editorialSummary'
            )
        ));
        
        if (is_wp_error($response)) {
            wp_send_json_error('Chyba při volání Google API: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (isset($data['error'])) {
            wp_send_json_error('Google API chyba: ' . ($data['error']['message'] ?? 'Neznámá chyba'));
        }
        
        wp_send_json_success($data);
    }
    
    /**
     * REST handler for POI photo upload
     */
    public function handle_poi_photo_upload($request) {
        // Načíst potřebné WordPress funkce
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        $photo_reference = sanitize_text_field($request['photo_reference']);
        $api_key = get_option('db_google_api_key');
        
        if (empty($photo_reference) || empty($api_key)) {
            return new \WP_Error('missing_data', 'Chybí photo_reference nebo API key', array('status' => 400));
        }
        
        // Vytvořit URL pro Google Places Photo API
        $url = 'https://maps.googleapis.com/maps/api/place/photo?maxwidth=800&photoreference=' . $photo_reference . '&key=' . $api_key;
        
        // Nejdříve zkusit stáhnout obrázek
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('download_error', 'Chyba při stahování fotky: ' . $response->get_error_message(), array('status' => 500));
        }
        
        $response_code = wp_remote_retrieve_response_code($response);
        if ($response_code !== 200) {
            return new \WP_Error('http_error', 'HTTP chyba: ' . $response_code, array('status' => 500));
        }
        
        $image_data = wp_remote_retrieve_body($response);
        if (empty($image_data)) {
            return new \WP_Error('empty_image', 'Prázdná fotka', array('status' => 500));
        }
        
        // Vytvořit dočasný soubor
        $upload_dir = wp_upload_dir();
        $filename = 'poi-photo-' . time() . '.jpg';
        $file_path = $upload_dir['path'] . '/' . $filename;
        
        if (file_put_contents($file_path, $image_data) === false) {
            return new \WP_Error('file_error', 'Chyba při ukládání souboru', array('status' => 500));
        }
        
        // Připravit soubor pro WordPress
        $file_array = array(
            'name' => $filename,
            'tmp_name' => $file_path,
            'type' => 'image/jpeg'
        );
        
        // Nahrát do media library
        $attachment_id = media_handle_sideload($file_array, 0, 'POI Photo');
        
        if (is_wp_error($attachment_id)) {
            unlink($file_path); // Smazat dočasný soubor
            return new \WP_Error('upload_error', 'Chyba při nahrávání: ' . $attachment_id->get_error_message(), array('status' => 500));
        }
        
        return array('id' => $attachment_id);
    }
    
    /**
     * TomTom EV Search API - hledání nabíjecích stanic v okolí
     */
    public function handle_tomtom_ev_search($request) {
        $params = $request->get_json_params();
        $lat = floatval($params['lat'] ?? 0);
        $lng = floatval($params['lng'] ?? 0);
        $radius = intval($params['radius'] ?? 5000); // v metrech
        
        $api_key = get_option('db_tomtom_api_key');
        if (!$api_key) {
            return new \WP_Error('missing_api_key', 'TomTom API klíč není nastaven', array('status' => 400));
        }
        
        // TomTom EV Search API - Nearby Search
        $url = "https://api.tomtom.com/evsearch/2/nearby.json";
        $url .= "?key=" . urlencode($api_key);
        $url .= "&lat=" . urlencode($lat);
        $url .= "&lon=" . urlencode($lng);
        $url .= "&radius=" . urlencode($radius);
        $url .= "&limit=50"; // maximální počet výsledků
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('api_error', 'Chyba při volání TomTom API: ' . $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi', array('status' => 500));
        }
        
        if (isset($data['error'])) {
            return new \WP_Error('tomtom_error', 'TomTom API chyba: ' . ($data['error']['description'] ?? 'Neznámá chyba'), array('status' => 500));
        }
        
        return rest_ensure_response($data);
    }
    
    /**
     * TomTom EV Availability API - dostupnost nabíjecích stanic
     */
    public function handle_tomtom_ev_availability($request) {
        $params = $request->get_json_params();
        $availability_ids = $params['availability_ids'] ?? [];
        
        if (empty($availability_ids)) {
            return new \WP_Error('missing_ids', 'Chybí availability IDs', array('status' => 400));
        }
        
        $api_key = get_option('db_tomtom_api_key');
        if (!$api_key) {
            return new \WP_Error('missing_api_key', 'TomTom API klíč není nastaven', array('status' => 400));
        }
        
        // TomTom EV Availability API - batch request (max 20 IDs)
        $batch_size = 20;
        $results = [];
        
        for ($i = 0; $i < count($availability_ids); $i += $batch_size) {
            $batch = array_slice($availability_ids, $i, $batch_size);
            $ids_string = implode(',', $batch);
            
            $url = "https://api.tomtom.com/search/2/chargingAvailability.json";
            $url .= "?key=" . urlencode($api_key);
            $url .= "&chargingAvailability=" . urlencode($ids_string);
            
            $response = wp_remote_get($url, array(
                'timeout' => 30,
                'user-agent' => 'WordPress/' . get_bloginfo('version')
            ));
            
            if (is_wp_error($response)) {
                return new \WP_Error('api_error', 'Chyba při volání TomTom API: ' . $response->get_error_message(), array('status' => 500));
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi', array('status' => 500));
            }
            
            if (isset($data['error'])) {
                return new \WP_Error('tomtom_error', 'TomTom API chyba: ' . ($data['error']['description'] ?? 'Neznámá chyba'), array('status' => 500));
            }
            
            if (isset($data['results'])) {
                $results = array_merge($results, $data['results']);
            }
        }
        
        return rest_ensure_response(array('results' => $results));
    }
    
    /**
     * TomTom EV Details API - detail nabíjecí stanice podle ID
     */
    public function handle_tomtom_ev_details($request) {
        $params = $request->get_json_params();
        $poi_id = $params['poi_id'] ?? '';
        
        if (empty($poi_id)) {
            return new \WP_Error('missing_poi_id', 'Chybí POI ID', array('status' => 400));
        }
        
        $api_key = get_option('db_tomtom_api_key');
        if (!$api_key) {
            return new \WP_Error('missing_api_key', 'TomTom API klíč není nastaven', array('status' => 400));
        }
        
        // TomTom EV Search API - Search by ID
        $url = "https://api.tomtom.com/evsearch/2/id.json";
        $url .= "?key=" . urlencode($api_key);
        $url .= "&ids=" . urlencode($poi_id);
        
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'user-agent' => 'WordPress/' . get_bloginfo('version')
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('api_error', 'Chyba při volání TomTom API: ' . $response->get_error_message(), array('status' => 500));
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi', array('status' => 500));
        }
        
        if (isset($data['error'])) {
            return new \WP_Error('tomtom_error', 'TomTom API chyba: ' . ($data['error']['description'] ?? 'Neznámá chyba'), array('status' => 500));
        }
        
        return rest_ensure_response($data);
    }
    
    /**
     * DATEX II Status API - získání aktuální dostupnosti stanic
     */
    public function handle_datex_status($request) {
        $params = $request->get_json_params();
        $station_ids = $params['station_ids'] ?? [];
        $refill_point_ids = $params['refill_point_ids'] ?? [];
        
        if (empty($station_ids) && empty($refill_point_ids)) {
            return new \WP_Error('missing_ids', 'Chybí station IDs nebo refill point IDs', array('status' => 400));
        }
        
        // Použití DATEX_Manager pro získání skutečných dat
        $datex_manager = \DB\DATEX_Manager::get_instance();
        $status_data = $datex_manager->get_datex_status($station_ids, $refill_point_ids);
        
        if (is_wp_error($status_data)) {
            return $status_data;
        }
        
        return rest_ensure_response(array('results' => $status_data));
    }
    
    /**
     * DATEX II Stations API - získání seznamu stanic
     */
    public function handle_datex_stations($request) {
        // DATEX II EnergyInfrastructureTablePublication
        $region = $request->get_param('region') ?? 'CZ';
        
        // Použití DATEX_Manager pro import stanic
        $datex_manager = \DB\DATEX_Manager::get_instance();
        $import_result = $datex_manager->import_datex_stations($region);
        
        if (isset($import_result['errors']) && !empty($import_result['errors'])) {
            return new \WP_Error('import_errors', 'Chyby při importu: ' . implode(', ', $import_result['errors']), array('status' => 500));
        }
        
        return rest_ensure_response(array(
            'success' => true,
            'imported' => $import_result['imported'],
            'message' => 'Import dokončen: ' . $import_result['imported'] . ' stanic importováno'
        ));
    }
    
    /**
     * Get detailed charging location data for debugging
     */
    public function get_charging_location_details($request) {
        $id = $request['id'];
        
        if (!$id) {
            return new \WP_Error('missing_id', 'Chybí ID nabíječky', array('status' => 400));
        }
        
        $post = get_post($id);
        if (!$post || $post->post_type !== 'charging_location') {
            return new \WP_Error('not_found', 'Nabíječka nenalezena', array('status' => 404));
        }
        
        // Načíst všechna meta data
        $meta_data = get_post_meta($id);
        
        // Načíst taxonomie
        $charger_types = wp_get_post_terms($id, 'charger_type');
        $providers = wp_get_post_terms($id, 'provider');
        
        // Načíst specifické meta klíče pro konektory
        $charger_counts = get_post_meta($id, '_db_charger_counts', true);
        $db_connectors = get_post_meta($id, '_db_connectors', true);
        $ocm_connectors = get_post_meta($id, '_ocm_connectors', true);
        $connectors_meta = get_post_meta($id, '_connectors', true);
        $mpo_connectors = get_post_meta($id, '_mpo_connectors', true);
        
        return rest_ensure_response(array(
            'post_id' => $id,
            'post_title' => $post->post_title,
            'post_type' => $post->post_type,
            'charger_types' => $charger_types,
            'providers' => $providers,
            'charger_counts' => $charger_counts,
            'db_connectors' => $db_connectors,
            'ocm_connectors' => $ocm_connectors,
            'connectors_meta' => $connectors_meta,
            'mpo_connectors' => $mpo_connectors,
            'meta_data' => $meta_data
        ));
    }
    
    /**
     * Dev seed endpoint - vytvoří testovací data
     */
    public function dev_seed($request) {
        $lat = (float)($request['lat'] ?? 50.0755);
        $lng = (float)($request['lng'] ?? 14.4378);

        $make = function($type, $title, $dlat, $dlng) use ($lat,$lng) {
            $id = wp_insert_post(['post_type'=>$type,'post_status'=>'publish','post_title'=>$title]);
            if ($type === 'charging_location') {
                update_post_meta($id, '_db_lat',  $lat+$dlat);
                update_post_meta($id, '_db_lng',  $lng+$dlng);
            } elseif ($type === 'poi') {
                update_post_meta($id, '_poi_lat', $lat+$dlat);
                update_post_meta($id, '_poi_lng', $lng+$dlng);
            } elseif ($type === 'rv_spot') {
                update_post_meta($id, '_rv_lat',  $lat+$dlat);
                update_post_meta($id, '_rv_lng',  $lng+$dlng);
            }
            return $id;
        };

        $ids = [];
        $ids[] = $make('charging_location', 'Test Charger A',  0.005,  0.005);
        $ids[] = $make('charging_location', 'Test Charger B', -0.004,  0.002);
        $ids[] = $make('poi',               'Test Café',       0.002, -0.003);
        $ids[] = $make('poi',               'Test Market',    -0.003, -0.001);

        return rest_ensure_response(['ok'=>true,'created'=>$ids]);
    }
    
    /**
     * Providers endpoint - získá všechny provozovatele seřazené podle počtu nabíjecích bodů
     */
    public function handle_providers($request) {
        global $wpdb;
        
        // Načíst všechny provider termy z taxonomie 'provider'
        $terms = get_terms(array(
            'taxonomy' => 'provider',
            'hide_empty' => false,
        ));
        
        if (is_wp_error($terms) || empty($terms)) {
            return rest_ensure_response([
                'providers' => []
            ]);
        }
        
        $providers_with_count = [];
        
        foreach ($terms as $term) {
            // Počítat kolik charging_location má tento provider
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) 
                FROM {$wpdb->posts} p
                INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                WHERE p.post_type = 'charging_location'
                AND p.post_status = 'publish'
                AND tr.term_taxonomy_id = %d",
                $term->term_taxonomy_id
            ));
            
            // Načíst friendly name a logo z term meta
            $friendly_name = get_term_meta($term->term_id, 'provider_friendly_name', true);
            $logo = get_term_meta($term->term_id, 'provider_logo', true);
            
            $providers_with_count[] = [
                'name' => $term->name,
                'nickname' => $friendly_name,
                'icon' => $logo,
                'count' => (int)$count
            ];
        }
        
        // Seřadit podle počtu bodů (nejvíc bodů na začátku)
        usort($providers_with_count, function($a, $b) {
            return $b['count'] - $a['count'];
        });
        
        return rest_ensure_response([
            'providers' => $providers_with_count
        ]);
    }

    /**
     * Filter options endpoint - globální katalog filtrů z celé DB
     * Vrací všechny dostupné možnosti pro filtry (providers, charger_types, poi_types, amenities, rating)
     */
    public function handle_filter_options( $request ) {
        global $wpdb;
        
        $options = [
            'providers' => [],
            'charger_types' => [],
            'poi_types' => [],
            'amenities' => [],
            'rating' => [],
        ];
        
        // Providers - taxonomie provider
        $provider_terms = get_terms(array(
            'taxonomy' => 'provider',
            'hide_empty' => false,
        ));
        if (!is_wp_error($provider_terms) && !empty($provider_terms)) {
            foreach ($provider_terms as $term) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     WHERE p.post_type = 'charging_location' AND p.post_status = 'publish'
                     AND tr.term_taxonomy_id = %d",
                    $term->term_taxonomy_id
                ));
                $options['providers'][] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => (int)$count,
                ];
            }
            usort($options['providers'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
        }
        
        // Charger types (connector types) - taxonomie charger_type
        $charger_type_terms = get_terms(array(
            'taxonomy' => 'charger_type',
            'hide_empty' => false,
        ));
        if (!is_wp_error($charger_type_terms) && !empty($charger_type_terms)) {
            foreach ($charger_type_terms as $term) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     WHERE p.post_type = 'charging_location' AND p.post_status = 'publish'
                     AND tr.term_taxonomy_id = %d",
                    $term->term_taxonomy_id
                ));
                $options['charger_types'][] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => (int)$count,
                ];
            }
            usort($options['charger_types'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
        }
        
        // POI types - taxonomie poi_type
        $poi_type_terms = get_terms(array(
            'taxonomy' => 'poi_type',
            'hide_empty' => false,
        ));
        if (!is_wp_error($poi_type_terms) && !empty($poi_type_terms)) {
            foreach ($poi_type_terms as $term) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     WHERE p.post_type = 'poi' AND p.post_status = 'publish'
                     AND tr.term_taxonomy_id = %d",
                    $term->term_taxonomy_id
                ));
                $options['poi_types'][] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => (int)$count,
                ];
            }
            usort($options['poi_types'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
        }
        
        // Amenities - taxonomie amenity
        $amenity_terms = get_terms(array(
            'taxonomy' => 'amenity',
            'hide_empty' => false,
        ));
        if (!is_wp_error($amenity_terms) && !empty($amenity_terms)) {
            foreach ($amenity_terms as $term) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     WHERE p.post_type IN ('charging_location', 'rv_spot', 'poi') AND p.post_status = 'publish'
                     AND tr.term_taxonomy_id = %d",
                    $term->term_taxonomy_id
                ));
                $icon = get_term_meta($term->term_id, 'icon', true);
                $options['amenities'][] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'icon' => $icon,
                    'count' => (int)$count,
                ];
            }
            usort($options['amenities'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
        }
        
        // Rating - taxonomie rating (volitelně)
        $rating_terms = get_terms(array(
            'taxonomy' => 'rating',
            'hide_empty' => false,
        ));
        if (!is_wp_error($rating_terms) && !empty($rating_terms)) {
            foreach ($rating_terms as $term) {
                $count = $wpdb->get_var($wpdb->prepare(
                    "SELECT COUNT(*) FROM {$wpdb->posts} p
                     INNER JOIN {$wpdb->term_relationships} tr ON p.ID = tr.object_id
                     WHERE p.post_type IN ('charging_location', 'rv_spot', 'poi') AND p.post_status = 'publish'
                     AND tr.term_taxonomy_id = %d",
                    $term->term_taxonomy_id
                ));
                $options['rating'][] = [
                    'id' => $term->term_id,
                    'name' => $term->name,
                    'slug' => $term->slug,
                    'count' => (int)$count,
                ];
            }
            usort($options['rating'], function($a, $b) {
                return $b['count'] - $a['count'];
            });
        }
        
        return rest_ensure_response($options);
    }

    /**
     * Special dataset handler - pro db_recommended/free bez radius filtru
     * S server-side cache na 5-15 minut
     */
    public function handle_map_special($request) {
        $db_recommended = $request->get_param('db_recommended'); // '1' nebo null
        $free_only = $request->get_param('free'); // '1' nebo null
        $fields_mode = $request->get_param('fields') ?: 'minimal';
        if (!in_array($fields_mode, ['minimal', 'full'], true)) {
            $fields_mode = 'minimal';
        }
        
        $limit = max(1, min(2000, intval($request->get_param('limit') ?: 2000)));
        
        // Cache key podle kombinace db_recommended/free
        $cache_key = 'db_map_special_' . md5(sprintf('%s_%s_%s_%d', 
            $db_recommended ? '1' : '0',
            $free_only ? '1' : '0',
            $fields_mode,
            $limit
        ));
        $cache_ttl = 10 * MINUTE_IN_SECONDS; // 10 minut
        
        // Zkusit cache
        $cached_result = get_transient($cache_key);
        if ($cached_result !== false) {
            // Přidat metadata o cache
            if (is_array($cached_result) && isset($cached_result['features'])) {
                $cached_result['cache'] = [
                    'key' => $cache_key,
                    'ttl' => $cache_ttl,
                    'cached' => true
                ];
            }
            return rest_ensure_response($cached_result);
        }
        
        // Načíst charging_location s filtry
        $args = [
            'post_type' => 'charging_location',
            'post_status' => 'publish',
            'posts_per_page' => $limit,
            'no_found_rows' => true,
            'orderby' => 'date',
            'order' => 'DESC',
        ];
        
        $meta_query = [];
        if ($db_recommended === '1') {
            $meta_query[] = [
                'key' => '_db_recommended',
                'value' => '1',
                'compare' => '='
            ];
        }
        if ($free_only === '1') {
            $meta_query[] = [
                'key' => '_db_price',
                'value' => 'free',
                'compare' => '='
            ];
        }
        if (!empty($meta_query)) {
            $args['meta_query'] = $meta_query;
        }
        
        $query = new \WP_Query($args);
        $features = [];
        
        // Inicializovat favorite_assignments
        $favorite_assignments = [];
        $favorite_folders_index = [];
        $current_user_id = get_current_user_id();
        if ($current_user_id > 0 && class_exists('\\DB\\Favorites_Manager')) {
            try {
                $favorites_manager = \DB\Favorites_Manager::get_instance();
                $state = $favorites_manager->get_state($current_user_id);
                $favorite_assignments = $state['assignments'] ?? [];
                $folders = $favorites_manager->get_folders($current_user_id);
                foreach ($folders as $folder) {
                    if (isset($folder['id'])) {
                        $favorite_folders_index[(string)$folder['id']] = $folder;
                    }
                }
            } catch (\Exception $e) {
                // Silent fail
            }
        }
        
        foreach ($query->posts as $post) {
            $keys = $this->get_latlng_keys_for_type('charging_location');
            $lat = (float) get_post_meta($post->ID, $keys['lat'], true);
            $lng = (float) get_post_meta($post->ID, $keys['lng'], true);
            
            if (!$lat || !$lng) continue;
            
            $properties = $this->build_minimal_properties($post, 'charging_location', $fields_mode, $favorite_assignments, $favorite_folders_index);
            
            $features[] = [
                'type' => 'Feature',
                'geometry' => [
                    'type' => 'Point',
                    'coordinates' => [$lng, $lat]
                ],
                'properties' => $properties
            ];
        }
        
        $response_data = [
            'type' => 'FeatureCollection',
            'features' => $features
        ];
        
        // Uložit do cache
        set_transient($cache_key, $response_data, $cache_ttl);
        
        // Přidat metadata o cache
        $response_data['cache'] = [
            'key' => $cache_key,
            'ttl' => $cache_ttl,
            'cached' => false
        ];
        
        return rest_ensure_response($response_data);
    }

    /**
     * Invalidace cache při změně/uložení postu
     */
    public function invalidate_special_cache($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if (!in_array($post->post_type, ['charging_location', 'poi', 'rv_spot'])) {
            return;
        }
        
        // Vymazat všechny special cache klíče
        global $wpdb;
        $pattern = 'db_map_special_%';
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_' . $pattern
        ));
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
            '_transient_timeout_' . $pattern
        ));
    }

    /**
     * Build minimal properties pro feature (používá stejnou logiku jako handle_map)
     */
    private function build_minimal_properties($post, $post_type, $fields_mode, $favorite_assignments, $favorite_folders_index) {
        // Použít stejnou logiku jako v handle_map pro minimal payload
        $icon_registry = \DB\Icon_Registry::get_instance();
        $icon_data = $icon_registry->get_icon($post);
        $icon_data = is_array($icon_data) ? $icon_data : array();
        
        $properties = [
            'id' => $post->ID,
            'post_type' => $post_type,
            'title' => get_the_title($post),
            'icon_slug' => (!empty($icon_data['slug']) ? $icon_data['slug'] : get_post_meta($post->ID, '_icon_slug', true)),
            'icon_color' => (!empty($icon_data['color']) ? $icon_data['color'] : get_post_meta($post->ID, '_icon_color', true)),
            'svg_content' => (!empty($icon_data['svg_content']) ? $icon_data['svg_content'] : ''),
            'db_recommended' => get_post_meta($post->ID, '_db_recommended', true) === '1' ? 1 : 0,
        ];
        
        if ($post_type === 'charging_location') {
            $provider_terms = wp_get_post_terms($post->ID, 'provider');
            if (!empty($provider_terms) && !is_wp_error($provider_terms)) {
                $properties['provider'] = $provider_terms[0]->name;
                $properties['provider_slug'] = $provider_terms[0]->slug;
            }
            $charger_terms = wp_get_post_terms($post->ID, 'charger_type');
            if (!empty($charger_terms) && !is_wp_error($charger_terms)) {
                $properties['charger_type'] = $charger_terms[0]->name;
                $properties['charger_type_slug'] = $charger_terms[0]->slug;
            }
        }
        
        // Favorites
        if (!empty($favorite_assignments)) {
            $fav_id = $favorite_assignments[$post->ID] ?? null;
            if ($fav_id && isset($favorite_folders_index[(string)$fav_id])) {
                $properties['favorite_folder_id'] = (string)$fav_id;
                $folder_meta = $favorite_folders_index[(string)$fav_id];
                $properties['favorite_folder'] = [
                    'id' => $folder_meta['id'] ?? $fav_id,
                    'name' => $folder_meta['name'] ?? '',
                    'icon' => $folder_meta['icon'] ?? '',
                    'type' => $folder_meta['type'] ?? 'custom',
                    'limit' => $folder_meta['limit'] ?? 0,
                ];
            }
        }
        
        return $properties;
    }
} 