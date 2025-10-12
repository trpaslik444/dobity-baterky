<?php
/**
 * REST endpoint pro GeoJSON mapu
 * @package DobityBaterky
 */

namespace DB;

class REST_Map {
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function __construct() {
        add_action( 'wp_ajax_google_places_nearby', array( $this, 'ajax_google_places_nearby' ) );
        add_action( 'wp_ajax_google_place_details', array( $this, 'ajax_google_place_details' ) );
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
        
        // Map data endpoint
        register_rest_route( 'db/v1', '/map', array(
            'methods'  => 'GET',
            'callback' => array( $this, 'handle_map' ),
            'permission_callback' => function ( $request ) {
                // Zkontroluj nonce autentizaci
                if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
                    return false;
                }
                
                // Jednoduchá kontrola - necháme Members plugin, aby měl kontrolu
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

    public function handle_map( $request ) {
        // Debug logging
        error_log('DB Map REST: handle_map spuštěn');
        // error_log('DB Map REST: Parametry - included: ' . $request->get_param('included') . ', center: ' . $request->get_param('center') . ', radius_km: ' . $request->get_param('radius_km'));
        
        // --- vstupy
        $included  = $request->get_param('included');  // např. "charging_location,rv_spot,poi"
        $post_types = $request->get_param('post_types'); // alternativní parametr pro kompatibilitu
        $center    = $request->get_param('center');    // "50.08,14.42"
        $radius_km = floatval($request->get_param('radius_km')); // např. 20
        $limit     = max(1, intval($request->get_param('limit') ?: 5000));
        $offset    = max(0, intval($request->get_param('offset') ?: 0));

        $post_type_map = [
            'charger' => 'charging_location',
            'rv_spot' => 'rv_spot',
            'poi'     => 'poi',
            'charging_location' => 'charging_location',
        ];
        // Použij post_types pokud je k dispozici, jinak included
        $types_param = $post_types ?: $included;
        
        if ($types_param) {
            $types = array_values(array_unique(array_map(function($t) use($post_type_map) {
                $t = trim($t);
                return $post_type_map[$t] ?? $t;
            }, explode(',', $types_param))));
        } else {
            $types = ['charging_location','rv_spot','poi'];
        }
        
        error_log('DB Map REST: Post types ke zpracování: ' . print_r($types, true));
        
        // DEBUG: Kontrola existence postů s danými post_types
        foreach ($types as $pt) {
            $count = wp_count_posts($pt);
            error_log('DB Map REST: Post count pro ' . $pt . ': ' . print_r($count, true));
            
            // DEBUG: Kontrola meta klíčů na prvním existujícím postu
            if ($count->publish > 0) {
                $sample_post = get_posts([
                    'post_type' => $pt,
                    'post_status' => 'publish',
                    'posts_per_page' => 1,
                    'orderby' => 'ID',
                    'order' => 'ASC'
                ]);
                if (!empty($sample_post)) {
                    $sample = $sample_post[0];
                    $keys = $this->get_latlng_keys_for_type($pt);
                    $sample_lat = get_post_meta($sample->ID, $keys['lat'], true);
                    $sample_lng = get_post_meta($sample->ID, $keys['lng'], true);
                    error_log('DB Map REST: Vzorek post ' . $pt . ' ID ' . $sample->ID . ' - ' . $keys['lat'] . ': ' . $sample_lat . ', ' . $keys['lng'] . ': ' . $sample_lng);
                }
                
                // DEBUG: Kontrola rozsahu souřadnic
                $keys = $this->get_latlng_keys_for_type($pt);
                global $wpdb;
                $min_lat = $wpdb->get_var($wpdb->prepare(
                    "SELECT MIN(CAST(meta_value AS DECIMAL(10,8))) FROM {$wpdb->postmeta} 
                     WHERE meta_key = %s AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
                     )",
                    $keys['lat'], $pt
                ));
                $max_lat = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(CAST(meta_value AS DECIMAL(10,8))) FROM {$wpdb->postmeta} 
                     WHERE meta_key = %s AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
                     )",
                    $keys['lat'], $pt
                ));
                $min_lng = $wpdb->get_var($wpdb->prepare(
                    "SELECT MIN(CAST(meta_value AS DECIMAL(10,8))) FROM {$wpdb->postmeta} 
                     WHERE meta_key = %s AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
                     )",
                    $keys['lng'], $pt
                ));
                $max_lng = $wpdb->get_var($wpdb->prepare(
                    "SELECT MAX(CAST(meta_value AS DECIMAL(10,8))) FROM {$wpdb->postmeta} 
                     WHERE meta_key = %s AND post_id IN (
                         SELECT ID FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish'
                     )",
                    $keys['lng'], $pt
                ));
                error_log('DB Map REST: Rozsah souřadnic pro ' . $pt . ' - lat: [' . $min_lat . ', ' . $max_lat . '], lng: [' . $min_lng . ', ' . $max_lng . ']');
            }
        }

        // Robustní příjem parametrů - nejprve zkusit oddělené lat/lng, pak center
        $lat = $lng = null;
        
        // 1. Zkusit oddělené parametry lat/lng (robustnější)
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
        
        // Určení režimu načítání
        $use_radius = false;
        if ($lat !== null && $lng !== null && $radius_km > 0) {
            $use_radius = true;
        }
        
        // Pro režim "all" necháme $use_radius = false a pokračujeme
        if (!$use_radius) {
            error_log('DB Map REST: Režim "all" - načítám všechna data bez radius filtru');
        }

        if ($use_radius) {
            // cca převod km -> stupně (zvětšeno o 20% pro jistotu)
            $dLat  = ($radius_km * 1.2) / 111.0;
            $dLng  = ($radius_km * 1.2) / (111.0 * max(cos(deg2rad($lat)), 0.000001));
            $minLa = $lat - $dLat; $maxLa = $lat + $dLat;
            $minLo = $lng - $dLng; $maxLo = $lng + $dLng;
            
            // error_log('DB Map REST: Bbox parametry - lat: ' . $lat . ', lng: ' . $lng . ', radius: ' . $radius_km . 'km');
            // error_log('DB Map REST: Bbox hranice - lat: [' . $minLa . ', ' . $maxLa . '], lng: [' . $minLo . ', ' . $maxLo . ']');
        }

        $features = [];
        $debug_stats = [ 'per_type' => [], 'totals' => [ 'found' => 0, 'bbox' => 0, 'kept' => 0 ] ];

        foreach ($types as $pt) {
            error_log('DB Map REST: Zpracovávám post_type: ' . $pt);
            $keys = $this->get_latlng_keys_for_type($pt);
            // error_log('DB Map REST: Meta klíče pro ' . $pt . ': ' . print_r($keys, true));

            $args = [
                'post_type'      => $pt,
                'post_status'    => 'publish',
                'no_found_rows'  => true,
                // V radius větvi necháme vyšší strop (prefilter), následně ořízneme až po Haversine
                'posts_per_page' => $use_radius ? 5000 : $limit, // Zvýšíme limit pro lepší pokrytí
                'orderby'        => 'date', // Seřadit podle data pro lepší pokrytí
                'order'          => 'DESC',
            ];

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
                    error_log('DB Map REST: Post ' . $post->ID . ' - neplatné souřadnice, přeskočeno');
                    continue; 
                }
                $laF = (float)$laV; $loF = (float)$loV;
                $bbox_count++;

                // přesný dořez - pouze v radius režimu
                $distance_km = 0;
                if ($use_radius) {
                    // Ochrana před edge-case: pokud je některá meta NULL/0/nesmysl, přeskoč před Haversinem
                    if (abs($laF) < 0.000001 && abs($loF) < 0.000001) {
                        continue;
                    }
                    $d = $this->haversine_km($lat, $lng, $laF, $loF);
                    if ($d > $radius_km) {
                        continue;
                    }
                    $distance_km = $d;
                    $haversine_count++;
                } else {
                    // V režimu "all" počítáme všechny body
                    $haversine_count++;
                }

                // Načtení ikon a barev pomocí Icon_Registry
                $icon_registry = \DB\Icon_Registry::get_instance();
                $icon_data = $icon_registry->get_icon($post);
                
                // Debug pro ikony
                // if ($pt === 'poi') {
                //     error_log('[REST DEBUG] POI ID: ' . $post->ID . ', Title: ' . get_the_title($post));
                //     error_log('[REST DEBUG] Icon data: ' . print_r($icon_data, true));
                // }
                
                // Základní properties
                $properties = [
                    'id' => $post->ID,
                    'post_type' => $pt,
                    'title' => get_the_title($post),
                    'distance_km' => $distance_km,
                    // Přidání všech potřebných metadat pro ikony a barvy
                    'icon_slug' => $icon_data['slug'] ?: get_post_meta($post->ID, '_icon_slug', true),
                    'icon_color' => $icon_data['color'] ?: get_post_meta($post->ID, '_icon_color', true),
                    'svg_content' => $icon_data['svg_content'] ?? '',
                    'provider' => get_post_meta($post->ID, '_db_provider', true),
                    'speed' => get_post_meta($post->ID, '_db_speed', true),
                    'connectors' => get_post_meta($post->ID, '_db_connectors', true),
                    'konektory' => get_post_meta($post->ID, '_db_konektory', true),
                    'db_recommended' => get_post_meta($post->ID, '_db_recommended', true) === '1' ? 1 : 0,
                    'image' => get_post_meta($post->ID, '_db_image', true),
                    'address' => get_post_meta($post->ID, '_db_address', true),
                    'phone' => get_post_meta($post->ID, '_db_phone', true),
                    'website' => get_post_meta($post->ID, '_db_website', true),
                    'rating' => get_post_meta($post->ID, '_db_rating', true),
                    'description' => get_post_meta($post->ID, '_db_description', true),
                    // RV spot specific metadata
                    'rv_type' => get_post_meta($post->ID, '_rv_type', true),
                    'rv_address' => get_post_meta($post->ID, '_rv_address', true),
                    'rv_phone' => get_post_meta($post->ID, '_rv_phone', true),
                    'rv_website' => get_post_meta($post->ID, '_rv_website', true),
                    'rv_rating' => get_post_meta($post->ID, '_rv_rating', true),
                    'rv_description' => get_post_meta($post->ID, '_rv_description', true),
                    // POI specific metadata
                    'poi_type' => get_post_meta($post->ID, '_poi_type', true),
                    'poi_address' => get_post_meta($post->ID, '_poi_address', true),
                    'poi_phone' => get_post_meta($post->ID, '_poi_phone', true),
                    'poi_website' => get_post_meta($post->ID, '_poi_website', true),
                    'poi_rating' => get_post_meta($post->ID, '_poi_rating', true),
                    'poi_description' => get_post_meta($post->ID, '_poi_description', true),
                    // Univerzální metadata pro všechny typy
                    'content' => $post->post_content,
                    'excerpt' => $post->post_excerpt,
                    'date' => $post->post_date,
                    'modified' => $post->post_modified,
                    'author' => $post->post_author,
                    'status' => $post->post_status,
                ];

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
                            
                            $connectors[] = [
                                'name' => $charger_term->name,
                                'slug' => $charger_term->slug,
                                'icon' => get_term_meta($charger_term->term_id, 'charger_icon', true), // Správný meta klíč pro ikony
                                'type' => get_term_meta($charger_term->term_id, 'charger_current_type', true), // Správný meta klíč pro typ proudu
                                'power' => get_term_meta($charger_term->term_id, 'power', true),
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
                        
                        
                        if (!empty($meta_connectors)) {
                            if (is_array($meta_connectors)) {
                                // Přidat počty z _db_charger_counts
                                foreach ($meta_connectors as &$connector) {
                                    if (isset($charger_counts[$connector['type']])) {
                                        $connector['quantity'] = $charger_counts[$connector['type']];
                                    }
                                }
                                $properties['connectors'] = $meta_connectors;
                                $properties['konektory'] = $meta_connectors;
                            } else {
                                // Pokud je string, zkusit parsovat jako JSON
                                $parsed = json_decode($meta_connectors, true);
                                if (is_array($parsed)) {
                                    // Přidat počty z _db_charger_counts
                                    foreach ($parsed as &$connector) {
                                        if (isset($charger_counts[$connector['type']])) {
                                            $connector['quantity'] = $charger_counts[$connector['type']];
                                        }
                                    }
                                    $properties['connectors'] = $parsed;
                                    $properties['konektory'] = $parsed;
                                }
                            }
                        }
                        
                        // Další fallback - zkusit jiné meta klíče
                        if (empty($properties['connectors'])) {
                            $ocm_connectors = get_post_meta($post->ID, '_ocm_connectors', true);
                            $charger_counts = get_post_meta($post->ID, '_db_charger_counts', true);
                            
                            if (!empty($ocm_connectors) && is_array($ocm_connectors)) {
                                // Přidat počty z _db_charger_counts
                                foreach ($ocm_connectors as &$connector) {
                                    if (isset($charger_counts[$connector['type']])) {
                                        $connector['quantity'] = $charger_counts[$connector['type']];
                                    }
                                }
                                $properties['connectors'] = $ocm_connectors;
                                $properties['konektory'] = $ocm_connectors;
                            }
                        }
                        
                        // Další fallback - zkusit _connectors
                        if (empty($properties['connectors'])) {
                            $connectors_meta = get_post_meta($post->ID, '_connectors', true);
                            
                            if (!empty($connectors_meta) && is_array($connectors_meta)) {
                                $properties['connectors'] = $connectors_meta;
                                $properties['konektory'] = $connectors_meta;
                            }
                        }
                        
                        // Další fallback - zkusit _mpo_connectors
                        if (empty($properties['connectors'])) {
                            $mpo_connectors = get_post_meta($post->ID, '_mpo_connectors', true);
                            
                            if (!empty($mpo_connectors) && is_array($mpo_connectors)) {
                                $properties['connectors'] = $mpo_connectors;
                                $properties['konektory'] = $mpo_connectors;
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
            
            error_log('DB Map REST: ' . $pt . ' - bbox: ' . $bbox_count . ', haversine: ' . $haversine_count . ', přidáno: ' . count($features));
        }

        $total_before_limit = count($features);
        if ($use_radius) {
            usort($features, function($a,$b) use($lat,$lng){
                [$alng,$alat] = $a['geometry']['coordinates'];
                [$blng,$blat] = $b['geometry']['coordinates'];
                $ad = ($alat-$lat)*($alat-$lat)+($alng-$lng)*($alng-$lng);
                $bd = ($blat-$lat)*($blat-$lat)+($blng-$lng)*($blng-$lng);
                return $ad <=> $bd;
            });
            // Aplikuj globální limit až po přesném dořezu a seřazení dle vzdálenosti
            if ($limit && count($features) > $limit) {
                $features = array_slice($features, 0, $limit);
            }
        } else {
            // V režimu "all" řadíme podle ID (nejnovější první)
            usort($features, function($a,$b){
                return $b['properties']['id'] <=> $a['properties']['id'];
            });
        }

        error_log('DB Map REST: Finální výsledek - celkem features: ' . count($features));
        if (count($features) > 0) {
            error_log('DB Map REST: První feature: ' . print_r($features[0], true));
        }

        // Přidání meta informací pro diagnostiku
        $response_data = [
            'type' => 'FeatureCollection',
            'features' => $features,
            'meta' => [
                'mode' => $use_radius ? 'radius' : 'all',
                'center' => $use_radius ? [$lat, $lng] : null,
                'radius_km' => $use_radius ? $radius_km : null,
                'count' => count($features),
                'post_types' => $types,
                'bbox_count' => $bbox_count ?? 0,
                'haversine_count' => $haversine_count ?? 0,
                'limit_used' => $use_radius ? $limit : null,
                'total_before_limit' => $use_radius ? $total_before_limit : null,
                'debug' => $debug_stats
            ]
        ];
        
        $resp = rest_ensure_response($response_data);
        $resp->header('Cache-Control','public, max-age=60');
        return $resp;
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
        $api_key = get_option('db_google_api_key'); // Uložte API klíč v WordPress options
        if (!$api_key) {
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 500));
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
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 500));
        }
        
        $places = array();
        
        // Pokud máme GPS souřadnice, použijeme Nearby Search
        if ($lat && $lng) {
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
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 500));
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
        
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            return new \WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 500));
        }
        
        // Použijeme starší verzi Google Places API (Place Details)
        $url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $args = array(
            'place_id' => $place_id,
            'fields' => 'name,formatted_address,geometry,types,place_id,formatted_phone_number,international_phone_number,website,rating,user_ratings_total,price_level,opening_hours,photos,icon,icon_background_color,icon_mask_base_uri,editorial_summary,url,vicinity,utc_offset,business_status,reviews,delivery,dine_in,takeout,serves_beer,serves_wine,serves_breakfast,serves_lunch,serves_dinner,wheelchair_accessible_entrance,curbside_pickup,reservable',
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
        error_log('Google Place Details API Response: ' . $body);
        
        if ($status_code !== 200) {
            return new \WP_Error('google_api_error', 'Google API chyba: HTTP ' . $status_code, array('status' => 500));
        }
        
        if (isset($data['error_message'])) {
            return new \WP_Error('google_api_error', 'Google API chyba: ' . $data['error_message'], array('status' => 500));
        }
        
        if ($data['status'] !== 'OK') {
            return new \WP_Error('google_api_error', 'Google API chyba: ' . $data['status'], array('status' => 500));
        }
        
        // Převedeme starší formát na nový formát pro frontend
        $result = $data['result'];
        
        // Pro fotky použijeme pouze první (titulní) fotku
        $main_photo = null;
        if (isset($result['photos']) && is_array($result['photos']) && count($result['photos']) > 0) {
            $main_photo = array(
                'photoReference' => $result['photos'][0]['photo_reference'] ?? '',
                'width' => $result['photos'][0]['width'] ?? 0,
                'height' => $result['photos'][0]['height'] ?? 0,
                'htmlAttributions' => $result['photos'][0]['html_attributions'] ?? array()
            );
        }
        
        $place_data = array(
            'placeId' => $result['place_id'],
            'displayName' => array('text' => $result['name']),
            'formattedAddress' => $result['formatted_address'] ?? '',
            'location' => array(
                'latitude' => $result['geometry']['location']['lat'],
                'longitude' => $result['geometry']['location']['lng']
            ),
            'types' => $result['types'] ?? array(),
            'nationalPhoneNumber' => $result['formatted_phone_number'] ?? '',
            'internationalPhoneNumber' => $result['international_phone_number'] ?? '',
            'websiteUri' => $result['website'] ?? '',
            'rating' => $result['rating'] ?? 0,
            'userRatingCount' => $result['user_ratings_total'] ?? 0,
            'priceLevel' => $result['price_level'] ?? '',
            'regularOpeningHours' => $result['opening_hours'] ?? null,
            'photos' => $main_photo ? array($main_photo) : array(),
            'iconUri' => $result['icon'] ?? '',
            'iconBackgroundColor' => $result['icon_background_color'] ?? '',
            'iconMaskUri' => $result['icon_mask_base_uri'] ?? '',
            'editorialSummary' => $result['editorial_summary'] ?? null,
            
            // Další dostupná data
            'url' => $result['url'] ?? '',
            'vicinity' => $result['vicinity'] ?? '',
            'utcOffset' => $result['utc_offset'] ?? '',
            'businessStatus' => $result['business_status'] ?? '',
            'reviews' => $result['reviews'] ?? array(),
            
            // Restaurační služby
            'delivery' => $result['delivery'] ?? false,
            'dineIn' => $result['dine_in'] ?? false,
            'takeout' => $result['takeout'] ?? false,
            'servesBeer' => $result['serves_beer'] ?? false,
            'servesWine' => $result['serves_wine'] ?? false,
            'servesBreakfast' => $result['serves_breakfast'] ?? false,
            'servesLunch' => $result['serves_lunch'] ?? false,
            'servesDinner' => $result['serves_dinner'] ?? false,
            
            // Přístupnost
            'wheelchairAccessibleEntrance' => $result['wheelchair_accessible_entrance'] ?? false,
            'curbsidePickup' => $result['curbside_pickup'] ?? false,
            'reservable' => $result['reservable'] ?? false,
            
            'rawData' => $result // Store full raw data
        );
        
        return rest_ensure_response($place_data);
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
} 