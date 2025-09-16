<?php
/**
 * REST API pro Nearby Places s walking distance
 * @package DobityBaterky
 */

namespace DB;

class REST_Nearby {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    public function __construct() {
        // Hooky pro background přepočet
        add_action('save_post', array($this, 'on_post_save'), 10, 2);
        add_action('delete_post', array($this, 'on_post_delete'), 10, 2);
        // Worker hooky (Action Scheduler / WP-Cron)
        add_action('db_nearby_recompute', array($this, 'run_recompute_job'), 10, 2);
    }
    
    public function register() {
        add_action('rest_api_init', array($this, 'register_routes'));
    }
    
    public function register_routes() {
        // Admin: config get/update
        register_rest_route('db/v1', '/admin/config', array(
            array(
                'methods'  => 'GET',
                'callback' => array($this, 'get_admin_config'),
                'permission_callback' => function() { return current_user_can('manage_options'); },
            ),
            array(
                'methods'  => 'POST',
                'callback' => array($this, 'update_admin_config'),
                'permission_callback' => function() { return current_user_can('manage_options'); },
            ),
        ));

        // Admin: verify ORS
        register_rest_route('db/v1', '/admin/verify/ors', array(
            'methods'  => 'POST',
            'callback' => array($this, 'verify_ors'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
        ));
        // Rychlé čtení cache
        register_rest_route('db/v1', '/nearby', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_nearby'),
            'permission_callback' => '__return_true', // Veřejný endpoint
            'args' => array(
                'origin_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('poi', 'charging_location', 'rv_spot'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'limit' => array(
                    'default' => 3,
                    'type' => 'integer',
                    'minimum' => 1,
                    'maximum' => 10,
                    'sanitize_callback' => 'absint'
                )
            )
        ));
        
        // Tichý enqueue recompute
        register_rest_route('db/v1', '/nearby/recompute', array(
            'methods' => 'POST',
            'callback' => array($this, 'post_recompute'),
            'permission_callback' => '__return_true', // Veřejný endpoint
            'args' => array(
                'origin_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('poi', 'charging_location', 'rv_spot'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'sync' => array(
                    'default' => false,
                    'type' => 'boolean',
                    'sanitize_callback' => 'rest_sanitize_boolean'
                )
            )
        ));
        
        // Routing endpoint pro DEV
        register_rest_route('db/v1', '/route', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_route'),
            'permission_callback' => '__return_true',
            'args' => array(
                'from' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'to' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'provider' => array(
                    'default' => 'ors',
                    'type' => 'string',
                    'enum' => array('ors', 'osrm'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'profile' => array(
                    'default' => 'foot-walking',
                    'type' => 'string',
                    'enum' => array('foot-walking', 'driving-car', 'cycling-regular'),
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        // Diagnostický endpoint
        register_rest_route('db/v1', '/nearby/diagnose', array(
            'methods' => 'GET',
            'callback' => array($this, 'diagnose'),
            'permission_callback' => '__return_true',
            'args' => array(
                'origin_id' => array(
                    'required' => true,
                    'type' => 'integer',
                    'sanitize_callback' => 'absint'
                ),
                'type' => array(
                    'required' => true,
                    'type' => 'string',
                    'enum' => array('poi', 'charging_location', 'rv_spot'),
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        // Routing proxy pro DEV
        register_rest_route('db/v1', '/route', array(
            'methods' => 'GET',
            'callback' => array($this, 'get_route'),
            'permission_callback' => function() { return current_user_can('manage_options'); },
            'args' => array(
                'from' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'to' => array(
                    'required' => true,
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'provider' => array(
                    'default' => 'ors',
                    'type' => 'string',
                    'enum' => array('ors', 'osrm'),
                    'sanitize_callback' => 'sanitize_text_field'
                ),
                'profile' => array(
                    'default' => 'foot-walking',
                    'type' => 'string',
                    'sanitize_callback' => 'sanitize_text_field'
                )
            )
        ));
        
        // Clear cache
        register_rest_route('db/v1', '/nearby/clear-cache', array(
            'methods' => 'POST',
            'callback' => array($this, 'clear_cache'),
            'permission_callback' => function() { return current_user_can('manage_options'); }
        ));
        
    }
    
    /**
     * GET /nearby - Rychlé čtení cache s průběžným ukládáním
     */
    public function get_nearby($request) {
        $origin_id = (int)$request->get_param('origin_id');
        $type      = $request->get_param('type'); // 'poi' | 'charging_location' | 'rv_spot'
        $limit     = max(1, (int)$request->get_param('limit'));

        $meta_key = ($type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';

        $cache     = get_post_meta($origin_id, $meta_key, true);
        $payload   = is_string($cache) ? json_decode($cache, true) : (is_array($cache) ? $cache : null);

        $ttl_days  = (int) (get_option('db_nearby_config', [])['cache_ttl_days'] ?? 30);
        $computed  = $payload['computed_at'] ?? null;
        $stale     = !$computed || (time() - strtotime($computed)) > ($ttl_days * DAY_IN_SECONDS);

        // stav recompute (lock)
        $lock_key  = $meta_key . '_lock';
        $running   = (bool) get_post_meta($origin_id, $lock_key, true);

        $items_all = (array)($payload['items'] ?? []);
        $partial   = (bool)($payload['partial'] ?? false);
        $progress  = (array)($payload['progress'] ?? ['done'=>count($items_all), 'total'=>count($items_all)]);
        $error     = $payload['error'] ?? null;
        $error_at  = isset($payload['error_at']) ? strtotime($payload['error_at']) : null;
        $retry_after_s = isset($payload['retry_after_s']) ? (int)$payload['retry_after_s'] : null;
        $next_retry_at = null;

        // pokud stale a neběží recompute, zkus ho nastartovat (async)
        // ale pokud je poslední chyba rate_limited, respektuj cooldown (např. 60s) a nespouštěj okamžitě
        $cfg_auto = (int) (get_option('db_nearby_config', [])['auto_enqueue_on_get'] ?? 0);
        if ($cfg_auto && $stale && !$running) {
            $cooldown_ok = true;
            if ($error && $error_at) {
                // Pokud máme známou chybu a retry_after_s, držíme se jí
                if ($retry_after_s) {
                    $remain = ($error_at + $retry_after_s) - time();
                    if ($remain > 0) {
                        $cooldown_ok = false;
                        $next_retry_at = date('c', $error_at + $retry_after_s);
                        $stale = false; // nepovažovat za stale, aby FE nepolloval
                    }
                } elseif ($error === 'rate_limited') {
                    $cooldown_ok = (time() - $error_at) > 120; // default 120s
                    if (!$cooldown_ok) {
                        $next_retry_at = date('c', $error_at + 120);
                        $stale = false;
                    }
                } elseif (in_array($error, ['unauthorized','missing_ors_key'], true)) {
                    $cooldown_ok = (time() - $error_at) > 6*HOUR_IN_SECONDS;
                    if (!$cooldown_ok) {
                        $next_retry_at = date('c', $error_at + 6*HOUR_IN_SECONDS);
                        $stale = false;
                    }
                }
            }
            if ($cooldown_ok) {
                $this->enqueue_recompute_async($origin_id, $type);
            }
        }

        // Pokud nemáme žádnou cache, připrav fallback výsledek vzdušnou čarou
        if (empty($items_all)) {
            $items_all = $this->build_basic_fallback($origin_id, $type);
        }

        // výstup (ořež na limit)
        $items = array_slice($items_all, 0, $limit);
        
        // Obohacení dat o ikony a metadata dynamicky
        $enriched_items = $this->enrich_nearby_items($items);

        return rest_ensure_response([
            'origin_id' => $origin_id,
            'type'      => $type,
            'stale'     => $stale,
            'partial'   => $partial,
            'running'   => $running,
            'progress'  => $progress, // ['done'=>int, 'total'=>int]
            'items'     => $enriched_items,
            'computed_at' => $computed ?: null,
            'error'     => $error,
            'error_at'  => $error_at ? date('c', $error_at) : null,
            'retry_after_s' => $retry_after_s,
            'next_retry_at' => $next_retry_at,
            'version'   => 4
        ]);
    }

    /**
     * Sestaví základní fallback položky (vzdušnou čarou) s označením direct_line=true
     */
    private function build_basic_fallback($origin_id, $type) {
        $cfg = get_option('db_nearby_config', []);
        $radiusKm = (float)($cfg['radius_km'] ?? 5);
        $maxCand  = (int)($cfg['max_candidates'] ?? 24);
        $speed    = (float)($cfg['walking_speed_m_s'] ?? 1.3);

        // Souřadnice originu
        $origin_post = get_post($origin_id);
        if (!$origin_post) return [];
        $olat = $olng = null;
        if ($origin_post->post_type === 'charging_location') {
            $olat = (float)get_post_meta($origin_id, '_db_lat', true);
            $olng = (float)get_post_meta($origin_id, '_db_lng', true);
        } elseif ($origin_post->post_type === 'poi') {
            $olat = (float)get_post_meta($origin_id, '_poi_lat', true);
            $olng = (float)get_post_meta($origin_id, '_poi_lng', true);
        } elseif ($origin_post->post_type === 'rv_spot') {
            $olat = (float)get_post_meta($origin_id, '_rv_lat', true);
            $olng = (float)get_post_meta($origin_id, '_rv_lng', true);
        }
        if (!$olat || !$olng) return [];

        // Kandidáti přes job helper
        $cands = [];
        if (class_exists('\\DB\\Jobs\\Nearby_Recompute_Job')) {
            $job = new \DB\Jobs\Nearby_Recompute_Job();
            if (method_exists($job, 'get_candidates')) {
                $cands = $job->get_candidates($olat, $olng, $type, $radiusKm, $maxCand);
            }
        }
        if (empty($cands)) return [];

        // Sestavit položky
        $items = [];
        foreach ($cands as $cand) {
            $dist_m = (int) round($this->haversine_m($olat, $olng, (float)$cand['lat'], (float)$cand['lng']));
            $dur_s  = $speed > 0 ? (int) round($dist_m / $speed) : $dist_m;
            $items[] = [
                'id'          => (int)$cand['id'],
                'post_type'   => (string)$cand['type'],
                'distance_m'  => $dist_m,
                'duration_s'  => $dur_s,
                'walk_m'      => $dist_m,
                'secs'        => $dur_s,
                'provider'    => 'basic.haversine',
                'profile'     => 'foot-walking',
                'direct_line' => true,
            ];
        }
        usort($items, function($a,$b){ return $a['duration_s'] <=> $b['duration_s']; });
        return $items;
    }

    /**
     * Haversine vzdálenost v metrech
     */
    private function haversine_m($lat1, $lng1, $lat2, $lng2) {
        $earth_km = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return (int) round($earth_km * $c * 1000);
    }

    // Admin handlers
    public function get_admin_config($request) {
        $cfg = get_option('db_nearby_config', []);
        return rest_ensure_response($cfg);
    }

    public function update_admin_config($request) {
        $data = $request->get_json_params();
        $cfg  = (array)($data['db_nearby_config'] ?? []);
        // jednoduchá sanitizace
        $provider = sanitize_text_field($cfg['provider'] ?? 'ors');
        if (!in_array($provider, ['ors','osrm','basic'], true)) { $provider = 'ors'; }
        $cfg['provider'] = $provider;
        $cfg['ors_api_key'] = sanitize_text_field($cfg['ors_api_key'] ?? '');
        $cfg['radius_km'] = floatval($cfg['radius_km'] ?? 5);
        $cfg['max_candidates'] = intval($cfg['max_candidates'] ?? 60);
        $cfg['matrix_batch_size'] = intval($cfg['matrix_batch_size'] ?? 60);
        $cfg['cache_ttl_days'] = intval($cfg['cache_ttl_days'] ?? 30);
        $cfg['walking_speed_m_s'] = isset($cfg['walking_speed_m_s']) ? floatval($cfg['walking_speed_m_s']) : 1.3;
        update_option('db_nearby_config', $cfg);
        return rest_ensure_response(['ok'=>true,'saved'=>true]);
    }

    public function verify_ors($request) {
        $cfg = get_option('db_nearby_config', []);
        $api_key = isset($cfg['ors_api_key']) ? trim((string)$cfg['ors_api_key']) : '';
        if (!$api_key) {
            return rest_ensure_response(['ok'=>false,'http_code'=>401,'message'=>'ORS key missing']);
        }
        $url = 'https://api.openrouteservice.org/v2/directions/foot-walking';
        $body = [ 'coordinates' => [ [14.42076,50.08804],[14.42150,50.08750] ] ];
        $res = wp_remote_post($url, [
            'headers' => [
                'Authorization' => $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'DobityBaterky/nearby (+https://dobitybaterky.cz)'
            ],
            'body' => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
            'timeout' => 10,
        ]);
        if (is_wp_error($res)) {
            return rest_ensure_response(['ok'=>false,'wp_error'=>$res->get_error_message()]);
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        $raw  = wp_remote_retrieve_body($res);
        return rest_ensure_response([
            'ok' => $code >= 200 && $code < 300,
            'http_code' => $code,
            'body_excerpt' => substr($raw, 0, 300)
        ]);
    }
    
    /**
     * POST /nearby/recompute - Enqueue recompute
     */
    public function post_recompute($request) {
        $origin_id = (int) ($request['origin_id'] ?? 0);
        $type      = sanitize_key($request['type'] ?? '');
        $sync      = (bool) ($request['sync'] ?? false);

        if (!$origin_id || !in_array($type, ['poi','charging_location','rv_spot'], true)) {
            return new \WP_Error('bad_request', 'Missing origin_id or type', ['status'=>400]);
        }

        if ($sync) {
            // spustíme hned (bez plánování) s lockem
            $this->run_recompute_job($origin_id, $type);
            return rest_ensure_response(['ok'=>true, 'ran'=>true]);
        }

        // async (AS/WP-Cron)
        $this->enqueue_recompute_async($origin_id, $type);
        return rest_ensure_response(['ok'=>true, 'queued'=>1]);
    }
    
    /**
     * GET /nearby/diagnose - Diagnostika
     */
    public function diagnose($request) {
        $origin_id = (int)$request['origin_id'];
        $type      = sanitize_key($request['type']);

        $cfg   = get_option('db_nearby_config', []);
        $keyOk = !empty($cfg['ors_api_key']);
        
        // Získat souřadnice podle typu origin postu
        $origin_post = get_post($origin_id);
        $lat = $lng = null;
        if ($origin_post) {
            if ($origin_post->post_type === 'charging_location') {
                $lat = (float)get_post_meta($origin_id, '_db_lat', true);
                $lng = (float)get_post_meta($origin_id, '_db_lng', true);
            } elseif ($origin_post->post_type === 'poi') {
                $lat = (float)get_post_meta($origin_id, '_poi_lat', true);
                $lng = (float)get_post_meta($origin_id, '_poi_lng', true);
            } elseif ($origin_post->post_type === 'rv_spot') {
                $lat = (float)get_post_meta($origin_id, '_rv_lat', true);
                $lng = (float)get_post_meta($origin_id, '_rv_lng', true);
            }
        }

        // zkuste 1 malý ping na ORS (bez ukládání)
        $orsStatus = null;
        if ($keyOk && $lat && $lng) {
            $res = wp_remote_get('https://api.openrouteservice.org/health', [
                'headers' => ['Authorization' => $cfg['ors_api_key']]
            ]);
            if (!is_wp_error($res)) $orsStatus = wp_remote_retrieve_response_code($res);
        }

        // kolik máme kandidátů teď
        $job   = new \DB\Jobs\Nearby_Recompute_Job();
        $cands = method_exists($job, 'get_candidates') ? $job->get_candidates($lat, $lng, $type, (float)($cfg['radius_km'] ?? 5), (int)($cfg['max_candidates'] ?? 60)) : [];

        return rest_ensure_response([
            'has_key'      => $keyOk,
            'coords_ok'    => (bool)($lat && $lng),
            'ors_health'   => $orsStatus,
            'candidates'   => count($cands),
            'config'       => [
                'radius_km'        => $cfg['radius_km'] ?? null,
                'max_candidates'   => $cfg['max_candidates'] ?? null,
                'matrix_batch_size'=> $cfg['matrix_batch_size'] ?? null,
            ]
        ]);
    }
    
    /**
     * GET /route - Routing proxy pro DEV
     */
    public function get_route($request) {
        $from = $request->get_param('from');
        $to = $request->get_param('to');
        $provider = $request->get_param('provider');
        $profile = $request->get_param('profile');
        
        // Parse coordinates
        $from_coords = explode(',', $from);
        $to_coords = explode(',', $to);
        
        if (count($from_coords) !== 2 || count($to_coords) !== 2) {
            return new \WP_Error('invalid_coords', 'Neplatné souřadnice', array('status' => 400));
        }
        
        $from_lat = floatval($from_coords[0]);
        $from_lng = floatval($from_coords[1]);
        $to_lat = floatval($to_coords[0]);
        $to_lng = floatval($to_coords[1]);
        
        // Zavolat routing API
        $result = $this->get_routing_result($from_lat, $from_lng, $to_lat, $to_lng, $provider, $profile);
        
        if (is_wp_error($result)) {
            return $result;
        }
        
        return rest_ensure_response(array(
            'distance_m' => $result['distance_m'],
            'duration_s' => $result['duration_s'],
            'provider' => $provider,
            'cached' => false
        ));
    }
    
    /**
     * POST /nearby/clear-cache - Vymazat cache
     */
    public function clear_cache($request) {
        global $wpdb;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)",
            '_db_nearby_cache_poi_foot',
            '_db_nearby_cache_charger_foot'
        ));
        
        return rest_ensure_response(array(
            'success' => true,
            'deleted' => $deleted,
            'message' => "Vymazáno {$deleted} cache záznamů"
        ));
    }
    
    
    /**
     * Enqueue recompute asynchronně
     */
    private function enqueue_recompute_async($origin_id, $type) {
        $origin_id = (int)$origin_id;
        $type = sanitize_key($type);

        // Action Scheduler, pokud je k dispozici
        if (function_exists('as_enqueue_async_action')) {
            // unikátní args = 1 úloha / origin+type
            as_enqueue_async_action('db_nearby_recompute', [
                'origin_id' => $origin_id,
                'type'      => $type,
            ], 'db-nearby');
            return true;
        }

        // Fallback na WP-Cron
        if (!wp_next_scheduled('db_nearby_recompute', [ $origin_id, $type ])) {
            wp_schedule_single_event(time() + 1, 'db_nearby_recompute', [ $origin_id, $type ]);
        }
        return true;
    }
    
    /**
     * Hook: save_post
     */
    public function on_post_save($post_id, $post) {
        if (wp_is_post_revision($post_id) || wp_is_post_autosave($post_id)) {
            return;
        }
        
        if (!in_array($post->post_type, array('charging_location', 'poi', 'rv_spot'))) {
            return;
        }
        
        // Enqueue recompute pro tento post - hledat opačný typ
        if ($post->post_type === 'charging_location') {
            $this->enqueue_recompute_async($post_id, 'poi');
        } elseif ($post->post_type === 'poi') {
            $this->enqueue_recompute_async($post_id, 'charging_location');
        } elseif ($post->post_type === 'rv_spot') {
            $this->enqueue_recompute_async($post_id, 'poi');
            $this->enqueue_recompute_async($post_id, 'charging_location');
        }
        
        // Enqueue recompute pro okolní origins (kde tento post může být cílem)
        $this->enqueue_reverse_recompute($post_id, $post->post_type);
    }
    
    /**
     * Hook: delete_post
     */
    public function on_post_delete($post_id, $post) {
        if (!in_array($post->post_type, array('charging_location', 'poi', 'rv_spot'))) {
            return;
        }
        
        // Vymazat cache tohoto postu
        delete_post_meta($post_id, '_db_nearby_cache_poi_foot');
        delete_post_meta($post_id, '_db_nearby_cache_charger_foot');
        
        // Enqueue reverse recompute
        $this->enqueue_reverse_recompute($post_id, $post->post_type);
    }
    
    /**
     * Enqueue reverse recompute pro okolní origins
     */
    private function enqueue_reverse_recompute($post_id, $post_type) {
        global $wpdb;
        // Souřadnice změněného bodu
        $lat = $lng = null;
        if ($post_type === 'charging_location') {
            $lat = (float)get_post_meta($post_id, '_db_lat', true);
            $lng = (float)get_post_meta($post_id, '_db_lng', true);
        } elseif ($post_type === 'poi') {
            $lat = (float)get_post_meta($post_id, '_poi_lat', true);
            $lng = (float)get_post_meta($post_id, '_poi_lng', true);
        } elseif ($post_type === 'rv_spot') {
            $lat = (float)get_post_meta($post_id, '_rv_lat', true);
            $lng = (float)get_post_meta($post_id, '_rv_lng', true);
        }
        if (!$lat || !$lng) return;
        // Konfigurace radiusu
        $cfg = get_option('db_nearby_config', []);
        $radius_km = (float)($cfg['radius_km'] ?? 5);
        // Naleznout protilehlé origins v okolí a frontovat
        $target_types = array('charging_location','poi');
        foreach ($target_types as $t) {
            if ($t === $post_type) continue;
            $sql = $wpdb->prepare(
                "SELECT p.ID, p.post_type, pm_lat.meta_value as lat, pm_lng.meta_value as lng
                 FROM {$wpdb->posts} p
                 LEFT JOIN {$wpdb->postmeta} pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key IN ('_db_lat','_poi_lat','_rv_lat')
                 LEFT JOIN {$wpdb->postmeta} pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key IN ('_db_lng','_poi_lng','_rv_lng')
                 WHERE p.post_status='publish' AND p.post_type=%s
                 HAVING (
                    6371 * acos(
                        cos(radians(%f)) * cos(radians(CAST(lat AS DECIMAL(10,6)))) *
                        cos(radians(CAST(lng AS DECIMAL(10,6))) - radians(%f)) +
                        sin(radians(%f)) * sin(radians(CAST(lat AS DECIMAL(10,6))))
                    ) <= %f
                 )
                 LIMIT 500",
                $t, $lat, $lng, $lat, $radius_km
            );
            $rows = $wpdb->get_results($sql);
            if ($rows) {
                foreach ($rows as $r) {
                    $opp = ($r->post_type === 'charging_location') ? 'poi' : 'charging_location';
                    $this->enqueue_recompute_async((int)$r->ID, $opp);
                }
            }
        }
    }

    /**
     * Worker: provede recompute pro origin s bezpečným lockem
     */
    public function run_recompute_job($origin_id, $type) {
        $origin_id = (int)$origin_id;
        $type = sanitize_key($type);
        if (!in_array($type, ['poi','charging_location','rv_spot'], true)) return false;

        $meta_key = ($type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';
        $lock_key = $meta_key . '_lock';
        if (get_post_meta($origin_id, $lock_key, true)) {
            return false; // Lock aktivní
        }
        update_post_meta($origin_id, $lock_key, 1);
        try {
            if (!class_exists('\\DB\\Jobs\\Nearby_Recompute_Job')) {
                return false;
            }
            $job = new \DB\Jobs\Nearby_Recompute_Job();
            $job->recompute_nearby_for_origin($origin_id, $type);
        } finally {
            delete_post_meta($origin_id, $lock_key);
        }
        return true;
    }
    
    /**
     * Získat routing výsledek
     */
    private function get_routing_result($from_lat, $from_lng, $to_lat, $to_lng, $provider, $profile) {
        $config = get_option('db_nearby_config', array(
            'provider' => 'ors',
            'ors_api_key' => '',
            'radius_poi_for_charger' => 2,
            'radius_charger_for_poi' => 5,
            'matrix_batch_size' => 25,
            'max_candidates' => 50,
            'cache_ttl_days' => 30,
            'max_jobs_per_day' => 100,
            'max_pairs_per_day' => 1000
        ));
        
        if ($provider === 'ors') {
            $orsKey = isset($config['ors_api_key']) ? trim((string)$config['ors_api_key']) : '';
            return $this->get_ors_route($from_lat, $from_lng, $to_lat, $to_lng, $profile, $orsKey);
        } else {
            return $this->get_osrm_route($from_lat, $from_lng, $to_lat, $to_lng, $profile);
        }
    }
    
    /**
     * OpenRouteService routing
     */
    private function get_ors_route($from_lat, $from_lng, $to_lat, $to_lng, $profile, $api_key) {
        if (empty($api_key)) {
            return new \WP_Error('no_api_key', 'ORS API key není nastaven');
        }
        
        $url = "https://api.openrouteservice.org/v2/directions/{$profile}";
        $body = array(
            'coordinates' => array(
                array($from_lng, $from_lat),
                array($to_lng, $to_lat)
            )
        );
        
        $response = wp_remote_post($url, array(
            'headers' => array(
                'Authorization' => $api_key,
                'Content-Type'  => 'application/json',
                'Accept'        => 'application/json',
                'User-Agent'    => 'DobityBaterky/nearby (+https://dobitybaterky.cz)'
            ),
            'body' => json_encode($body),
            'timeout' => 10
        ));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['features'][0]['properties']['summary'])) {
            $summary = $data['features'][0]['properties']['summary'];
            return array(
                'distance_m' => $summary['distance'],
                'duration_s' => $summary['duration']
            );
        }
        
        return new \WP_Error('ors_error', 'ORS API chyba: ' . ($data['error']['message'] ?? 'Neznámá chyba'));
    }
    
    /**
     * OSRM routing
     */
    private function get_osrm_route($from_lat, $from_lng, $to_lat, $to_lng, $profile) {
        $url = "http://router.project-osrm.org/route/v1/{$profile}/{$from_lng},{$from_lat};{$to_lng},{$to_lat}?overview=false";
        
        $response = wp_remote_get($url, array('timeout' => 10));
        
        if (is_wp_error($response)) {
            return $response;
        }
        
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['routes'][0])) {
            $route = $data['routes'][0];
            return array(
                'distance_m' => $route['distance'],
                'duration_s' => $route['duration']
            );
        }
        
        return new \WP_Error('osrm_error', 'OSRM API chyba: ' . ($data['message'] ?? 'Neznámá chyba'));
    }
    
    /**
     * Rate limiting
     */
    private function check_rate_limit() {
        $ip = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
        $key = 'db_nearby_rate_' . md5($ip);
        $count = get_transient($key) ?: 0;
        
        if ($count > 1000) { // 1000 requests per hour (zvýšeno pro testování)
            return false;
        }
        
        set_transient($key, $count + 1, HOUR_IN_SECONDS);
        return true;
    }
    
    /**
     * Kontrola denních kvót
     */
    private function check_daily_quotas() {
        $today = date('Y-m-d');
        $jobs_key = 'db_nearby_jobs_' . $today;
        $pairs_key = 'db_nearby_pairs_' . $today;
        
        $jobs_count = get_transient($jobs_key) ?: 0;
        $pairs_count = get_transient($pairs_key) ?: 0;
        
        $config = get_option('db_nearby_config', array(
            'provider' => 'ors',
            'ors_api_key' => '',
            'radius_poi_for_charger' => 2,
            'radius_charger_for_poi' => 5,
            'matrix_batch_size' => 25,
            'max_candidates' => 50,
            'cache_ttl_days' => 30,
            'max_jobs_per_day' => 100,
            'max_pairs_per_day' => 1000
        ));
        $max_jobs = $config['max_jobs_per_day'] ?? 100;
        $max_pairs = $config['max_pairs_per_day'] ?? 1000;
        
        $result = $jobs_count < $max_jobs && $pairs_count < $max_pairs;
        error_log("[DB-Nearby] check_daily_quotas: jobs={$jobs_count}/{$max_jobs}, pairs={$pairs_count}/{$max_pairs}, result=" . ($result ? 'true' : 'false'));
        
        return $result;
    }
    
    /**
     * Obohacení nearby items o ikony a metadata dynamicky
     * Používá stejnou logiku jako REST_Map.php
     */
    private function enrich_nearby_items($items) {
        $enriched = [];
        
        foreach ($items as $item) {
            $post = get_post($item['id']);
            if (!$post) {
                continue;
            }
            
            // Načtení ikon a barev pomocí Icon_Registry (stejně jako v REST_Map.php)
            $icon_registry = \DB\Icon_Registry::get_instance();
            $icon_data = $icon_registry->get_icon($post);
            
            // Základní properties (stejně jako v REST_Map.php)
            $properties = [
                'id' => $post->ID,
                'post_type' => $item['post_type'],
                'title' => get_the_title($post),
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
                'amenities' => get_post_meta($post->ID, '_db_amenities', true),
                'access' => get_post_meta($post->ID, '_db_access', true),
                'opening_hours' => get_post_meta($post->ID, '_db_opening_hours', true),
                'price' => get_post_meta($post->ID, '_db_price', true),
                'description' => get_post_meta($post->ID, '_db_description', true),
            ];
            
            // Přidat souřadnice
            $lat = get_post_meta($post->ID, '_db_lat', true);
            $lng = get_post_meta($post->ID, '_db_lng', true);
            if ($lat && $lng) {
                $properties['lat'] = (float)$lat;
                $properties['lng'] = (float)$lng;
            }
            
            // Zachovat původní nearby data
            $enriched_item = array_merge($item, $properties);
            $enriched[] = $enriched_item;
        }
        
        return $enriched;
    }
}
