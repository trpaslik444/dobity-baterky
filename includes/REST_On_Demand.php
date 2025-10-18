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
        // On-demand zpracování bodu
        register_rest_route('db/v1', '/ondemand/process', array(
            'methods' => 'POST',
            'callback' => array($this, 'process_point'),
            'permission_callback' => '__return_true', // Veřejný endpoint
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
            'permission_callback' => '__return_true',
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
    }
    
    /**
     * Zpracovat bod na požádání
     */
    public function process_point($request) {
        $point_id = $request->get_param('point_id');
        $point_type = $request->get_param('point_type');
        $token = $request->get_param('token');
        
        // Pro frontend volání s tokenem 'frontend-trigger' přeskočit ověření
        if ($token !== 'frontend-trigger') {
            // Ověřit token pro admin volání
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
     * Synchronní zpracování (admin)
     */
    public function process_sync($request) {
        $point_id = $request->get_param('point_id');
        $point_type = $request->get_param('point_type');
        
        $result = On_Demand_Processor::process_point_sync($point_id, $point_type);
        
        return rest_ensure_response($result);
    }
}
