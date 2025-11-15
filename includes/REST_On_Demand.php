<?php
/**
 * REST API pro on-demand zpracování
 * @package DobityBaterky
 */

namespace DB;

use DB\Jobs\On_Demand_Processor;

class REST_On_Demand {
    
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
        // Test endpoint - ověřit, že REST API funguje
        register_rest_route('db/v1', '/ondemand/test', array(
            'methods' => 'POST',
            'callback' => function($request) {
                return rest_ensure_response(array('status' => 'ok', 'message' => 'REST API funguje'));
            },
            'permission_callback' => '__return_true'
        ));
        
        // On-demand zpracování bodu
        register_rest_route('db/v1', '/ondemand/process', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_point'),
            'permission_callback' => array($this, 'check_ondemand_permission'),
            'args' => array(
                'point_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'point_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('charging_location', 'poi', 'rv_spot'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'token' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        // Kontrola stavu zpracování
        register_rest_route('db/v1', '/ondemand/status/(?P<point_id>\d+)', array(
            'methods' => 'GET',
            'callback' => array($this, 'check_status'),
            'permission_callback' => array($this, 'check_ondemand_permission'), // Uživatelé s oprávněním k mapě
            'args' => array(
                'point_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                )
            )
        ));
        
        // Synchronní zpracování (pro admin/testování)
        register_rest_route('db/v1', '/ondemand/sync', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_sync'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args' => array(
                'point_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'point_type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('charging_location', 'poi', 'rv_spot'),
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        // Generování tokenu pro on-demand zpracování
        register_rest_route('db/v1', '/ondemand/token', array(
            'methods' => 'POST',
            'callback' => array($this, 'generate_token'),
            'permission_callback' => array($this, 'check_ondemand_permission'),
            'args' => array(
                'point_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                )
            )
        ));
    }
    
    /**
     * Kontrola oprávnění pro on-demand zpracování
     * Umožňuje anonymní přístup - validace se provádí přes token v callback
     */
    public function check_ondemand_permission($request) {
        // Veřejný endpoint - token validace proběhne v callback funkci
        return true;
    }
    
    /**
     * Zpracovat bod na požádání
     */
    public function process_point($request) {
        $point_id = $request->get_param('point_id');
        $point_type = $request->get_param('point_type');
        $token = $request->get_param('token');
        
        // Veřejný přístup s 'frontend-trigger' tokenem (pro anonymní uživatele)
        if ($token === 'frontend-trigger') {
            // Rate limiting pro veřejný přístup - max 1 request za 1 sekundu na IP
            // Zkráceno z 5s na 1s, aby neblokovalo opakované pokusy
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $rate_limit_key = 'db_ondemand_rate_' . md5($ip . $point_id);
            $last_request = get_transient($rate_limit_key);
            
            if ($last_request !== false) {
                return new \WP_Error('rate_limit', 'Příliš mnoho požadavků. Zkuste to za chvíli.', array('status' => 429));
            }
            
            // Nastavit rate limit - 1 sekunda
            set_transient($rate_limit_key, time(), 1);
        } else {
            // Validace tokenu pro přihlášené uživatele
            $stored_token = get_transient('db_ondemand_token_' . $point_id);
            
            if (!$stored_token || !hash_equals($stored_token, $token)) {
                return new \WP_Error('unauthorized', 'Neplatný token', array('status' => 403));
            }
        }
        
        // Zpracovat bod
        $processor = new On_Demand_Processor();
        $result = $processor->process_point($point_id, $point_type);
        
        return rest_ensure_response($result);
    }
    
    /**
     * Zkontrolovat stav zpracování
     */
    public function check_status($request) {
        $point_id = $request->get_param('point_id');
        $point_type = $request->get_param('type');
        
        if (!$point_id) {
            return new \WP_Error('missing_point_id', 'Point ID is required', array('status' => 400));
        }
        
        $status = On_Demand_Processor::check_processing_status($point_id, $point_type);
        
        return rest_ensure_response($status);
    }
    
    /**
     * Generování tokenu pro on-demand zpracování
     */
    public function generate_token($request) {
        $point_id = $request->get_param('point_id');
        
        // Generovat nový token
        $token = wp_generate_password(24, false, false);
        
        // Uložit token na 5 minut
        set_transient('db_ondemand_token_' . $point_id, $token, 300);
        
        return rest_ensure_response(array(
            'token' => $token,
            'expires_in' => 300,
            'point_id' => $point_id
        ));
    }
    
    /**
     * Synchronní zpracování (admin)
     */
    public function process_sync($request) {
        $point_id = $request->get_param('point_id');
        $point_type = $request->get_param('point_type');
        
        $result = On_Demand_Processor::process_point_sync($point_id, $point_type);
        
        return rest_ensure_response($result);
    }
}
