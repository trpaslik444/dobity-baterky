<?php

namespace DB\Jobs;

class Nearby_Recompute_Job {
    
    public function __construct() {
        // Registrace hooku s podporou pro oba formáty (AS: assoc array, WP-Cron: dva scalary)
        add_action('db_nearby_recompute', function($arg1, $arg2 = null) {
            // Podpora pro oba formáty (AS: assoc array, WP-Cron: dva scalary)
            if (is_array($arg1)) {
                $origin_id = (int)($arg1['origin_id'] ?? 0);
                $type      = (string)($arg1['type'] ?? '');
            } else {
                $origin_id = (int)$arg1;
                $type      = (string)$arg2;
            }
            (new self())->recompute_nearby_for_origin($origin_id, $type);
        }, 10, 2);
    }
    
    /**
     * Zpracovat nearby data pro batch processor
     */
    public function process_nearby_data($origin_id, $type, $candidates) {
        try {
            $meta_key = ($type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';
            
            // Načíst origin souřadnice podle typu postu
            $origin_post = get_post($origin_id);
            if (!$origin_post) {
                return array('success' => false, 'error' => 'Origin bod nenalezen');
            }
            
            $origin_lat = $origin_lng = null;
            if ($origin_post->post_type === 'charging_location') {
                $origin_lat = (float)get_post_meta($origin_id, '_db_lat', true);
                $origin_lng = (float)get_post_meta($origin_id, '_db_lng', true);
            } elseif ($origin_post->post_type === 'poi') {
                $origin_lat = (float)get_post_meta($origin_id, '_poi_lat', true);
                $origin_lng = (float)get_post_meta($origin_id, '_poi_lng', true);
            } elseif ($origin_post->post_type === 'rv_spot') {
                $origin_lat = (float)get_post_meta($origin_id, '_rv_lat', true);
                $origin_lng = (float)get_post_meta($origin_id, '_rv_lng', true);
            }
            
            if (!$origin_lat || !$origin_lng) {
                return array('success' => false, 'error' => 'Origin bod nemá souřadnice');
            }
            
            // Získat konfiguraci
            $cfg = get_option('db_nearby_config', []);
            $orsKey = trim((string)($cfg['ors_api_key'] ?? ''));
            $provider = (string)($cfg['provider'] ?? 'ors');
            $profile = 'foot-walking';
            $batch_size = (int)($cfg['matrix_batch_size'] ?? 24);
            
            if (empty($orsKey) && $provider === 'ors') {
                return array('success' => false, 'error' => 'ORS API key není nastaven');
            }
            
            $items = array();
            $total = count($candidates);
            
            // Zpracovat po dávkách
            for ($i = 0; $i < $total; $i += $batch_size) {
                $chunk = array_slice($candidates, $i, $batch_size);
                $chunk_result = $this->process_chunk($chunk, $orsKey, $provider, $profile, $origin_lat, $origin_lng);
                
                if (!$chunk_result['success']) {
                    return $chunk_result;
                }
                
                $items = array_merge($items, $chunk_result['items']);
                
                // Pauza mezi dávkami
                if ($i + $batch_size < $total) {
                    usleep(150000); // 150ms
                }
            }
            
            // Uložit výsledky
            $this->write_cache($origin_id, $meta_key, $items, false, count($items), count($items), current_time('c'), null);

            // Současně stáhnout a uložit isochrones stejně jako v recompute_nearby_for_origin
            if (!empty($orsKey) && $provider === 'ors') {
                $this->fetch_and_cache_isochrones($origin_id, $origin_lat, $origin_lng, $orsKey);
            }
            
            // Přidat do zpracovaných míst
            $this->track_processed_location($origin_id, $type, $candidates, $api_calls_used, $processing_time);
            
            return array('success' => true, 'items_count' => count($items));
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Track zpracované místo v databázi
     */
    private function track_processed_location($origin_id, $origin_type, $candidates, $api_calls_used, $processing_time) {
        try {
            $processed_manager = new \DB\Jobs\Nearby_Processed_Manager();
            
            // Získat velikost cache
            $meta_key = ($origin_type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';
            $cache_data = get_post_meta($origin_id, $meta_key, true);
            $cache_size_kb = $cache_data ? strlen(serialize($cache_data)) / 1024 : 0;
            
            $stats = array(
                'candidates_count' => count($candidates),
                'api_calls' => $api_calls_used,
                'processing_time' => $processing_time,
                'api_provider' => 'ors', // TODO: určit dynamicky
                'cache_size_kb' => round($cache_size_kb),
                'status' => 'completed'
            );
            
            // origin_type zde reprezentuje cílový typ (co zpracováváme). Do processed ukládáme reálný typ origin postu.
            $real_origin_type = get_post_type($origin_id) ?: $origin_type;
            $processed_type = ($origin_type === 'poi') ? 'poi_foot' : 'charger_foot';
            $processed_manager->add_processed($origin_id, $real_origin_type, $processed_type, $stats);
            
        } catch (Exception $e) {
            error_log("[DB Processed Tracking] Chyba při trackingu: " . $e->getMessage());
        }
    }
    
    /**
     * Hlavní funkce pro recompute nearby places přes ORS Matrix API
     */
    public function recompute_nearby_for_origin($origin_id, $type) {
        $type = sanitize_key($type);
        $meta_key = ($type === 'poi') ? '_db_nearby_cache_poi_foot' : (($type === 'rv_spot') ? '_db_nearby_cache_rv_foot' : '_db_nearby_cache_charger_foot');
        $lock_key = $meta_key . '_lock';
        $transient_lock_key = 'db_nearby_lock_' . $origin_id . '_' . $type;

        // LOCK – jediný běh
        if (!add_post_meta($origin_id, $lock_key, 1, true)) {
            error_log("[DB Nearby] lock exists for #$origin_id/$type");
            return;
        }
        // Transient lock na několik minut, aby se nespouštělo opakovaně z více míst
        if (get_transient($transient_lock_key)) {
            error_log("[DB Nearby] transient lock active for #$origin_id/$type");
            delete_post_meta($origin_id, $lock_key); // uvolnit post_meta lock, když transient brání běhu
            return;
        }
        set_transient($transient_lock_key, 1, 5 * MINUTE_IN_SECONDS);
        
        try {
            $cfg = get_option('db_nearby_config', []);
            
            $orsKey    = trim((string)($cfg['ors_api_key'] ?? ''));
            $provider  = (string)($cfg['provider'] ?? 'ors');
            $profile   = 'foot-walking'; // Vždy používáme pěší trasu pro všechny typy
            $radiusKm  = (float)($cfg['radius_km'] ?? 5);
            $maxCand   = (int)($cfg['max_candidates'] ?? 60);
            // U type=poi (typicky klik na nabíječku) držet menší dávky
            $batchSize = (int)($cfg['matrix_batch_size'] ?? 60);
            if ($type === 'poi') {
                $batchSize = min($batchSize, 30);
            }

            // Získat souřadnice origin postu podle jeho typu
            $origin_post = get_post($origin_id);
            if (!$origin_post) {
                error_log("[DB Nearby] origin post #$origin_id not found");
                $this->write_cache($origin_id, $meta_key, [], false, 0, 0, null, 'origin_not_found');
                return;
            }

            // Guard: nikdy nezpracovávej stejný typ jako je origin; přemapuj na protitip
            // charging_location => poi (a/nebo rv_spot), poi => charging_location, rv_spot => charging_location (základní)
            if ($type === $origin_post->post_type) {
                if ($type === 'charging_location') { $type = 'poi'; $meta_key = '_db_nearby_cache_poi_foot'; }
                elseif ($type === 'poi') { $type = 'charging_location'; $meta_key = '_db_nearby_cache_charger_foot'; }
                elseif ($type === 'rv_spot') { $type = 'charging_location'; $meta_key = '_db_nearby_cache_charger_foot'; }
            }
            
            $lat = $lng = null;
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

            if (!$lat || !$lng) {
                error_log("[DB Nearby] missing coords for #$origin_id");
                $this->write_cache($origin_id, $meta_key, [], false, 0, 0, null, 'missing_coords');
                return;
            }
            // Pokud je zvolen basic provider, nebo chybí ORS klíč, použijeme jednoduchý režim
            if ($provider === 'basic' || !$orsKey) {
                $items = [];
                $candidates = $this->get_candidates($lat, $lng, $type, $radiusKm, $maxCand);
                $total = count($candidates);
                $speed = (float)($cfg['walking_speed_m_s'] ?? 1.3); // default ~4.7 km/h
                foreach ($candidates as $cand) {
                    $distance_m = isset($cand['dist_km']) ? (int) round(((float)$cand['dist_km']) * 1000) : (int) round($this->haversine_m($lat, $lng, (float)$cand['lat'], (float)$cand['lng']));
                    $duration_s = $speed > 0 ? (int) round($distance_m / $speed) : (int) $distance_m; // fallback bez dělení
                    $items[] = [
                        'id'         => (int)$cand['id'],
                        'post_type'  => (string)$cand['type'],
                        'duration_s' => $duration_s,
                        'distance_m' => $distance_m,
                        'walk_m'     => $distance_m,
                        'secs'       => $duration_s,
                        'provider'   => 'basic.haversine',
                        'profile'    => $profile,
                    ];
                }
                usort($items, fn($a,$b) => ($a['duration_s'] <=> $b['duration_s']));
                $this->write_cache($origin_id, $meta_key, $items, false, $total, $total, current_time('c'), null);
                return;
            }

            // 1) Kandidáti (Haversine; vyber typ protilehlý vůči originu)
            $candidates = $this->get_candidates($lat, $lng, $type, $radiusKm, $maxCand);
            $total = count($candidates);

            if ($total === 0) {
                $this->write_cache($origin_id, $meta_key, [], false, 0, 0, current_time('c'), null);
                return;
            }

            // 2) ORS Matrix po batších
            $items = [];
            $done  = 0;

            for ($i=0; $i<$total; $i += $batchSize) {
                $chunk = array_slice($candidates, $i, $batchSize);

                $locations = [ [ (float)$lng, (float)$lat ] ];
                foreach ($chunk as $c) {
                    $locations[] = [ (float)$c['lng'], (float)$c['lat'] ];
                }

                $body = [
                    'locations'    => $locations,
                    'sources'      => [0],
                    'destinations' => range(1, count($chunk)),
                    'metrics'      => ['distance','duration']
                ];

                // Zkontrolovat lokální minutový limit
                $quota_manager = new \DB\Jobs\API_Quota_Manager();
                $minute_check = $quota_manager->check_minute_limit();
                
                if (!$minute_check['allowed']) {
                    error_log("[DB Nearby] Lokální minutový limit. Počkej {$minute_check['wait_seconds']}s");
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, 'rate_limited');
                    return;
                }
                
                $res = wp_remote_post("https://api.openrouteservice.org/v2/matrix/{$profile}", [
                    'headers' => [
                        'Authorization' => $orsKey,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                        'User-Agent'    => 'DobityBaterky/nearby (+https://dobitybaterky.cz)'
                    ],
                    'body'    => wp_json_encode($body, JSON_UNESCAPED_UNICODE),
                    'timeout' => 20
                ]);

                if (is_wp_error($res)) {
                    error_log("[DB Nearby] ORS error: ".$res->get_error_message());
                    // uložíme, ale partial zůstane true => GET ukáže stale/partial
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, 'wp_error');
                    return;
                }

                $code = (int) wp_remote_retrieve_response_code($res);
                $headers = wp_remote_retrieve_headers($res);
                $json = json_decode(wp_remote_retrieve_body($res), true);
                
                // Uložit kvóty z hlaviček
                $quota_manager = new \DB\Jobs\API_Quota_Manager();
                $quota_manager->save_ors_headers($headers);

                if ($code === 401 || $code === 403) {
                    error_log("[DB Nearby] ORS unauthorized (key invalid?) code={$code}");
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, current_time('c'), 'unauthorized', 6 * HOUR_IN_SECONDS);
                    return;
                }
                if ($code === 429) {
                    error_log("[DB Nearby] ORS rate limit (429) – partial save and stop");
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, current_time('c'), 'rate_limited', 120);
                    // Naplánovat opakování s backoffem (např. za 2 minuty)
                    if (!wp_next_scheduled('db_nearby_recompute', [$origin_id, $type])) {
                        wp_schedule_single_event(time() + 120, 'db_nearby_recompute', [$origin_id, $type]);
                    }
                    return;
                }
                if ($code < 200 || $code >= 300 || empty($json['durations'])) {
                    error_log("[DB Nearby] ORS bad response: ".$code);
                    continue; // přeskočíme batch, ale běžíme dál
                }

                $dur = $json['durations'][0];
                $dist= $json['distances'][0] ?? array_fill(0, count($chunk), null);

                foreach ($chunk as $idx => $cand) {
                    // Uložit pouze základní data - ikony a metadata se načtou dynamicky
                    $items[] = [
                        'id'         => (int)$cand['id'],
                        'post_type'  => (string)$cand['type'],
                        'duration_s' => (int) round($dur[$idx] ?? -1),
                        'distance_m' => (int) round($dist[$idx] ?? -1),
                        // aliasy pro FE zpětná kompatibilita
                        'walk_m'     => (int) round($dist[$idx] ?? -1),
                        'secs'       => (int) round($dur[$idx] ?? -1),
                        'provider'   => 'ors.matrix',
                        'profile'    => $profile,
                    ];
                }

                $done += count($chunk);

                // průběžně ukládat
                $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, null);

                // Lehký throttling mezi dávkami, aby se nevytočila kvóta
                usleep(150000); // 150 ms
            }

            // 3) finální uložení
            usort($items, fn($a,$b) => ($a['duration_s'] <=> $b['duration_s']));
            $this->write_cache($origin_id, $meta_key, $items, false, $total, $total, current_time('c'), null);

            // 4) Načíst isochrones data současně s nearby data
            $this->fetch_and_cache_isochrones($origin_id, $lat, $lng, $orsKey);

        } catch (\Throwable $e) {
            error_log("[DB Nearby] Exception: ".$e->getMessage());
            $this->write_cache($origin_id, $meta_key, [], true, 0, 0, null, 'exception');
        } finally {
            delete_post_meta($origin_id, $lock_key);
            delete_transient($transient_lock_key);
        }
    }
    
    /**
     * Načíst a uložit isochrones data současně s nearby data
     */
    private function fetch_and_cache_isochrones($origin_id, $lat, $lng, $orsKey) {
        try {
            $cfg = get_option('db_nearby_config', []);
            $profile = 'foot-walking';
            $ranges = [552, 1124, 1695]; // Vypočítané časy pro realistické 10, 20, 30 min
            
            // Zkontrolovat, zda už máme isochrones data
            $meta_key = 'db_isochrones_v1_' . $profile;
            $existing_cache = get_post_meta($origin_id, $meta_key, true);
            
            if ($existing_cache && !empty($existing_cache)) {
                $payload = is_string($existing_cache) ? json_decode($existing_cache, true) : $existing_cache;
                // Pokud máme platná data a nejsou starší než TTL, nepřenačítat
                if ($payload && isset($payload['geojson']) && isset($payload['geojson']['features']) && 
                    !empty($payload['geojson']['features']) && !isset($payload['error'])) {
                    $ttl_days = 30; // Default TTL
                    $computed_at = isset($payload['computed_at']) ? strtotime($payload['computed_at']) : 0;
                    if ((time() - $computed_at) < ($ttl_days * DAY_IN_SECONDS)) {
                        return; // Data jsou ještě platná
                    }
                }
            }
            
            // Zkontrolovat lokální minutový limit
            $quota_manager = new \DB\Jobs\API_Quota_Manager();
            $minute_check = $quota_manager->check_minute_limit();
            
            if (!$minute_check['allowed']) {
                error_log("[DB Isochrones] Lokální minutový limit. Přeskočeno pro origin_id {$origin_id}");
                return;
            }
            
            $body = [
                'locations' => [[(float)$lng, (float)$lat]],
                'range' => $ranges,
                'range_type' => 'time'
            ];
            
            $response = wp_remote_post("https://api.openrouteservice.org/v2/isochrones/{$profile}", [
                'headers' => [
                    'Authorization' => $orsKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/geo+json;charset=UTF-8',
                    'User-Agent' => 'DobityBaterky/isochrones (+https://dobitybaterky.cz)'
                ],
                'body' => json_encode($body),
                'timeout' => 30
            ]);
            
            if (is_wp_error($response)) {
                error_log("[DB Isochrones] Network error for origin_id {$origin_id}: " . $response->get_error_message());
                return;
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);
            
            // Uložit kvóty z hlaviček
            $headers = wp_remote_retrieve_headers($response);
            $quota_manager->save_ors_headers($headers);
            
            if ($http_code === 401 || $http_code === 403) {
                error_log("[DB Isochrones] Unauthorized for origin_id {$origin_id}");
                $this->set_isochrones_error($origin_id, $profile, 'unauthorized', 'API key invalid');
                return;
            }
            
            if ($http_code === 429) {
                error_log("[DB Isochrones] Rate limited for origin_id {$origin_id}");
                $this->set_isochrones_error($origin_id, $profile, 'rate_limited', 'Rate limited');
                return;
            }
            
            if ($http_code !== 200) {
                error_log("[DB Isochrones] HTTP error {$http_code} for origin_id {$origin_id}");
                $this->set_isochrones_error($origin_id, $profile, 'upstream_error', "HTTP $http_code: " . ($data['error']['message'] ?? 'Unknown error'));
                return;
            }
            
            // Úspěch - uložit data
            $payload = [
                'version' => 1,
                'profile' => $profile,
                'ranges_s' => $ranges,
                'center' => [(float)$lng, (float)$lat],
                'geojson' => $data,
                'computed_at' => current_time('c'),
                'ttl_days' => 30,
                'error' => null,
                'error_at' => null,
                'retry_after_s' => null,
                'running' => false
            ];
            
            update_post_meta($origin_id, $meta_key, json_encode($payload));
            error_log("[DB Isochrones] Successfully cached for origin_id {$origin_id}, features: " . count($data['features'] ?? []));
            
        } catch (\Throwable $e) {
            error_log("[DB Isochrones] Exception for origin_id {$origin_id}: " . $e->getMessage());
        }
    }
    
    /**
     * Nastavit chybu v isochrones cache
     */
    private function set_isochrones_error($origin_id, $profile, $error_type, $message, $retry_after = null) {
        $meta_key = 'db_isochrones_v1_' . $profile;
        $cfg = get_option('db_nearby_config', []);
        
        $payload = [
            'version' => 1,
            'profile' => $profile,
            'ranges_s' => [552, 1124, 1695],
            'center' => null,
            'geojson' => null,
            'computed_at' => current_time('c'),
            'ttl_days' => 30,
            'error' => $error_type,
            'error_at' => current_time('c'),
            'retry_after_s' => $retry_after,
            'running' => false
        ];
        
        update_post_meta($origin_id, $meta_key, json_encode($payload));
    }
    
    /**
     * Získat kandidáty pomocí Haversine SQL dotazu
     */
    public function get_candidates($lat, $lng, $type, $radiusKm, $limit) {
        global $wpdb;

        // type='poi' znamená hledat POI pro origin (může být charging_location nebo rv_spot)
        // type='charging_location' znamená hledat charging_location pro origin (může být poi nebo rv_spot)
        // type='rv_spot' znamená hledat RV spots pro origin (může být poi nebo charging_location)
        if ($type === 'poi') {
            $target_type = 'poi';
        } elseif ($type === 'charging_location') {
            $target_type = 'charging_location';
        } elseif ($type === 'rv_spot') {
            $target_type = 'rv_spot';
        } else {
            $target_type = 'charging_location'; // fallback
        }

        // Určit správné meta klíče podle typu
        if ($target_type === 'charging_location') {
            $lat_key = '_db_lat';
            $lng_key = '_db_lng';
        } elseif ($target_type === 'poi') {
            $lat_key = '_poi_lat';
            $lng_key = '_poi_lng';
        } elseif ($target_type === 'rv_spot') {
            $lat_key = '_rv_lat';
            $lng_key = '_rv_lng';
        } else {
            $lat_key = '_db_lat';
            $lng_key = '_db_lng';
        }
        
        $sql = $wpdb->prepare("
            SELECT p.ID as id,
                   lat_pm.meta_value+0 AS lat,
                   lng_pm.meta_value+0 AS lng,
                   p.post_type,
                   p.post_title as name,
                   (6371 * ACOS(
                       COS(RADIANS(%f)) * COS(RADIANS(lat_pm.meta_value+0)) *
                       COS(RADIANS(lng_pm.meta_value+0) - RADIANS(%f)) +
                       SIN(RADIANS(%f)) * SIN(RADIANS(lat_pm.meta_value+0))
                   )) AS dist_km
            FROM {$wpdb->posts} p
            JOIN {$wpdb->postmeta} lat_pm ON lat_pm.post_id = p.ID AND lat_pm.meta_key = %s
            JOIN {$wpdb->postmeta} lng_pm ON lng_pm.post_id = p.ID AND lng_pm.meta_key = %s
            WHERE p.post_type = %s AND p.post_status='publish'
            AND lat_pm.meta_value != '' AND lng_pm.meta_value != ''
            HAVING dist_km <= %f
            ORDER BY dist_km ASC
            LIMIT %d
        ", $lat, $lng, $lat, $lat_key, $lng_key, $target_type, $radiusKm, $limit);

        $rows = $wpdb->get_results($sql, ARRAY_A);
        return array_map(function($r){
            return [
                'id'   => (int)$r['id'],
                'lat'  => (float)$r['lat'],
                'lng'  => (float)$r['lng'],
                'type' => $r['post_type'],
                'name' => (string)$r['name'],
                'dist_km' => isset($r['dist_km']) ? (float)$r['dist_km'] : null,
            ];
        }, $rows ?: []);
    }
    
    /**
     * Zápis cache s error handlingem
     */
    private function write_cache($origin_id, $meta_key, array $items, bool $partial, int $done, int $total, ?string $computed_at, ?string $error, ?int $retry_after_s = null) {
        $payload = [
            'computed_at' => $computed_at,
            'items'       => array_values($items),
            'partial'     => $partial,
            'progress'    => ['done'=>$done,'total'=>$total],
        ];
        if ($error) {
            $payload['error'] = $error;
            $payload['error_at'] = current_time('c');
            if ($retry_after_s) {
                $payload['retry_after_s'] = (int)$retry_after_s;
            }
        }
        update_post_meta($origin_id, $meta_key, wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    /**
     * Jednoduchý Haversine výpočet vzdálenosti v metrech
     */
    private function haversine_m($lat1, $lng1, $lat2, $lng2) {
        $earth_radius_km = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        return (int) round($earth_radius_km * $c * 1000);
    }
    
    /**
     * Zpracovat dávku kandidátů
     */
    private function process_chunk($chunk, $orsKey, $provider, $profile, $origin_lat, $origin_lng) {
        try {
            if ($provider === 'ors') {
                return $this->process_ors_chunk($chunk, $orsKey, $profile, $origin_lat, $origin_lng);
            } else {
                return $this->process_osrm_chunk($chunk, $profile, $origin_lat, $origin_lng);
            }
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Zpracovat dávku přes ORS Matrix API
     */
    private function process_ors_chunk($chunk, $orsKey, $profile, $origin_lat, $origin_lng) {
        // Origin bod je vždy na indexu 0
        $locations = array(array((float)$origin_lng, (float)$origin_lat));
        
        // Přidat kandidáty
        foreach ($chunk as $cand) {
            $locations[] = array((float)$cand->lng, (float)$cand->lat);
        }
        
        $body = array(
            'locations' => $locations,
            'sources' => array(0), // Origin bod je vždy na indexu 0
            'destinations' => range(1, count($chunk)), // Kandidáti jsou od indexu 1
            'metrics' => array('distance', 'duration')
        );
        
        // Zkontrolovat lokální minutový limit
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        $minute_check = $quota_manager->check_minute_limit();
        
        if (!$minute_check['allowed']) {
            return array('success' => false, 'error' => "Lokální minutový limit. Počkej {$minute_check['wait_seconds']}s");
        }
        
        $response = wp_remote_post("https://api.openrouteservice.org/v2/matrix/{$profile}", array(
            'headers' => array(
                'Authorization' => $orsKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/batch (+https://dobitybaterky.cz)'
            ),
            'body' => json_encode($body),
            'timeout' => 20
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $code = (int)wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        // Uložit kvóty z hlaviček
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        $quota_manager->save_ors_headers($headers);
        
        // Obsluha limitů
        if ($code === 429) {
            $retry_after = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 10;
            return array('success' => false, 'error' => "ORS minute limit hit. Retry after {$retry_after}s");
        }
        
        if ($code === 401 || $code === 403) {
            return array('success' => false, 'error' => 'ORS unauthorized/daily quota exhausted');
        }
        
        if ($code < 200 || $code >= 300 || empty($data['durations'])) {
            return array('success' => false, 'error' => 'ORS bad response: ' . $code);
        }
        
        $durations = $data['durations'][0];
        $distances = $data['distances'][0] ?? array_fill(0, count($chunk), null);
        
        $items = array();
        foreach ($chunk as $idx => $cand) {
            $items[] = array(
                'id' => (int)$cand->id,
                'post_type' => (string)$cand->type,
                'duration_s' => (int)round($durations[$idx] ?? -1),
                'distance_m' => (int)round($distances[$idx] ?? -1),
                'provider' => 'ors.matrix',
                'profile' => $profile,
            );
        }
        
        return array('success' => true, 'items' => $items);
    }
    
    /**
     * Zpracovat dávku přes OSRM Matrix API
     */
    private function process_osrm_chunk($chunk, $profile, $origin_lat, $origin_lng) {
        // Origin bod je vždy na indexu 0
        $locations = array(array((float)$origin_lng, (float)$origin_lat));
        
        // Přidat kandidáty
        foreach ($chunk as $cand) {
            $locations[] = array((float)$cand->lng, (float)$cand->lat);
        }
        
        // OSRM Matrix API endpoint
        $url = "https://router.project-osrm.org/table/v1/{$profile}";
        
        // Připravit souřadnice pro OSRM (lng,lat formát)
        $coords = array();
        foreach ($locations as $loc) {
            $coords[] = $loc[0] . ',' . $loc[1]; // lng,lat
        }
        
        $url .= '?' . http_build_query(array(
            'sources' => '0',
            'destinations' => implode(';', range(1, count($chunk))),
            'annotations' => 'distance,duration'
        ));
        
        // Přidat souřadnice na konec URL
        $url .= '&' . implode(';', $coords);
        
        $response = wp_remote_get($url, array(
            'timeout' => 20,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/osrm (+https://dobitybaterky.cz)'
            )
        ));
        
        if (is_wp_error($response)) {
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $code = (int)wp_remote_retrieve_response_code($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if ($code < 200 || $code >= 300 || empty($data['durations'])) {
            return array('success' => false, 'error' => 'OSRM bad response: ' . $code);
        }
        
        $durations = $data['durations'][0];
        $distances = $data['distances'][0] ?? array_fill(0, count($chunk), null);
        
        $items = array();
        foreach ($chunk as $idx => $cand) {
            $items[] = array(
                'id' => (int)$cand->id,
                'post_type' => (string)$cand->type,
                'duration_s' => (int)round($durations[$idx] ?? -1),
                'distance_m' => (int)round($distances[$idx] ?? -1),
                'provider' => 'osrm.matrix',
                'profile' => $profile,
            );
        }
        
        return array('success' => true, 'items' => $items);
    }
    
    /**
     * Zpracovat jednu položku pro testování
     */
    public function process_single_item($queue_id) {
        global $wpdb;
        
        // Načíst položku z fronty
        $queue_item = $wpdb->get_row($wpdb->prepare("
            SELECT * FROM {$wpdb->prefix}nearby_queue WHERE id = %d
        ", $queue_id));
        
        if (!$queue_item) {
            return array('success' => false, 'error' => 'Položka nenalezena');
        }
        
        // Načíst kandidáty
        $candidates = $this->fetch_candidates($queue_item->origin_id, $queue_item->origin_type);
        
        if (empty($candidates)) {
            return array('success' => false, 'error' => 'Žádní kandidáti nenalezeni');
        }
        
        // Zpracovat nearby data
        $result = $this->process_nearby_data($queue_item->origin_id, $queue_item->origin_type, $candidates);
        
        if ($result['success']) {
            // Označit jako zpracované
            $wpdb->update(
                $wpdb->prefix . 'nearby_queue',
                array('status' => 'completed'),
                array('id' => $queue_id)
            );
            
            return array(
                'success' => true,
                'processed' => count($candidates),
                'api_calls' => $result['api_calls'] ?? 1,
                'message' => 'Položka úspěšně zpracována'
            );
        }
        
        return $result;
    }
    
    /**
     * Načíst kandidáty pro origin bod
     */
    private function fetch_candidates($origin_id, $origin_type) {
        global $wpdb;
        
        // Načíst souřadnice origin bodu
        $origin = $wpdb->get_row($wpdb->prepare("
            SELECT p.*, 
                CASE 
                    WHEN p.post_type = 'charging_location' THEN pm_lat.meta_value
                    WHEN p.post_type = 'poi' THEN pm_lat.meta_value
                    WHEN p.post_type = 'rv_spot' THEN pm_lat.meta_value
                END as lat,
                CASE 
                    WHEN p.post_type = 'charging_location' THEN pm_lng.meta_value
                    WHEN p.post_type = 'poi' THEN pm_lng.meta_value
                    WHEN p.post_type = 'rv_spot' THEN pm_lng.meta_value
                END as lng
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')
            LEFT JOIN {$wpdb->postmeta} pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key IN ('_db_lng', '_poi_lng', '_rv_lng')
            WHERE p.ID = %d
        ", $origin_id));
        
        if (!$origin || !$origin->lat || !$origin->lng) {
            return array();
        }
        
        // Určit typ kandidátů: zde origin_type reprezentuje CÍLOVÝ typ, který chceme hledat
        // (fronta ukládá, co se má k originu počítat: 'poi' => hledej POI; 'charging_location' => hledej nabíječky)
        $candidate_type = in_array($origin_type, ['poi','charging_location','rv_spot'], true)
            ? $origin_type
            : 'charging_location';
        
        // Načíst kandidáty v okolí
        $candidates = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID as id, p.post_title, p.post_type as type,
                CASE 
                    WHEN p.post_type = 'charging_location' THEN pm_lat.meta_value
                    WHEN p.post_type = 'poi' THEN pm_lat.meta_value
                    WHEN p.post_type = 'rv_spot' THEN pm_lat.meta_value
                END as lat,
                CASE 
                    WHEN p.post_type = 'charging_location' THEN pm_lng.meta_value
                    WHEN p.post_type = 'poi' THEN pm_lng.meta_value
                    WHEN p.post_type = 'rv_spot' THEN pm_lng.meta_value
                END as lng
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')
            LEFT JOIN {$wpdb->postmeta} pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key IN ('_db_lng', '_poi_lng', '_rv_lng')
            WHERE p.post_status = 'publish' 
                AND p.post_type = %s
                AND p.ID != %d
                AND pm_lat.meta_value IS NOT NULL 
                AND pm_lng.meta_value IS NOT NULL
                AND (
                    6371000 * acos(
                        cos(radians(%f)) * cos(radians(pm_lat.meta_value)) * 
                        cos(radians(pm_lng.meta_value) - radians(%f)) + 
                        sin(radians(%f)) * sin(radians(pm_lat.meta_value))
                    )
                ) <= 10000
            ORDER BY (
                6371000 * acos(
                    cos(radians(%f)) * cos(radians(pm_lat.meta_value)) * 
                    cos(radians(pm_lng.meta_value) - radians(%f)) + 
                    sin(radians(%f)) * sin(radians(pm_lat.meta_value))
                )
            )
            LIMIT 50
        ", $candidate_type, $origin_id, $origin->lat, $origin->lng, $origin->lat, $origin->lat, $origin->lng, $origin->lat));
        
        return $candidates;
    }
}