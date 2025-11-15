<?php

namespace DB\Jobs;

class Nearby_Recompute_Job {

    private function debug_log($message, array $context = []) {
        Nearby_Logger::log('RECOMPUTE', $message, $context);
    }

    private function truncate_body($body) {
        if (!is_string($body)) {
            $body = wp_json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }
        if ($body === false || $body === null) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($body, 0, 500) : substr($body, 0, 500);
    }

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
            $start_time = microtime(true);
            $matrix_calls = 0;
            $iso_calls = 0;
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
            $original_total = $total;
            $matrix_limit = max(1, API_Quota_Manager::ORS_MATRIX_MAX_LOCATIONS - 1);

            if ($total > $matrix_limit) {
                $this->debug_log('[Matrix] candidate limit enforced', [
                    'origin_id' => $origin_id,
                    'type' => $type,
                    'original_total' => $original_total,
                    'limited_to' => $matrix_limit
                ]);
                $candidates = array_slice($candidates, 0, $matrix_limit);
                $total = count($candidates);
            }

            $batch_size = max(1, min($batch_size, $total));

            // Zpracovat po dávkách
            for ($i = 0; $i < $total; $i += $batch_size) {
                $chunk = array_slice($candidates, $i, $batch_size);
                $normalized_chunk = $this->normalize_matrix_candidates($chunk);
                $chunk_index = (int) floor($i / $batch_size);
                $chunk_result = $this->process_chunk($normalized_chunk, $orsKey, $provider, $profile, $origin_lat, $origin_lng, $origin_id, $chunk_index);

                if (!$chunk_result['success']) {
                    if (!empty($chunk_result['retry_after_s'])) {
                        $chunk_result['api_calls'] = array('matrix' => $matrix_calls, 'isochrones' => $iso_calls);
                    }
                    return $chunk_result;
                }

                $items = array_merge($items, $chunk_result['items']);
                $matrix_calls++;

                // Pauza mezi dávkami
                if ($i + $batch_size < $total) {
                    usleep(150000); // 150ms
                }
            }
            
            // Uložit výsledky
            $this->write_cache($origin_id, $meta_key, $items, false, count($items), count($items), current_time('c'), null);

            // Současně stáhnout a uložit isochrones stejně jako v recompute_nearby_for_origin
            if (!empty($orsKey) && $provider === 'ors') {
                if ($this->fetch_and_cache_isochrones($origin_id, $origin_lat, $origin_lng, $orsKey)) {
                    $iso_calls++;
                }
            }

            // Přidat do zpracovaných míst
            $processing_time = round(microtime(true) - $start_time, 3);
            $api_calls_used = array('matrix' => $matrix_calls, 'isochrones' => $iso_calls);
            $this->track_processed_location($origin_id, $type, $candidates, $api_calls_used, $processing_time, $provider);

            return array(
                'success' => true,
                'items_count' => count($items),
                'api_calls' => $api_calls_used,
                'processing_time_s' => $processing_time
            );

        } catch (\Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Track zpracované místo v databázi
     */
    private function track_processed_location($origin_id, $origin_type, $candidates, $api_calls_used, $processing_time, $provider = 'ors') {
        try {
            $processed_manager = new \DB\Jobs\Nearby_Processed_Manager();
            
            // Získat velikost cache
            $meta_key = ($origin_type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';
            $cache_raw = get_post_meta($origin_id, $meta_key, true);
            $cache_size_kb = 0;
            $nearby_items = 0;
            if (!empty($cache_raw)) {
                if (is_string($cache_raw)) {
                    $cache_size_kb = strlen($cache_raw) / 1024;
                    $cache_payload = json_decode($cache_raw, true);
                } else {
                    $cache_size_kb = strlen(serialize($cache_raw)) / 1024;
                    $cache_payload = $cache_raw;
                }
                if (is_array($cache_payload) && isset($cache_payload['items']) && is_array($cache_payload['items'])) {
                    $nearby_items = count($cache_payload['items']);
                }
            }

            $iso_payload_raw = get_post_meta($origin_id, 'db_isochrones_v1_foot-walking', true);
            $iso_payload = is_string($iso_payload_raw) ? json_decode($iso_payload_raw, true) : (is_array($iso_payload_raw) ? $iso_payload_raw : array());
            $iso_features = isset($iso_payload['geojson']['features']) && is_array($iso_payload['geojson']['features']) ? count($iso_payload['geojson']['features']) : 0;
            $iso_calls = isset($api_calls_used['isochrones']) ? (int)$api_calls_used['isochrones'] : 0;
            if ($iso_calls === 0 && $iso_features > 0) {
                $iso_calls = 1;
            }
            
            $stats = array(
                'candidates_count' => count($candidates),
                'api_calls' => $api_calls_used,
                'processing_time' => $processing_time,
                'api_provider' => $provider ?: 'ors',
                'cache_size_kb' => round($cache_size_kb),
                'status' => 'completed',
                'error_message' => null,
                'nearby_items' => $nearby_items,
                'iso_features' => $iso_features,
                'iso_calls' => $iso_calls
            );
            
            // origin_type zde reprezentuje cílový typ (co zpracováváme). Do processed ukládáme reálný typ origin postu.
            $real_origin_type = get_post_type($origin_id) ?: $origin_type;
            $processed_type = ($origin_type === 'poi') ? 'poi_foot' : 'charger_foot';
            $processed_manager->add_processed($origin_id, $real_origin_type, $processed_type, $stats);
            
        } catch (\Exception $e) {
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
            
            // KONTROLA: Zkontrolovat, zda už máme fresh data v databázi - pokud ano, NEPROVÁDĚT recompute
            $existing_cache = get_post_meta($origin_id, $meta_key, true);
            if ($existing_cache) {
                $payload = is_string($existing_cache) ? json_decode($existing_cache, true) : $existing_cache;
                
                // Pokud máme data bez chyby
                if ($payload && !isset($payload['error'])) {
                    $computed_at = isset($payload['computed_at']) ? strtotime($payload['computed_at']) : null;
                    $has_items = !empty($payload['items']) && is_array($payload['items']) && count($payload['items']) > 0;
                    $is_partial = (bool)($payload['partial'] ?? false);
                    
                    // Pokud máme items a data nejsou stale, NEPROVÁDĚT recompute
                    if ($has_items && $computed_at && !$is_partial) {
                        $ttl_days = (int)($cfg['cache_ttl_days'] ?? 30);
                        $is_stale = (time() - $computed_at) > ($ttl_days * DAY_IN_SECONDS);
                        
                        if (!$is_stale) {
                            error_log("[DB Nearby] Data already exist and are fresh for #$origin_id/$type, skipping recompute");
                            delete_post_meta($origin_id, $lock_key);
                            delete_transient($transient_lock_key);
                            return;
                        }
                    }
                }
            }
            
            $orsKey    = trim((string)($cfg['ors_api_key'] ?? ''));
            $provider  = (string)($cfg['provider'] ?? 'ors');
            $profile   = 'foot-walking'; // Vždy používáme pěší trasu pro všechny typy
            $radiusKm  = (float)($cfg['radius_km'] ?? 5);
            $maxCand   = (int)($cfg['max_candidates'] ?? 60);
            // Zvýšit implicitní batch pro Matrix, aby se minimalizoval počet requestů
            $batchSize = (int)($cfg['matrix_batch_size'] ?? 1000);
            
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
                    $duration_s = $speed > 0 ? (int) round($distance_m / $speed) : (int) $distance_m;
                    $name = $this->resolve_candidate_name($cand);
                    $items[] = [
                        'id'         => (int)$cand['id'],
                        'post_type'  => (string)$cand['type'],
                        'name'       => $name,
                        'title'      => $name,
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
                $api_calls_used = array('matrix' => 0, 'isochrones' => 0);
                $processing_time = 0;
                $this->track_processed_location($origin_id, $type, $candidates, $api_calls_used, $processing_time, 'basic');
                return;
            }

            // 1) Kandidáti (Haversine; vyber typ protilehlý vůči originu)
            $candidates = $this->get_candidates($lat, $lng, $type, $radiusKm, $maxCand);
            $total = count($candidates);
            $original_total = $total;
            $matrix_limit = max(1, API_Quota_Manager::ORS_MATRIX_MAX_LOCATIONS - 1);

            if ($total > $matrix_limit) {
                $this->debug_log('[Matrix] candidate limit enforced', [
                    'origin_id' => $origin_id,
                    'type' => $type,
                    'original_total' => $original_total,
                    'limited_to' => $matrix_limit,
                    'context' => 'recompute'
                ]);
                $candidates = array_slice($candidates, 0, $matrix_limit);
                $total = count($candidates);
            }

            if ($total === 0) {
                $this->write_cache($origin_id, $meta_key, [], false, 0, 0, current_time('c'), null);
                return;
            }

            // 2) ORS Matrix po batších
            $items = [];
            $done  = 0;
            $batchSize = max(1, min($batchSize, $total));

            for ($i=0; $i<$total; $i += $batchSize) {
                $chunk = array_slice($candidates, $i, $batchSize);
                $chunk_index = (int) floor($i / $batchSize);
                $dest_ids = array_values(array_filter(array_map(function($cand) {
                    return isset($cand['id']) ? (int)$cand['id'] : null;
                }, $chunk), function($value) {
                    return $value !== null;
                }));

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
                $body_json = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

                $this->debug_log('[Matrix] preparing request', [
                    'origin_id' => $origin_id,
                    'type' => $type,
                    'chunk_index' => $chunk_index,
                    'chunk_size' => count($chunk),
                    'destination_ids' => $dest_ids,
                    'context' => 'recompute'
                ]);

                $quota_manager = new \DB\Jobs\API_Quota_Manager();
                $minute_check = $quota_manager->check_minute_limit('matrix');

                if (!$minute_check['allowed']) {
                    $this->debug_log('[Matrix] token bucket blocked', [
                        'origin_id' => $origin_id,
                        'chunk_index' => $chunk_index,
                        'wait_seconds' => $minute_check['wait_seconds'] ?? null,
                        'tokens_before' => $minute_check['tokens_before'] ?? null,
                        'tokens_after' => $minute_check['tokens_after'] ?? null
                    ]);
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, 'rate_limited');
                    return;
                }

                $this->debug_log('[Matrix] sending request', [
                    'origin_id' => $origin_id,
                    'chunk_index' => $chunk_index,
                    'minute_tokens_after' => $minute_check['tokens_after'] ?? null,
                    'body_excerpt' => $this->truncate_body($body_json)
                ]);

                $res = wp_remote_post("https://api.openrouteservice.org/v2/matrix/{$profile}", [
                    'headers' => [
                        'Authorization' => $orsKey,
                        'Content-Type'  => 'application/json',
                        'Accept'        => 'application/json',
                        'User-Agent'    => 'DobityBaterky/nearby (+https://dobitybaterky.cz)'
                    ],
                    'body'    => $body_json,
                    'timeout' => 20
                ]);

                if (is_wp_error($res)) {
                    $this->debug_log('[Matrix] wp_remote_post error', [
                        'origin_id' => $origin_id,
                        'chunk_index' => $chunk_index,
                        'error' => $res->get_error_message()
                    ]);
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, 'wp_error');
                    return;
                }

                $code = (int) wp_remote_retrieve_response_code($res);
                $headers = wp_remote_retrieve_headers($res);
                $json = json_decode(wp_remote_retrieve_body($res), true);
                $remaining_header = isset($headers['x-ratelimit-remaining']) ? (int)$headers['x-ratelimit-remaining'] : null;
                $retry_after_header = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;

                $this->debug_log('[Matrix] response received', [
                    'origin_id' => $origin_id,
                    'chunk_index' => $chunk_index,
                    'http_code' => $code,
                    'ratelimit_remaining' => $remaining_header,
                    'retry_after' => $retry_after_header
                ]);

                $quota_manager = new \DB\Jobs\API_Quota_Manager();
                $quota_manager->save_ors_headers($headers, 'matrix');

                if ($code === 401 || $code === 403) {
                    $response_body = wp_remote_retrieve_body($res);
                    $error_detail = json_decode($response_body, true);
                    $this->debug_log('[Matrix] unauthorized', [
                        'origin_id' => $origin_id,
                        'chunk_index' => $chunk_index,
                        'http_code' => $code,
                        'response_body' => $this->truncate_body($response_body),
                        'error_detail' => $error_detail
                    ]);
                    
                    // Pokud ORS API nefunguje, zkusit fallback na basic provider
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, current_time('c'), 'unauthorized', 6 * HOUR_IN_SECONDS);
                    
                    // Fallback na basic provider - použít Haversine výpočet bez ORS API
                    // Použít pouze pokud je to první chunk a ještě nebyly zpracovány žádné items
                    if ($done === 0 && empty($items)) {
                        // Zkusit použít basic provider pro všechny kandidáty
                        $basic_items = [];
                        $speed = (float)(get_option('db_nearby_config', [])['walking_speed_m_s'] ?? 1.3);
                        foreach ($candidates as $cand) {
                            $distance_m = (int) round($this->haversine_m($lat, $lng, (float)$cand['lat'], (float)$cand['lng']));
                            $duration_s = $speed > 0 ? (int) round($distance_m / $speed) : (int) $distance_m;
                            $name = $this->resolve_candidate_name($cand);
                            $basic_items[] = [
                                'id'         => (int)$cand['id'],
                                'post_type'  => (string)$cand['type'],
                                'name'       => $name,
                                'title'      => $name,
                                'duration_s' => $duration_s,
                                'distance_m' => $distance_m,
                                'walk_m'     => $distance_m,
                                'secs'       => $duration_s,
                                'provider'   => 'basic.haversine.fallback',
                                'profile'    => $profile,
                            ];
                        }
                        usort($basic_items, fn($a,$b) => ($a['duration_s'] <=> $b['duration_s']));
                        $this->write_cache($origin_id, $meta_key, $basic_items, false, count($basic_items), count($basic_items), current_time('c'), null);
                        // Uvolnit locky
                        delete_post_meta($origin_id, $lock_key);
                        delete_transient($transient_lock_key);
                        return;
                    }
                    return;
                }
                if ($code === 429) {
                    $this->debug_log('[Matrix] rate limited', [
                        'origin_id' => $origin_id,
                        'chunk_index' => $chunk_index,
                        'retry_after' => $retry_after_header
                    ]);
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, current_time('c'), 'rate_limited', 120);
                $manager = new \DB\Jobs\API_Quota_Manager();
                $retry_until = $manager->get_retry_until();
                $delay = $retry_until ? max(120, ($retry_until - time()) + 30 * MINUTE_IN_SECONDS) : 120;

                if (!Nearby_Cron_Tools::schedule_recompute($delay, $origin_id, $type)) {
                    // fallback do fronty, aby se neztratila práce
                    try {
                        $queue = new Nearby_Queue_Manager();
                        $queue->enqueue($origin_id, $type, 2);
                    } catch (\Throwable $__) {
                            // tichý fallback – chyba už je zalogována v debug logu
                        }
                    }
                    return;
                }
                if ($code < 200 || $code >= 300 || empty($json['durations'])) {
                    $this->debug_log('[Matrix] unexpected response', [
                        'origin_id' => $origin_id,
                        'chunk_index' => $chunk_index,
                        'http_code' => $code,
                        'body_excerpt' => $this->truncate_body(wp_json_encode($json))
                    ]);
                    continue;
                }

                $dur = $json['durations'][0];
                $dist= $json['distances'][0] ?? array_fill(0, count($chunk), null);

                foreach ($chunk as $idx => $cand) {
                    $name = $this->resolve_candidate_name($cand);
                    $items[] = [
                        'id'         => (int)$cand['id'],
                        'post_type'  => (string)$cand['type'],
                        'name'       => $name,
                        'title'      => $name,
                        'duration_s' => (int) round($dur[$idx] ?? -1),
                        'distance_m' => (int) round($dist[$idx] ?? -1),
                        'walk_m'     => (int) round($dist[$idx] ?? -1),
                        'secs'       => (int) round($dur[$idx] ?? -1),
                        'provider'   => 'ors.matrix',
                        'profile'    => $profile,
                    ];
                }

                $this->debug_log('[Matrix] chunk processed', [
                    'origin_id' => $origin_id,
                    'chunk_index' => $chunk_index,
                    'items_added' => count($chunk),
                    'tokens_remaining' => $minute_check['tokens_after'] ?? null,
                    'ratelimit_remaining' => $remaining_header
                ]);

                $done += count($chunk);

                $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, null);

                usleep(150000); // 150 ms
            }

            // 3) finální uložení
            usort($items, fn($a,$b) => ($a['duration_s'] <=> $b['duration_s']));
            $this->write_cache($origin_id, $meta_key, $items, false, $total, $total, current_time('c'), null);

            $this->debug_log('[Matrix] recompute completed', [
                'origin_id' => $origin_id,
                'type' => $type,
                'total_candidates' => $total,
                'saved_items' => count($items),
                'batch_size' => $batchSize
            ]);

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
     *
     * @return bool True pokud proběhl HTTP request na ORS API.
     */
    private function fetch_and_cache_isochrones($origin_id, $lat, $lng, $orsKey) {
        $did_call = false;
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
                        return false; // Data jsou ještě platná
                    }
                }
            }

            // Zkontrolovat lokální minutový limit
            $quota_manager = new \DB\Jobs\API_Quota_Manager();
            $minute_check = $quota_manager->check_minute_limit('isochrones');
            
            if (!$minute_check['allowed']) {
                $this->debug_log('[Isochrones] token bucket blocked', [
                    'origin_id' => $origin_id,
                    'wait_seconds' => $minute_check['wait_seconds'] ?? null,
                    'tokens_before' => $minute_check['tokens_before'] ?? null,
                    'tokens_after' => $minute_check['tokens_after'] ?? null
                ]);
                return false;
            }

            $body = [
                'locations' => [[(float)$lng, (float)$lat]],
                'range' => $ranges,
                'range_type' => 'time'
            ];

            $this->debug_log('[Isochrones] sending request', [
                'origin_id' => $origin_id,
                'ranges' => $ranges,
                'tokens_after' => $minute_check['tokens_after'] ?? null
            ]);

            $did_call = true;
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
                $this->debug_log('[Isochrones] wp_remote_post error', [
                    'origin_id' => $origin_id,
                    'error' => $response->get_error_message()
                ]);
                return $did_call;
            }

            $http_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $data = json_decode($response_body, true);
            
            // Uložit kvóty z hlaviček
            $headers = wp_remote_retrieve_headers($response);
            $quota_manager->save_ors_headers($headers, 'isochrones');
            $remaining_header = isset($headers['x-ratelimit-remaining']) ? (int)$headers['x-ratelimit-remaining'] : null;
            $retry_after_header = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;

            $this->debug_log('[Isochrones] response received', [
                'origin_id' => $origin_id,
                'http_code' => $http_code,
                'ratelimit_remaining' => $remaining_header,
                'retry_after' => $retry_after_header
            ]);

            if ($http_code === 401 || $http_code === 403) {
                $this->debug_log('[Isochrones] unauthorized', [
                    'origin_id' => $origin_id,
                    'http_code' => $http_code
                ]);
                $quota_manager->mark_daily_quota_exhausted('isochrones', $http_code);
                $this->set_isochrones_error($origin_id, $profile, 'unauthorized', 'API key invalid');
                return $did_call;
            }

            if ($http_code === 429) {
                $this->debug_log('[Isochrones] rate limited', [
                    'origin_id' => $origin_id,
                    'retry_after' => $retry_after_header
                ]);
                $this->set_isochrones_error($origin_id, $profile, 'rate_limited', 'Rate limited');
                return $did_call;
            }

            if ($http_code !== 200) {
                $this->debug_log('[Isochrones] unexpected response', [
                    'origin_id' => $origin_id,
                    'http_code' => $http_code,
                    'body_excerpt' => $this->truncate_body($response_body)
                ]);
                $this->set_isochrones_error($origin_id, $profile, 'upstream_error', "HTTP $http_code: " . ($data['error']['message'] ?? 'Unknown error'));
                return $did_call;
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
                'running' => false,
                'user_settings' => [
                    'enabled' => true,
                    'walking_speed' => 4.5
                ]
            ];
            
            update_post_meta($origin_id, $meta_key, json_encode($payload));
            $this->debug_log('[Isochrones] cached', [
                'origin_id' => $origin_id,
                'features' => count($data['features'] ?? []),
                'ratelimit_remaining' => $remaining_header
            ]);

            return $did_call;

        } catch (\Throwable $e) {
            $this->debug_log('[Isochrones] exception', [
                'origin_id' => $origin_id,
                'error' => $e->getMessage()
            ]);
            return $did_call;
        }
    }
    
    /**
     * Nastavit chybu v isochrones cache
     */
    private function set_isochrones_error($origin_id, $profile, $error_type, $message, $retry_after = null) {
        $meta_key = 'db_isochrones_v1_' . $profile;
        $cfg = get_option('db_nearby_config', []);
        
        // DŮLEŽITÉ: Zachovat stará data, pokud existují
        $existing_cache = get_post_meta($origin_id, $meta_key, true);
        $existing_payload = null;
        
        if ($existing_cache && !empty($existing_cache)) {
            $existing_payload = is_string($existing_cache) ? json_decode($existing_cache, true) : $existing_cache;
        }
        
        // Pokud máme stará data, zachovat je a jen přidat error info
        if ($existing_payload && isset($existing_payload['geojson']) && !empty($existing_payload['geojson'])) {
            $payload = $existing_payload;
            $payload['error'] = $error_type;
            $payload['error_at'] = current_time('c');
            $payload['retry_after_s'] = $retry_after;
            $payload['running'] = false;
        } else {
            // Pokud nemáme stará data, vytvořit novou strukturu s null
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
                'running' => false,
                'user_settings' => [
                    'enabled' => true,
                    'walking_speed' => 4.5
                ]
            ];
        }
        
        update_post_meta($origin_id, $meta_key, json_encode($payload));
    }
    
    /**
     * Získat kandidáty pomocí Haversine SQL dotazu
     */
    public function get_candidates($lat, $lng, $type, $radiusKm, $limit) {
        global $wpdb;

        $matrix_limit = max(1, API_Quota_Manager::ORS_MATRIX_MAX_LOCATIONS - 1);
        $limit = max(1, min((int)$limit, $matrix_limit));

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

        // Cache key pro tento dotaz
        $cache_key = 'db_candidates_' . md5($lat . '_' . $lng . '_' . $target_type . '_' . $radiusKm . '_' . $limit);
        $cached_result = wp_cache_get($cache_key, 'db_nearby');
        
        if ($cached_result !== false) {
            $this->debug_log('[Cache] Using cached candidates', [
                'origin_lat' => $lat,
                'origin_lng' => $lng,
                'target_type' => $target_type,
                'radius_km' => $radiusKm,
                'limit' => $limit,
                'cached_count' => count($cached_result)
            ]);
            return $cached_result;
        }
        
        // Optimalizovaný SQL dotaz s lepšími indexy
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
            INNER JOIN {$wpdb->postmeta} lat_pm ON lat_pm.post_id = p.ID AND lat_pm.meta_key = %s
            INNER JOIN {$wpdb->postmeta} lng_pm ON lng_pm.post_id = p.ID AND lng_pm.meta_key = %s
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND lat_pm.meta_value != '' 
            AND lng_pm.meta_value != ''
            AND lat_pm.meta_value+0 BETWEEN %f AND %f
            AND lng_pm.meta_value+0 BETWEEN %f AND %f
            HAVING dist_km <= %f
            ORDER BY dist_km ASC
            LIMIT %d
        ", $lat, $lng, $lat, $lat_key, $lng_key, $target_type, 
            $lat - ($radiusKm / 111.0), $lat + ($radiusKm / 111.0), // Přibližný bounding box
            $lng - ($radiusKm / (111.0 * cos(deg2rad($lat)))), $lng + ($radiusKm / (111.0 * cos(deg2rad($lat)))),
            $radiusKm, $limit);

        $start_time = microtime(true);
        $rows = $wpdb->get_results($sql, ARRAY_A);
        $query_time = microtime(true) - $start_time;
        
        $result = array_map(function($r){
            return [
                'id'   => (int)$r['id'],
                'lat'  => (float)$r['lat'],
                'lng'  => (float)$r['lng'],
                'type' => $r['post_type'],
                'name' => (string)$r['name'],
                'dist_km' => isset($r['dist_km']) ? (float)$r['dist_km'] : null,
            ];
        }, $rows ?: []);

        // Cache výsledek na 5 minut
        wp_cache_set($cache_key, $result, 'db_nearby', 300);
        
        $this->debug_log('[Query] Candidates query completed', [
            'origin_lat' => $lat,
            'origin_lng' => $lng,
            'target_type' => $target_type,
            'radius_km' => $radiusKm,
            'limit' => $limit,
            'query_time' => round($query_time, 4),
            'result_count' => count($result)
        ]);

        return $result;
    }
    
    /**
     * Zápis cache s error handlingem
     * DŮLEŽITÉ: Zachová stará data, pokud jsou k dispozici a došlo k chybě
     */
    private function write_cache($origin_id, $meta_key, array $items, bool $partial, int $done, int $total, ?string $computed_at, ?string $error, ?int $retry_after_s = null) {
        // Pokud máme chybu a prázdné items, zkontrolovat existující data
        if ($error && empty($items)) {
            $existing_cache = get_post_meta($origin_id, $meta_key, true);
            if ($existing_cache) {
                $existing_payload = is_string($existing_cache) ? json_decode($existing_cache, true) : $existing_cache;
                // Zachovat stará data a jen přidat info o chybě
                if ($existing_payload && !empty($existing_payload['items'])) {
                    $payload = $existing_payload;
                    $payload['error'] = $error;
                    $payload['error_at'] = current_time('c');
                    if ($retry_after_s) {
                        $payload['retry_after_s'] = (int)$retry_after_s;
                    }
                    update_post_meta($origin_id, $meta_key, wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
                    return;
                }
            }
        }
        
        // Normální zápis nových dat
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
     * Převést kandidáty na stdClass pro jednotnou práci s ORS matrix API.
     */
    private function normalize_matrix_candidates(array $candidates) {
        return array_map(function($candidate) {
            if (is_object($candidate)) {
                return $candidate;
            }

            $object = new \stdClass();
            foreach ($candidate as $key => $value) {
                $object->{$key} = $value;
            }

            return $object;
        }, $candidates);
    }

    /**
     * Zpracovat dávku kandidátů
     */
    private function process_chunk($chunk, $orsKey, $provider, $profile, $origin_lat, $origin_lng, $origin_id = null, $chunk_index = null) {
        try {
            if ($provider === 'ors') {
                return $this->process_ors_chunk($chunk, $orsKey, $profile, $origin_lat, $origin_lng, $origin_id, $chunk_index);
            } else {
                return $this->process_osrm_chunk($chunk, $profile, $origin_lat, $origin_lng);
            }
        } catch (\Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Zpracovat dávku přes ORS Matrix API
     */
    private function process_ors_chunk($chunk, $orsKey, $profile, $origin_lat, $origin_lng, $origin_id = null, $chunk_index = null) {
        $locations = array(array((float)$origin_lng, (float)$origin_lat));
        foreach ($chunk as $cand) {
            $locations[] = array((float)$cand->lng, (float)$cand->lat);
        }

        $body = array(
            'locations' => $locations,
            'sources' => array(0),
            'destinations' => range(1, count($chunk)),
            'metrics' => array('distance', 'duration')
        );
        $body_json = wp_json_encode($body, JSON_UNESCAPED_UNICODE);

        $dest_ids = array_map(function($cand) {
            return isset($cand->id) ? (int)$cand->id : null;
        }, $chunk);

        $this->debug_log('[Matrix] preparing request', [
            'origin_id' => $origin_id,
            'chunk_index' => $chunk_index,
            'chunk_size' => count($chunk),
            'destination_ids' => array_values(array_filter($dest_ids, fn($v) => $v !== null)),
            'context' => 'process_chunk'
        ]);

        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        $minute_check = $quota_manager->check_minute_limit('matrix');

        if (!$minute_check['allowed']) {
            $wait = $minute_check['wait_seconds'] ?? null;
            $this->debug_log('[Matrix] token bucket blocked', [
                'origin_id' => $origin_id,
                'chunk_index' => $chunk_index,
                'wait_seconds' => $wait,
                'tokens_before' => $minute_check['tokens_before'] ?? null,
                'tokens_after' => $minute_check['tokens_after'] ?? null
            ]);
            return array(
                'success' => false,
                'error' => $wait ? "Lokální minutový limit. Počkej {$wait}s" : 'Lokální minutový limit',
                'retry_after_s' => $wait
            );
        }

        $this->debug_log('[Matrix] sending request', [
            'origin_id' => $origin_id,
            'chunk_index' => $chunk_index,
            'minute_tokens_after' => $minute_check['tokens_after'] ?? null,
            'body_excerpt' => $this->truncate_body($body_json)
        ]);

        $response = wp_remote_post("https://api.openrouteservice.org/v2/matrix/{$profile}", array(
            'headers' => array(
                'Authorization' => $orsKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/batch (+https://dobitybaterky.cz)'
            ),
            'body' => $body_json,
            'timeout' => 20
        ));
        
        if (is_wp_error($response)) {
            $this->debug_log('[Matrix] wp_remote_post error', [
                'origin_id' => $origin_id,
                'chunk_index' => $chunk_index,
                'error' => $response->get_error_message()
            ]);
            return array('success' => false, 'error' => $response->get_error_message());
        }
        
        $code = (int)wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $data = json_decode(wp_remote_retrieve_body($response), true);
        $remaining_header = isset($headers['x-ratelimit-remaining']) ? (int)$headers['x-ratelimit-remaining'] : null;
        $retry_after_header = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;

        $this->debug_log('[Matrix] response received', [
            'origin_id' => $origin_id,
            'chunk_index' => $chunk_index,
            'http_code' => $code,
            'ratelimit_remaining' => $remaining_header,
            'retry_after' => $retry_after_header
        ]);
        
        // Uložit kvóty z hlaviček
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        $quota_manager->save_ors_headers($headers, 'matrix');
        
        // Obsluha limitů
        if ($code === 429) {
            $retry_after = $retry_after_header ?? 10;
            $this->debug_log('[Matrix] rate limited', [
                'origin_id' => $origin_id,
                'chunk_index' => $chunk_index,
                'retry_after' => $retry_after
            ]);
            return array('success' => false, 'error' => "ORS minute limit hit. Retry after {$retry_after}s");
        }
        
        if ($code === 401 || $code === 403) {
            $this->debug_log('[Matrix] unauthorized', [
                'origin_id' => $origin_id,
                'chunk_index' => $chunk_index,
                'http_code' => $code
            ]);
            $quota_manager->mark_daily_quota_exhausted('matrix', $code);
            return array('success' => false, 'error' => 'ORS unauthorized/daily quota exhausted');
        }
        
        if ($code < 200 || $code >= 300 || empty($data['durations'])) {
            $this->debug_log('[Matrix] unexpected response', [
                'origin_id' => $origin_id,
                'chunk_index' => $chunk_index,
                'http_code' => $code,
                'body_excerpt' => $this->truncate_body(json_encode($data))
            ]);
            return array('success' => false, 'error' => 'ORS bad response: ' . $code);
        }
        
        $durations = $data['durations'][0];
        $distances = $data['distances'][0] ?? array_fill(0, count($chunk), null);

        $items = array();
        foreach ($chunk as $idx => $cand) {
            $name = $this->resolve_candidate_name($cand);
            $items[] = array(
                'id' => (int)$cand->id,
                'post_type' => (string)$cand->type,
                'name' => $name,
                'title' => $name,
                'duration_s' => (int)round($durations[$idx] ?? -1),
                'distance_m' => (int)round($distances[$idx] ?? -1),
                'walk_m' => (int)round($distances[$idx] ?? -1),
                'secs' => (int)round($durations[$idx] ?? -1),
                'provider' => 'ors.matrix',
                'profile' => $profile,
            );
        }
        $this->debug_log('[Matrix] chunk processed', [
            'origin_id' => $origin_id,
            'chunk_index' => $chunk_index,
            'items_added' => count($items),
            'tokens_remaining' => $minute_check['tokens_after'] ?? null,
            'ratelimit_remaining' => $remaining_header
        ]);

        return array('success' => true, 'items' => $items);
    }
    
    /**
     * Zpracovat dávku přes OSRM Matrix API
     */
    private function process_osrm_chunk($chunk, $profile, $origin_lat, $origin_lng, $origin_id = null, $chunk_index = null) {
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
            $name = $this->resolve_candidate_name($cand);
            $items[] = array(
                'id' => (int)$cand->id,
                'post_type' => (string)$cand->type,
                'name' => $name,
                'title' => $name,
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
            LIMIT 1000
        ", $candidate_type, $origin_id, $origin->lat, $origin->lng, $origin->lat, $origin->lat, $origin->lng, $origin->lat));
        
        return $candidates;
    }

    private function resolve_candidate_name($candidate) {
        $id = null;
        $raw = '';

        if (is_array($candidate)) {
            $id = isset($candidate['id']) ? (int)$candidate['id'] : null;
            if (!empty($candidate['name'])) {
                $raw = (string)$candidate['name'];
            } elseif (!empty($candidate['post_title'])) {
                $raw = (string)$candidate['post_title'];
            }
        } elseif (is_object($candidate)) {
            $id = isset($candidate->id) ? (int)$candidate->id : null;
            if (!empty($candidate->name)) {
                $raw = (string)$candidate->name;
            } elseif (!empty($candidate->post_title)) {
                $raw = (string)$candidate->post_title;
            }
        }

        if ($raw === '' && $id) {
            $title = get_the_title($id);
            if (!empty($title)) {
                $raw = $title;
            }
        }

        return $raw !== '' ? wp_strip_all_tags($raw) : '';
    }
}
