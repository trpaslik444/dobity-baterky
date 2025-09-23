<?php

namespace DB\Jobs;

class Nearby_Recompute_Job {

    /**
     * Zapsat ladicí zprávu jak do PHP error logu, tak do souboru v uploads.
     */
    private function debug_log($message, array $context = []) {
        if (!empty($context)) {
            $context_json = wp_json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $message .= ' | ' . $context_json;
        }

        $line = '[DB Nearby] ' . $message;
        error_log($line);

        $uploads = function_exists('wp_upload_dir') ? wp_upload_dir() : null;
        if (!empty($uploads['basedir']) && is_dir($uploads['basedir']) && is_writable($uploads['basedir'])) {
            $log_path = trailingslashit($uploads['basedir']) . 'db-nearby-debug.log';
            $timestamp = gmdate('Y-m-d H:i:s');
            @file_put_contents($log_path, "[{$timestamp}] {$line}\n", FILE_APPEND | LOCK_EX);
        }
    }

    private function truncate_body($body) {
        if (!is_string($body)) {
            return '';
        }
        return function_exists('mb_substr') ? mb_substr($body, 0, 500) : substr($body, 0, 500);
    }

    private function wait_for_quota($type, \DB\Jobs\API_Quota_Manager $quota_manager, $origin_id = null) {
        $check = $quota_manager->check_minute_limit($type);
        if (!empty($check['allowed'])) {
            return true;
        }
        
        // Pokud nemáme kvótu, vrátit false místo čekání
        $wait = isset($check['wait_seconds']) ? max(1, (int)$check['wait_seconds']) : 60;
        if ($origin_id) {
            $this->debug_log('[Quota] insufficient quota', ['origin_id' => $origin_id, 'type' => $type, 'wait' => $wait]);
        }
        return false;
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
            $iso_settings = get_option('db_isochrones_settings', [
                'enabled' => 1,
                'walking_speed_kmh' => 4.5,
                'display_times_min' => [10, 20, 30]
            ]);
            $orsKey = trim((string)($cfg['ors_api_key'] ?? ''));
            $provider = (string)($cfg['provider'] ?? 'ors');
            $profile = 'foot-walking';
            $batch_size = (int)($cfg['matrix_batch_size'] ?? 50);
            $batch_size = max(1, min(50, $batch_size));
            $api_calls_used = 0;
            $started_at = microtime(true);
            $quota_manager = new \DB\Jobs\API_Quota_Manager();
            $batch_size = max(1, $quota_manager->get_max_batch_limit($batch_size));

            $basic_result = function() use ($origin_id, $type, $candidates, $meta_key, $origin_lat, $origin_lng, $cfg, $iso_settings, $started_at) {
                $items = $this->build_basic_items($candidates, $origin_lat, $origin_lng, $cfg, $type);
                $timestamp = current_time('c');
                $count = count($items);
                $this->write_cache($origin_id, $meta_key, $items, false, $count, $count, $timestamp, null);

                $iso_summary = null;
                if (!empty($iso_settings['enabled'])) {
                    $fallback_payload = $this->generate_fallback_isochrones($origin_lat, $origin_lng, $this->resolve_isochrones_ranges($iso_settings), $iso_settings, 'basic');
                    $this->store_isochrones_payload($origin_id, 'foot-walking', $fallback_payload, $iso_settings);
                    $iso_summary = array(
                        'provider' => 'fallback.circle',
                        'features' => count($fallback_payload['geojson']['features'] ?? []),
                        'error' => null
                    );
                }

                $processing_time = (int) round((microtime(true) - $started_at) * 1000);
                $this->track_processed_location($origin_id, $type, $candidates, 0, $processing_time, 'basic.haversine', $iso_summary);
                $this->debug_log("[Done] origin={$origin_id} provider=basic items={$count} time_ms={$processing_time}");

                $iso_provider = $iso_summary['provider'] ?? null;
                $iso_features = $iso_summary['features'] ?? null;
                $iso_error_return = $iso_summary['error'] ?? (!empty($iso_settings['enabled']) ? null : 'disabled');

                return array(
                    'success' => true,
                    'items_count' => $count,
                    'processed' => $count,
                    'api_calls' => 0,
                    'processing_time' => $processing_time,
                    'api_provider' => 'basic.haversine',
                    'isochrones_provider' => $iso_provider,
                    'isochrones_features' => $iso_features,
                    'isochrones_error' => $iso_error_return
                );
            };

            if ($provider === 'basic') {
                return $basic_result();
            }

            if (empty($orsKey)) {
                return array('success' => false, 'error' => 'ORS API key není nastaven');
            }
            
            $candidates = array_slice($candidates, 0, $batch_size);
            $items = array();
            $total = count($candidates);
            
            // Zpracovat po dávkách
            for ($i = 0; $i < $total; $i += $batch_size) {
                $chunk = array_slice($candidates, $i, $batch_size);
                $this->debug_log("[Chunk] origin={$origin_id}, type={$type}, index=" . ($i / $batch_size) . ", size=" . count($chunk));
                $chunk_result = $this->process_chunk($chunk, $orsKey, $provider, $profile, $origin_lat, $origin_lng, $origin_id);

                if (!$chunk_result['success']) {
                    $this->debug_log("[Chunk] origin={$origin_id} failed: " . ($chunk_result['error'] ?? 'unknown'));
                    if (!empty($chunk_result['http_code'])) {
                        $this->debug_log("[Chunk] origin={$origin_id} http_code=" . $chunk_result['http_code']);
                    }
                    if (!empty($chunk_result['response_body'])) {
                        $this->debug_log("[Chunk] origin={$origin_id} response_body=" . $chunk_result['response_body']);
                    }
                    return $chunk_result;
                }
                
                $items = array_merge($items, $chunk_result['items']);
                $api_calls_used++;
                
                // Pauza mezi dávkami
                if ($i + $batch_size < $total) {
                    usleep(150000); // 150ms
                }
            }
            
            $timestamp = current_time('c');
            $count = count($items);
            $this->write_cache($origin_id, $meta_key, $items, false, $count, $count, $timestamp, null);

            // Současně stáhnout a uložit isochrones stejně jako v recompute_nearby_for_origin
            $iso_summary = null;
            if (!empty($orsKey) && $provider === 'ors') {
                $iso_summary = $this->fetch_and_cache_isochrones($origin_id, $origin_lat, $origin_lng, $orsKey, $iso_settings);
            } else {
                // Pro basic provider použít fallback isochrony
                $fallback_payload = $this->generate_fallback_isochrones($origin_lat, $origin_lng, $this->resolve_isochrones_ranges($iso_settings), $iso_settings, 'basic');
                $this->store_isochrones_payload($origin_id, 'foot-walking', $fallback_payload, $iso_settings);
                $iso_summary = array(
                    'provider' => 'fallback.circle',
                    'features' => count($fallback_payload['geojson']['features'] ?? []),
                    'error' => null
                );
            }

            if ($iso_summary === null) {
                $iso_summary = array(
                    'provider' => null,
                    'features' => 0,
                    'error' => !empty($iso_settings['enabled']) ? 'not_requested' : 'disabled'
                );
            }

            if (is_array($iso_summary) && !empty($iso_summary['error'])) {
                $error_type = $iso_summary['error'];
                $retry_after = isset($iso_summary['retry_after']) ? (int)$iso_summary['retry_after'] : 60;
                $rate_limited_flag = in_array($error_type, ['rate_limited', 'minute_limit', 'network_error', 'http_500', 'http_502', 'http_503', 'http_504', 'exception']);
                $this->debug_log('[Isochrones] retry planned', ['origin_id' => $origin_id, 'error' => $error_type, 'retry_after' => $retry_after]);
                $this->write_cache($origin_id, $meta_key, $items, true, $count, $total, $timestamp, 'isochrones_' . $iso_summary['error'], $retry_after);
                return array(
                    'success' => false,
                    'error' => 'Isochrones ' . $iso_summary['error'],
                    'rate_limited' => $rate_limited_flag,
                    'retry_after' => $retry_after,
                    'isochrones_error' => $iso_summary['error']
                );
            }

            $processing_time = (int) round((microtime(true) - $started_at) * 1000);
            // Přidat do zpracovaných míst
            $this->track_processed_location($origin_id, $type, $candidates, $api_calls_used, $processing_time, 'ors.matrix', $iso_summary);
            $this->debug_log("[Done] origin={$origin_id} provider=ors items={$count} api_calls={$api_calls_used} time_ms={$processing_time}");

            $iso_provider = $iso_summary['provider'] ?? null;
            $iso_features = $iso_summary['features'] ?? null;
            $iso_error_return = $iso_summary['error'] ?? null;
            if ($iso_error_return === null && $iso_summary === null && !empty($iso_settings['enabled'])) {
                $iso_error_return = 'not_requested';
            }

            return array(
                'success' => true,
                'items_count' => $count,
                'processed' => $count,
                'api_calls' => $api_calls_used,
                'processing_time' => $processing_time,
                'api_provider' => 'ors.matrix',
                'isochrones_provider' => $iso_provider,
                'isochrones_features' => $iso_features,
                'isochrones_error' => $iso_error_return
            );
            
        } catch (Exception $e) {
            return array('success' => false, 'error' => $e->getMessage());
        }
    }
    
    /**
     * Track zpracované místo v databázi
     */
    private function track_processed_location($origin_id, $origin_type, $candidates, $api_calls_used, $processing_time, $provider_label = 'ors', $iso_summary = null) {
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
                'api_provider' => $provider_label,
                'cache_size_kb' => round($cache_size_kb),
                'isochrones_provider' => $iso_summary['provider'] ?? null,
                'isochrones_features' => $iso_summary['features'] ?? null,
                'isochrones_error' => $iso_summary['error'] ?? null,
                'status' => 'completed'
            );
            
            // origin_type zde reprezentuje cílový typ (co zpracováváme). Do processed ukládáme reálný typ origin postu.
            $real_origin_type = get_post_type($origin_id) ?: $origin_type;
            $processed_type = ($origin_type === 'poi') ? 'poi_foot' : 'charger_foot';
            $processed_manager->add_processed($origin_id, $real_origin_type, $processed_type, $stats);
            
        } catch (Exception $e) {
            $this->debug_log('[Processed Tracking] Chyba při trackingu: ' . $e->getMessage());
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
            $this->debug_log("[Lock] already exists for #$origin_id/$type");
            return;
        }
        // Transient lock na několik minut, aby se nespouštělo opakovaně z více míst
        if (get_transient($transient_lock_key)) {
            $this->debug_log("[Lock] transient active for #$origin_id/$type");
            delete_post_meta($origin_id, $lock_key); // uvolnit post_meta lock, když transient brání běhu
            return;
        }
        set_transient($transient_lock_key, 1, 5 * MINUTE_IN_SECONDS);
        
        try {
            $cfg = get_option('db_nearby_config', []);
            $iso_settings = get_option('db_isochrones_settings', [
                'enabled' => 1,
                'walking_speed_kmh' => 4.5,
                'display_times_min' => [10, 20, 30]
            ]);

            $orsKey    = trim((string)($cfg['ors_api_key'] ?? ''));
            $provider  = (string)($cfg['provider'] ?? 'ors');
            $profile   = 'foot-walking'; // Vždy používáme pěší trasu pro všechny typy
            $radiusKm  = (float)($cfg['radius_km'] ?? 5);
            $maxCand   = (int)($cfg['max_candidates'] ?? 50);
            $maxCand   = max(1, min(50, $maxCand));
            $batchSize = (int)($cfg['matrix_batch_size'] ?? 50);
            $batchSize = max(1, min(50, $batchSize));
            $batchSize = min($batchSize, $maxCand);

            // Získat souřadnice origin postu podle jeho typu
            $origin_post = get_post($origin_id);
            if (!$origin_post) {
                $this->debug_log("[Guard] origin post #$origin_id not found");
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
                $this->debug_log("[Guard] missing coords for #$origin_id");
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
            $candidates = array_slice($candidates, 0, $batchSize);
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
                $minute_check = $quota_manager->check_minute_limit('matrix');
                
                if (!$minute_check['allowed']) {
                    $wait_seconds = isset($minute_check['wait_seconds']) ? (int)$minute_check['wait_seconds'] : 60;
                    $this->debug_log("[Chunk] origin={$origin_id} local minute limit, wait {$wait_seconds}s");
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, null, 'rate_limited', $wait_seconds);
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
                    'timeout' => 60
                ]);

                if (is_wp_error($res)) {
                    $this->debug_log("[Chunk] origin={$origin_id} ORS error: " . $res->get_error_message());
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
                    $reset_epoch = isset($headers['x-ratelimit-reset']) ? (int)$headers['x-ratelimit-reset'] : null;
                    $retry_after_header = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, current_time('c'), ($code === 401 ? 'unauthorized' : 'rate_limited'), 6 * HOUR_IN_SECONDS);
                    return array(
                        'success' => false,
                        'error' => ($code === 401)
                            ? 'ORS API key je neplatný nebo nemá oprávnění (401)'
                            : $this->format_rate_limit_message($retry_after_header, $reset_epoch),
                        'rate_limited' => ($code === 403),
                        'retry_after' => $retry_after_header,
                        'reset_at' => $reset_epoch
                    );
                }
                if ($code === 429) {
                    $this->debug_log("[Chunk] origin={$origin_id} ORS rate limit (429) – partial save and stop");
                    $this->write_cache($origin_id, $meta_key, $items, true, $done, $total, current_time('c'), 'rate_limited', 120);
                    // Naplánovat opakování s backoffem (např. za 2 minuty)
                    if (!wp_next_scheduled('db_nearby_recompute', [$origin_id, $type])) {
                        wp_schedule_single_event(time() + 120, 'db_nearby_recompute', [$origin_id, $type]);
                    }
                    $retry_after = isset($headers['retry-after']) ? (int)$headers['retry-after'] : null;
                    $reset_epoch = isset($headers['x-ratelimit-reset']) ? (int)$headers['x-ratelimit-reset'] : null;
                    return array(
                        'success' => false,
                        'error' => $this->format_rate_limit_message($retry_after, $reset_epoch),
                        'rate_limited' => true,
                        'retry_after' => $retry_after,
                        'reset_at' => $reset_epoch
                    );
                }
                if ($code < 200 || $code >= 300 || empty($json['durations'])) {
                    $this->debug_log("[Chunk] origin={$origin_id} ORS bad response: " . $code);
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
            $this->fetch_and_cache_isochrones($origin_id, $lat, $lng, $orsKey, $iso_settings);

        } catch (\Throwable $e) {
            $this->debug_log('[Exception] ' . $e->getMessage());
            $this->write_cache($origin_id, $meta_key, [], true, 0, 0, null, 'exception');
        } finally {
            delete_post_meta($origin_id, $lock_key);
            delete_transient($transient_lock_key);
        }
    }
    
    /**
     * Načíst a uložit isochrones data současně s nearby data
     */
    private function fetch_and_cache_isochrones($origin_id, $lat, $lng, $orsKey, array $iso_settings) {
        try {
            $profile = 'foot-walking';
            $ranges = $this->resolve_isochrones_ranges($iso_settings);
            
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
                        $features = count($payload['geojson']['features'] ?? []);
                        return array(
                            'provider' => $payload['provider'] ?? 'cache.reuse',
                            'features' => $features,
                            'error' => null
                        );
                    }
                }
            }
            
            // Zkontrolovat lokální minutový limit
            $quota_manager = new \DB\Jobs\API_Quota_Manager();
            $quota_check = $quota_manager->check_minute_limit('isochrones');
            if (!$quota_check['allowed']) {
                $wait_seconds = isset($quota_check['wait_seconds']) ? (int)$quota_check['wait_seconds'] : 60;
                $this->debug_log("[Isochrones] origin={$origin_id} quota insufficient, wait {$wait_seconds}s");
                return array('provider' => null, 'features' => 0, 'error' => 'minute_limit', 'retry_after' => $wait_seconds);
            }

            $body = [
                'locations' => [[(float)$lng, (float)$lat]],
                'range' => $ranges,
                'range_type' => 'time'
            ];
            
            $this->debug_log("[Isochrones] request", ['origin_id' => $origin_id, 'ranges' => $ranges]);

            $response = wp_remote_post("https://api.openrouteservice.org/v2/isochrones/{$profile}", [
                'headers' => [
                    'Authorization' => $orsKey,
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/geo+json;charset=UTF-8',
                    'User-Agent' => 'DobityBaterky/isochrones (+https://dobitybaterky.cz)'
                ],
                'body' => json_encode($body),
                'timeout' => 45
            ]);
            
            if (is_wp_error($response)) {
                $this->debug_log('[Isochrones] network error', ['origin_id' => $origin_id, 'error' => $response->get_error_message()]);
                return array('provider' => null, 'features' => 0, 'error' => 'network_error', 'retry_after' => 30);
            }
            
            $http_code = wp_remote_retrieve_response_code($response);
            $response_body = wp_remote_retrieve_body($response);
            $this->debug_log('[Isochrones] response', ['origin_id' => $origin_id, 'http_code' => $http_code, 'body' => $this->truncate_body($response_body)]);
            $data = json_decode($response_body, true);
            
            // Uložit kvóty z hlaviček
            $headers = wp_remote_retrieve_headers($response);
            $quota_manager->save_ors_headers($headers);
            
            if ($http_code === 401 || $http_code === 403) {
                $this->debug_log('[Isochrones] unauthorized', ['origin_id' => $origin_id]);
                $this->set_isochrones_error($origin_id, $profile, 'unauthorized', 'API key invalid');
                return array('provider' => null, 'features' => 0, 'error' => 'unauthorized', 'retry_after' => 3600);
            }

            if ($http_code === 429) {
                $this->debug_log('[Isochrones] rate limited', ['origin_id' => $origin_id]);
                $this->set_isochrones_error($origin_id, $profile, 'rate_limited', 'Rate limited');
                $retry_after_header = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 60;
                return array('provider' => null, 'features' => 0, 'error' => 'rate_limited', 'retry_after' => $retry_after_header);
            }

            if ($http_code !== 200) {
                $this->debug_log('[Isochrones] http error', ['origin_id' => $origin_id, 'http_code' => $http_code]);
                $this->set_isochrones_error($origin_id, $profile, 'upstream_error', "HTTP $http_code: " . ($data['error']['message'] ?? 'Unknown error'));
                return array('provider' => null, 'features' => 0, 'error' => 'http_' . $http_code, 'retry_after' => 120);
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
                'provider' => 'ors.matrix'
            ];
            
            $this->store_isochrones_payload($origin_id, $profile, $payload, $iso_settings);
            $feature_count = count($data['features'] ?? []);
            $this->debug_log('[Isochrones] success', ['origin_id' => $origin_id, 'features' => $feature_count]);
            return array(
                'provider' => 'ors.matrix',
                'features' => $feature_count,
                'error' => null,
                'retry_after' => null
            );
        } catch (\Throwable $e) {
            $this->debug_log('[Isochrones] exception', ['origin_id' => $origin_id, 'error' => $e->getMessage()]);
            return array('provider' => null, 'features' => 0, 'error' => 'exception', 'retry_after' => 60);
        }
        return array('provider' => null, 'features' => 0, 'error' => 'unknown', 'retry_after' => 60);
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

    private function store_isochrones_payload($origin_id, $profile, array $payload, ?array $iso_settings = null) {
        $meta_key = 'db_isochrones_v1_' . $profile;
        if ($iso_settings !== null && !isset($payload['user_settings'])) {
            $payload['user_settings'] = $iso_settings;
        }
        update_post_meta($origin_id, $meta_key, wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    private function resolve_isochrones_ranges(array $settings) {
        $default = [10, 20, 30];
        $times = isset($settings['display_times_min']) && is_array($settings['display_times_min']) ? $settings['display_times_min'] : $default;
        $ranges = [];
        foreach ($times as $minutes) {
            $minutes = (int)$minutes;
            if ($minutes <= 0) {
                continue;
            }
            $ranges[] = max(60, $minutes * 60);
        }
        return !empty($ranges) ? $ranges : array_map(fn($m) => $m * 60, $default);
    }

    private function generate_fallback_isochrones($lat, $lng, array $ranges, array $settings, $context = 'fallback') {
        $speed_kmh = isset($settings['walking_speed_kmh']) ? (float)$settings['walking_speed_kmh'] : 4.5;
        $speed_mps = max(0.5, $speed_kmh * 1000 / 3600);

        $features = [];
        foreach ($ranges as $range_s) {
            $radius_m = max(50, $speed_mps * (int)$range_s);
            $coords = $this->build_circle_coordinates($lat, $lng, $radius_m, 64);
            $features[] = [
                'type' => 'Feature',
                'properties' => [
                    'value' => (int)$range_s,
                    'source' => 'fallback.circle',
                    'context' => $context
                ],
                'geometry' => [
                    'type' => 'Polygon',
                    'coordinates' => [$coords]
                ]
            ];
        }

        return [
            'version' => 1,
            'profile' => 'foot-walking',
            'ranges_s' => $ranges,
            'center' => [(float)$lng, (float)$lat],
            'geojson' => [
                'type' => 'FeatureCollection',
                'features' => $features
            ],
            'computed_at' => current_time('c'),
            'ttl_days' => 7,
            'error' => null,
            'error_at' => null,
            'retry_after_s' => null,
            'running' => false,
            'provider' => 'fallback.circle',
            'user_settings' => $settings
        ];
    }

    private function build_circle_coordinates($lat_deg, $lng_deg, $radius_m, $segments = 48) {
        $coords = [];
        $earth_radius = 6371000; // metry
        $lat = deg2rad($lat_deg);
        $lng = deg2rad($lng_deg);
        $angular_distance = $radius_m / $earth_radius;

        for ($i = 0; $i <= $segments; $i++) {
            $bearing = 2 * M_PI * ($i / $segments);
            $lat2 = asin(sin($lat) * cos($angular_distance) + cos($lat) * sin($angular_distance) * cos($bearing));
            $lng2 = $lng + atan2(
                sin($bearing) * sin($angular_distance) * cos($lat),
                cos($angular_distance) - sin($lat) * sin($lat2)
            );

            $coords[] = [rad2deg($lng2), rad2deg($lat2)];
        }

        return $coords;
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
        
        error_log("[DB DEBUG] get_candidates: lat={$lat}, lng={$lng}, type={$type}, target_type={$target_type}, radius={$radiusKm}km, limit={$limit}");

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
        
        error_log("[DB DEBUG] get_candidates SQL result: " . count($result) . " candidates found");
        if (count($result) === 0) {
            error_log("[DB DEBUG] No candidates found - SQL: " . $sql);
        }
        
        return $result;
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
    private function process_chunk($chunk, $orsKey, $provider, $profile, $origin_lat, $origin_lng, $origin_id = null) {
        try {
            if ($provider === 'ors') {
                return $this->process_ors_chunk($chunk, $orsKey, $profile, $origin_lat, $origin_lng, $origin_id);
            } else {
                return $this->process_osrm_chunk($chunk, $profile, $origin_lat, $origin_lng, $origin_id);
            }
        } catch (Exception $e) {
            if ($origin_id) {
                $this->debug_log('[Chunk] exception', ['origin_id' => $origin_id, 'error' => $e->getMessage()]);
            }
            return array('success' => false, 'error' => $e->getMessage());
        }
    }

    /**
     * Zpracovat dávku přes ORS Matrix API
     */
    private function process_ors_chunk($chunk, $orsKey, $profile, $origin_lat, $origin_lng, $origin_id = null) {
        // Origin bod je vždy na indexu 0
        $locations = array(array((float)$origin_lng, (float)$origin_lat));

        // Přidat kandidáty
        foreach ($chunk as $cand) {
            $cand_lng = $this->candidate_value($cand, 'lng');
            $cand_lat = $this->candidate_value($cand, 'lat');

            if ($cand_lat === null || $cand_lng === null) {
                if ($origin_id) {
                    $this->debug_log('[Chunk] candidate missing coordinates', ['origin_id' => $origin_id]);
                }
                return array('success' => false, 'error' => 'Candidate is missing coordinates');
            }

            $locations[] = array((float)$cand_lng, (float)$cand_lat);
        }

        $body = array(
            'locations' => $locations,
            'sources' => array(0), // Origin bod je vždy na indexu 0
            'destinations' => range(1, count($chunk)), // Kandidáti jsou od indexu 1
            'metrics' => array('distance', 'duration')
        );
        
        // Zkontrolovat lokální minutový limit
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        $quota_check = $quota_manager->check_minute_limit('matrix');
        if (!$quota_check['allowed']) {
            $wait_seconds = isset($quota_check['wait_seconds']) ? (int)$quota_check['wait_seconds'] : 60;
            $this->debug_log('[Chunk] quota insufficient', ['origin_id' => $origin_id, 'wait' => $wait_seconds]);
            return array(
                'success' => false,
                'error' => 'Lokální minutový limit. Počkej ' . $wait_seconds . 's',
                'rate_limited' => true,
                'retry_after' => $wait_seconds
            );
        }
        
            $response = wp_remote_post("https://api.openrouteservice.org/v2/matrix/{$profile}", array(
            'headers' => array(
                'Authorization' => $orsKey,
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/batch (+https://dobitybaterky.cz)'
            ),
            'body' => json_encode($body),
            'timeout' => 60
        ));
        
        if (is_wp_error($response)) {
            $error_msg = $response->get_error_message();
            if ($origin_id) {
                $this->debug_log('[Chunk] network error', ['origin_id' => $origin_id, 'error' => $error_msg]);
            }
            $rate_limited = (strpos($error_msg, 'cURL error 28') !== false);
            return array(
                'success' => false,
                'error' => $error_msg,
                'rate_limited' => $rate_limited,
                'retry_after' => $rate_limited ? 60 : null,
                'isochrones_error' => $rate_limited ? 'network_error' : null
            );
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $headers = wp_remote_retrieve_headers($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);
        
        // Uložit kvóty z hlaviček
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        $quota_manager->save_ors_headers($headers);
        
        // Obsluha limitů
        if ($code === 429) {
            $retry_after = isset($headers['retry-after']) ? (int)$headers['retry-after'] : 10;
            if ($origin_id) {
                $this->debug_log('[Chunk] rate limited', ['origin_id' => $origin_id, 'retry_after' => $retry_after]);
            }
            return array(
                'success' => false,
                'error' => "ORS minute limit hit. Retry after {$retry_after}s",
                'rate_limited' => true,
                'retry_after' => $retry_after,
                'http_code' => $code,
                'response_body' => substr($raw_body, 0, 500)
            );
        }

        if ($code === 401 || $code === 403) {
            if ($origin_id) {
                $this->debug_log('[Chunk] unauthorized/daily quota exhausted', ['origin_id' => $origin_id, 'http_code' => $code]);
            }
            return array('success' => false, 'error' => 'ORS unauthorized/daily quota exhausted');
        }

        if ($code < 200 || $code >= 300 || empty($data['durations'])) {
            if ($origin_id) {
                $this->debug_log('[Chunk] bad response', ['origin_id' => $origin_id, 'http_code' => $code, 'body' => $this->truncate_body($raw_body)]);
            }
            return array('success' => false, 'error' => 'ORS bad response: ' . $code);
        }
        
        $durations = $data['durations'][0];
        $distances = $data['distances'][0] ?? array_fill(0, count($chunk), null);
        
        $items = array();
        foreach ($chunk as $idx => $cand) {
            $cand_id = $this->candidate_value($cand, 'id');
            $cand_type = $this->candidate_value($cand, 'type');

            if ($cand_id === null || $cand_type === null) {
                return array('success' => false, 'error' => 'Candidate is missing identifier data');
            }

            $items[] = array(
                'id' => (int)$cand_id,
                'post_type' => (string)$cand_type,
                'duration_s' => (int)round($durations[$idx] ?? -1),
                'distance_m' => (int)round($distances[$idx] ?? -1),
                'provider' => 'ors.matrix',
                'profile' => $profile,
            );
        }
        
        if ($origin_id) {
            $this->debug_log('[Chunk] success', ['origin_id' => $origin_id, 'items' => count($items)]);
        }
        return array('success' => true, 'items' => $items);
    }
    
    /**
     * Zpracovat dávku přes OSRM Matrix API
     */
    private function process_osrm_chunk($chunk, $profile, $origin_lat, $origin_lng, $origin_id = null) {
        // Origin bod je vždy na indexu 0
        $locations = array(array((float)$origin_lng, (float)$origin_lat));

        // Přidat kandidáty
        foreach ($chunk as $cand) {
            $cand_lng = $this->candidate_value($cand, 'lng');
            $cand_lat = $this->candidate_value($cand, 'lat');

            if ($cand_lat === null || $cand_lng === null) {
                if ($origin_id) {
                    $this->debug_log('[OSRM] candidate missing coordinates', ['origin_id' => $origin_id]);
                }
                return array('success' => false, 'error' => 'Candidate is missing coordinates');
            }

            $locations[] = array((float)$cand_lng, (float)$cand_lat);
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
            if ($origin_id) {
                $this->debug_log('[OSRM] network error', ['origin_id' => $origin_id, 'error' => $response->get_error_message()]);
            }
            return array('success' => false, 'error' => $response->get_error_message());
        }

        $code = (int)wp_remote_retrieve_response_code($response);
        $raw_body = wp_remote_retrieve_body($response);
        $data = json_decode($raw_body, true);

        if ($code < 200 || $code >= 300 || empty($data['durations'])) {
            if ($origin_id) {
            $this->debug_log('[OSRM] bad response', ['origin_id' => $origin_id, 'http_code' => $code, 'body' => $this->truncate_body($raw_body)]);
            }
            return array('success' => false, 'error' => 'OSRM bad response: ' . $code);
        }

        $durations = $data['durations'][0];
        $distances = $data['distances'][0] ?? array_fill(0, count($chunk), null);
        
        $items = array();
        foreach ($chunk as $idx => $cand) {
            $cand_id = $this->candidate_value($cand, 'id');
            $cand_type = $this->candidate_value($cand, 'type');

            if ($cand_id === null || $cand_type === null) {
                if ($origin_id) {
                    $this->debug_log('[OSRM] candidate missing identifier', ['origin_id' => $origin_id]);
                }
                return array('success' => false, 'error' => 'Candidate is missing identifier data');
            }

            $items[] = array(
                'id' => (int)$cand_id,
                'post_type' => (string)$cand_type,
                'duration_s' => (int)round($durations[$idx] ?? -1),
                'distance_m' => (int)round($distances[$idx] ?? -1),
                'provider' => 'osrm.matrix',
                'profile' => $profile,
            );
        }

        if ($origin_id) {
            $this->debug_log('[OSRM] success', ['origin_id' => $origin_id, 'items' => count($items)]);
        }
        return array('success' => true, 'items' => $items);
    }

    /**
     * Získat hodnotu z kandidáta bez ohledu na to, zda je pole nebo objekt
     */
    private function candidate_value($candidate, $key)
    {
        if (is_array($candidate)) {
            return array_key_exists($key, $candidate) ? $candidate[$key] : null;
        }

        if (is_object($candidate) && property_exists($candidate, $key)) {
            return $candidate->$key;
        }

        return null;
    }

    private function format_rate_limit_message($retry_after = null, $reset_epoch = null) {
        $parts = array('ORS denní kvóta vyčerpána');
        if ($reset_epoch) {
            $date_fn = function_exists('date_i18n') ? 'date_i18n' : 'date';
            $parts[] = 'reset ' . $date_fn('Y-m-d H:i', $reset_epoch);
        } elseif ($retry_after) {
            $parts[] = 'zkus znovu za ' . $retry_after . ' s';
        }
        return implode(' – ', $parts);
    }

    private function build_basic_items(array $candidates, $origin_lat, $origin_lng, array $cfg, $origin_type) {
        $items = array();
        $speed_mps = isset($cfg['walking_speed_m_s']) ? (float)$cfg['walking_speed_m_s'] : 1.3;
        if ($speed_mps <= 0) {
            $speed_mps = 1.3;
        }

        foreach ($candidates as $cand) {
            $cand_id = $this->candidate_value($cand, 'id');
            $cand_type = $this->candidate_value($cand, 'type');
            $cand_lat = (float)$this->candidate_value($cand, 'lat');
            $cand_lng = (float)$this->candidate_value($cand, 'lng');
            if ($cand_id === null || $cand_type === null || !$cand_lat || !$cand_lng) {
                continue;
            }

            $distance_m = null;
            $dist_km = $this->candidate_value($cand, 'dist_km');
            if ($dist_km !== null) {
                $distance_m = (float)$dist_km * 1000;
            }
            if ($distance_m === null || !is_finite($distance_m)) {
                $distance_m = $this->haversine_m($origin_lat, $origin_lng, $cand_lat, $cand_lng);
            }

            $duration_s = (int) round($distance_m / $speed_mps);

            $items[] = array(
                'id' => (int)$cand_id,
                'post_type' => (string)$cand_type,
                'duration_s' => max(0, $duration_s),
                'distance_m' => (int) round($distance_m),
                'walk_m' => (int) round($distance_m),
                'secs' => max(0, $duration_s),
                'provider' => 'basic.haversine',
                'profile' => 'foot-walking',
            );
        }

        usort($items, function ($a, $b) {
            return $a['duration_s'] <=> $b['duration_s'];
        });

        return $items;
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
