<?php
/**
 * REST API pro Isochrony
 * @package DobityBaterky
 */

namespace DB;

use DB\Jobs\API_Quota_Manager;

class REST_Isochrones {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function register() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        // Isochrony endpoint
        register_rest_route('db/v1', '/isochrones', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_isochrones'),
            'permission_callback' => '__return_true', // Veřejný endpoint
            'args' => array(
                'lat' => array(
                    'required' => true,
                    'type' => 'number',
                    'minimum' => -90,
                    'maximum' => 90,
                    'sanitize_callback' => 'floatval'
                ),
                'lng' => array(
                    'required' => true,
                    'type' => 'number',
                    'minimum' => -180,
                    'maximum' => 180,
                    'sanitize_callback' => 'floatval'
                ),
                'profile' => array(
                    'default' => 'foot-walking',
                    'type' => 'string',
                    'enum' => array('foot-walking', 'driving-car', 'cycling-regular'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'range' => array(
                    'default' => '600,1200,1800',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
    }
    
    /**
     * GET /isochrones - Získat isochrony pro dané souřadnice
     */
    public function get_isochrones($request) {
        $lat = $request->get_param('lat');
        $lng = $request->get_param('lng');
        $profile = $request->get_param('profile');
        $range_string = $request->get_param('range');
        
        // Parse range string (e.g., "600,1200,1800")
        $ranges = array_map('intval', explode(',', $range_string));
        $ranges = array_filter($ranges, function($r) { return $r > 0; });
        
        if (empty($ranges)) {
            return new \WP_Error('invalid_range', 'Invalid range parameter', array('status' => 400));
        }
        
        // Cache key
        $cache_key = 'db_isochrones_' . md5($lat . '_' . $lng . '_' . $profile . '_' . implode(',', $ranges));
        
        // Zkontrolovat cache
        $cached = wp_cache_get($cache_key, 'db_isochrones');
        if ($cached !== false) {
            return rest_ensure_response(array_merge($cached, array('cached' => true)));
        }
        
        // Získat konfiguraci
        $config = get_option('db_nearby_config', array());
        $provider = $config['provider'] ?? 'ors';
        $ors_key = $config['ors_api_key'] ?? '';
        
        if ($provider === 'ors' && empty($ors_key)) {
            return new \WP_Error('missing_api_key', 'ORS API key not configured', array('status' => 500));
        }
        
        // Zkontrolovat kvóty
        $quota_manager = new API_Quota_Manager();
        $quota_check = $quota_manager->check_available_quota();
        
        if (!$quota_check['can_process']) {
            return new \WP_Error('quota_exceeded', $quota_check['error'] ?? 'API quota exceeded', array(
                'status' => 429,
                'retry_after' => $quota_check['reset_at'] ?? null
            ));
        }
        
        // Zkontrolovat minutový limit
        $minute_check = $quota_manager->check_minute_limit('isochrones');
        if (!$minute_check['allowed']) {
            return new \WP_Error('rate_limited', 'Rate limited', array(
                'status' => 429,
                'retry_after' => $minute_check['wait_seconds'] ?? 60
            ));
        }
        
        // Volat ORS API
        $result = $this->call_ors_isochrones($lat, $lng, $profile, $ranges, $ors_key);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        // Uložit do cache (30 minut)
        wp_cache_set($cache_key, $result, 'db_isochrones', 30 * 60);
        
        return rest_ensure_response(array_merge($result, array('cached' => false)));
    }
    
    /**
     * Volat ORS isochrones API
     */
    private function call_ors_isochrones($lat, $lng, $profile, $ranges, $api_key) {
        $body = array(
            'locations' => array(array($lng, $lat)),
            'range' => $ranges,
            'range_type' => 'time'
        );
        
        $response = wp_remote_post("https://api.openrouteservice.org/v2/isochrones/{$profile}", array(
            'headers' => array(
                'Authorization' => $api_key,
                'Content-Type' => 'application/json',
                'Accept' => 'application/geo+json;charset=UTF-8',
                'User-Agent' => 'DobityBaterky/isochrones (+https://dobitybaterky.cz)'
            ),
            'body' => json_encode($body),
            'timeout' => 30
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('api_error', $response->get_error_message(), array('status' => 500));
        }
        
        $http_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($http_code !== 200) {
            $error_data = json_decode($response_body, true);
            $error_message = $error_data['error']['message'] ?? 'Unknown API error';
            
            return new \WP_Error('api_error', $error_message, array(
                'status' => $http_code,
                'api_response' => $response_body
            ));
        }
        
        $data = json_decode($response_body, true);
        
        if (!$data || !isset($data['features'])) {
            return new \WP_Error('invalid_response', 'Invalid API response', array('status' => 500));
        }
        
        // Zpracovat response
        $geojson = array(
            'type' => 'FeatureCollection',
            'features' => array()
        );
        
        foreach ($data['features'] as $feature) {
            if (isset($feature['properties']['value'])) {
                $geojson['features'][] = array(
                    'type' => 'Feature',
                    'geometry' => $feature['geometry'],
                    'properties' => array(
                        'value' => $feature['properties']['value']
                    )
                );
            }
        }
        
        return array(
            'geojson' => $geojson,
            'ranges' => $ranges,
            'profile' => $profile,
            'computed_at' => current_time('c'),
            'user_settings' => array(
                'enabled' => true,
                'walking_speed_kmh' => 4.5
            )
        );
    }
}
