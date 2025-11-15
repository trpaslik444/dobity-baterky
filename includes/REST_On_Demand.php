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
     * Vyžaduje buď přístup k mapě (early adopter/admin) nebo validní anonymní token
     */
    public function check_ondemand_permission($request) {
        // Přihlášení uživatelé s přístupem k mapě mají povolen přístup
        if ( is_user_logged_in() && function_exists( 'db_user_can_see_map' ) && db_user_can_see_map() ) {
            return true;
        }
        
        // Status endpoint: povolen pro všechny (jen čtení stavu, nezpracovává data)
        // Status endpoint pouze kontroluje, zda jsou data zpracovaná - není to citlivá operace
        if ( $request instanceof \WP_REST_Request && strpos( $request->get_route(), '/status/' ) !== false ) {
            return true; // Povolit anonymní přístup ke status endpointu
        }
        
        // Pro anonymní přístup k process endpointu: pouze pokud request obsahuje validní token
        if ( $request instanceof \WP_REST_Request ) {
            // V permission_callback může být JSON body ještě neparsované
            // Zkusit získat z různých zdrojů
            $token = null;
            $point_id = null;
            
            // Zkusit z get_param (funguje po parsování args)
            $token = $request->get_param( 'token' );
            $point_id = $request->get_param( 'point_id' );
            
            // Pokud nejsou v get_param, zkusit z JSON body ručně
            if ( ! $token || ! $point_id ) {
                $body = $request->get_body();
                if ( ! empty( $body ) ) {
                    $body_params = json_decode( $body, true );
                    if ( is_array( $body_params ) ) {
                        $token = $token ?? ( $body_params['token'] ?? null );
                        $point_id = $point_id ?? ( $body_params['point_id'] ?? null );
                    }
                }
            }
            
            // Frontend-trigger token je povolen pouze s rate limitingem (kontrola proběhne v callback)
            // Tento token může použít kdokoli, ale má silný rate limiting
            if ( $token === 'frontend-trigger' && $point_id ) {
                // Rate limiting: kontrola proběhne v process_point callback
                // Zde pouze povolíme request pokračovat
                return true;
            }
            
            // Validní token pro konkrétní bod (generovaný uživatelem s přístupem)
            if ( $token && $point_id ) {
                $stored_token = get_transient( 'db_ondemand_token_' . $point_id );
                if ( $stored_token && hash_equals( $stored_token, $token ) ) {
                    return true;
                }
            }
        }
        
        // Výchozí: zamítnout přístup
        return false;
    }
    
    /**
     * Zpracovat bod na požádání
     */
    public function process_point($request) {
        $point_id = $request->get_param('point_id');
        $point_type = $request->get_param('point_type');
        $token = $request->get_param('token');
        
        // Frontend-trigger token: povolen pouze s silným rate limitingem
        // Tento token umožňuje anonymní přístup, ale má ochranu proti zneužití
        if ($token === 'frontend-trigger') {
            $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
            $current_time = time();
            
            // Rate limiting na úrovni bodu: min 2 sekundy mezi requesty na stejný bod
            $rate_limit_key = 'db_ondemand_rate_' . md5($ip . $point_id);
            $last_request = get_transient($rate_limit_key);
            
            if ($last_request !== false) {
                $time_since = $current_time - (int) $last_request;
                if ($time_since < 2) {
                    return new \WP_Error(
                        'rate_limit',
                        'Data se načítají. Zkuste to za chvíli.',
                        array(
                            'status' => 429,
                            'retry_after' => 2 - $time_since,
                            'message_type' => 'loading'
                        )
                    );
                }
            }
            
            // Nastavit rate limit - 2 sekundy
            set_transient($rate_limit_key, $current_time, 2);
            
            // Burst rate limiting: povolíme více rychlých requestů, ale pak pomaleji
            // Prvních 10 requestů rychle (burst), pak max 1 za 3 sekundy
            $ip_rate_key = 'db_ondemand_ip_rate_' . md5($ip);
            $ip_rate_data = get_transient($ip_rate_key);
            
            if ($ip_rate_data === false) {
                $ip_rate_data = array(
                    'count' => 0,
                    'window_start' => $current_time,
                    'last_request' => 0
                );
            } else {
                $ip_rate_data = json_decode($ip_rate_data, true);
                if (!is_array($ip_rate_data)) {
                    $ip_rate_data = array(
                        'count' => 0,
                        'window_start' => $current_time,
                        'last_request' => 0
                    );
                }
            }
            
            // Reset okna po 60 sekundách
            if ($current_time - $ip_rate_data['window_start'] >= 60) {
                $ip_rate_data = array(
                    'count' => 0,
                    'window_start' => $current_time,
                    'last_request' => 0
                );
            }
            
            // Burst limit: prvních 15 requestů v burstu (rychle)
            $burst_limit = 15;
            
            // Pokud je v burst módu
            if ($ip_rate_data['count'] < $burst_limit) {
                // V burst módu - povolíme rychlé requesty
                $time_since_last = $current_time - (int) $ip_rate_data['last_request'];
                if ($time_since_last < 1) {
                    // I v burst módu min 1 sekunda mezi requesty
                    return new \WP_Error(
                        'rate_limit',
                        'Data se načítají. Zkuste to za chvíli.',
                        array(
                            'status' => 429,
                            'retry_after' => 1 - $time_since_last,
                            'message_type' => 'loading'
                        )
                    );
                }
            } else {
                // Po burst módu - pomalejší rate limiting (1 request za 3 sekundy)
                $time_since_last = $current_time - (int) $ip_rate_data['last_request'];
                if ($time_since_last < 3) {
                    return new \WP_Error(
                        'rate_limit',
                        'Data se načítají pomaleji. Zkuste to za chvíli.',
                        array(
                            'status' => 429,
                            'retry_after' => 3 - $time_since_last,
                            'message_type' => 'slowing'
                        )
                    );
                }
            }
            
            // Zvýšit počet requestů a aktualizovat čas
            $ip_rate_data['count'] = (int) $ip_rate_data['count'] + 1;
            $ip_rate_data['last_request'] = $current_time;
            
            // Uložit (TTL 60 sekund)
            set_transient($ip_rate_key, wp_json_encode($ip_rate_data), 60);
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
     * Vyžaduje přístup k mapě (early adopter/admin) - token nelze generovat anonymně
     */
    public function generate_token($request) {
        // Kontrola, zda má uživatel přístup k mapě
        if ( ! is_user_logged_in() || ! function_exists( 'db_user_can_see_map' ) || ! db_user_can_see_map() ) {
            return new \WP_Error(
                'unauthorized',
                'K generování tokenu je potřeba přístup k mapové aplikaci.',
                array( 'status' => 403 )
            );
        }
        
        $point_id = $request->get_param('point_id');
        
        if ( ! $point_id ) {
            return new \WP_Error(
                'missing_point_id',
                'Point ID je povinný.',
                array( 'status' => 400 )
            );
        }
        
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
