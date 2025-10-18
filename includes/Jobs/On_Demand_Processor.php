<?php
/**
 * On-Demand Processor - Zpracování na požádání při uživatelské interakci
 * @package DobityBaterky
 */

namespace DB\Jobs;

class On_Demand_Processor {
    
    private const CACHE_TTL_DAYS = 30;
    private const PROCESSING_TIMEOUT = 30; // sekund
    
    /**
     * Zpracovat bod na požádání při uživatelské interakci
     */
    public static function process_point_on_demand($point_id, $point_type) {
        $start_time = microtime(true);
        
        // 1. Zkontrolovat cache (30 dní)
        $cache_status = self::check_cache_status($point_id, $point_type);
        
        if ($cache_status['is_fresh']) {
            return array(
                'status' => 'cached',
                'data' => $cache_status['data'],
                'message' => 'Data jsou aktuální',
                'processing_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
            );
        }
        
        // 2. Zobrazit loading UI uživateli
        $loading_response = self::show_loading_ui($point_id, $point_type);
        
        // 3. Asynchronně zpracovat data
        $processing_result = self::process_async($point_id, $point_type);
        
        return array(
            'status' => 'processing',
            'loading_ui' => $loading_response,
            'processing_result' => $processing_result,
            'estimated_time' => '10-30 sekund'
        );
    }
    
    /**
     * Zkontrolovat stav cache pro bod
     */
    private static function check_cache_status($point_id, $point_type) {
        $meta_keys = self::get_cache_meta_keys($point_type);
        $is_fresh = false;
        $data = null;
        
        foreach ($meta_keys as $meta_key) {
            $cache_data = get_post_meta($point_id, $meta_key, true);
            
            if (!empty($cache_data)) {
                $payload = is_string($cache_data) ? json_decode($cache_data, true) : $cache_data;
                $computed_at = $payload['computed_at'] ?? null;
                
                if ($computed_at) {
                    $age_days = (time() - strtotime($computed_at)) / DAY_IN_SECONDS;
                    
                    if ($age_days < self::CACHE_TTL_DAYS) {
                        $is_fresh = true;
                        $data = $payload;
                        break;
                    }
                }
            }
        }
        
        return array(
            'is_fresh' => $is_fresh,
            'data' => $data,
            'age_days' => $age_days ?? null
        );
    }
    
    /**
     * Zobrazit loading UI uživateli
     */
    private static function show_loading_ui($point_id, $point_type) {
        $loading_steps = array(
            '🔍 Hledám nearby body...',
            '📏 Vypočítávám vzdálenosti...',
            '🗺️ Generuji isochrony...',
            '💾 Ukládám data...',
            '✅ Hotovo!'
        );
        
        return array(
            'point_id' => $point_id,
            'point_type' => $point_type,
            'steps' => $loading_steps,
            'current_step' => 0,
            'progress' => 0,
            'estimated_time' => '10-30 sekund'
        );
    }
    
    /**
     * Asynchronně zpracovat data
     */
    private static function process_async($point_id, $point_type) {
        // Spustit asynchronní worker s vysokou prioritou
        $worker_token = wp_generate_password(24, false, false);
        
        // Uložit token pro ověření
        set_transient('db_ondemand_token_' . $point_id, $worker_token, 300); // 5 minut
        
        // Spustit worker
        $url = rest_url('db/v1/ondemand/process');
        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'point_id' => $point_id,
                'point_type' => $point_type,
                'token' => $worker_token,
                'priority' => 'high'
            ),
        );
        
        wp_remote_post($url, $args);
        
        return array(
            'status' => 'started',
            'token' => $worker_token,
            'check_url' => rest_url('db/v1/ondemand/status/' . $point_id)
        );
    }
    
    /**
     * Získat meta klíče pro cache podle typu bodu
     */
    private static function get_cache_meta_keys($point_type) {
        $keys = array();
        
        if ($point_type === 'charging_location') {
            $keys[] = '_db_nearby_cache_poi_foot';
            $keys[] = '_db_nearby_cache_charger_foot';
            $keys[] = '_db_isochrones_v1_foot-walking';
        } elseif ($point_type === 'poi') {
            $keys[] = '_db_nearby_cache_charger_foot';
            $keys[] = '_db_nearby_cache_rv_foot';
            $keys[] = '_db_isochrones_v1_foot-walking';
        } elseif ($point_type === 'rv_spot') {
            $keys[] = '_db_nearby_cache_poi_foot';
            $keys[] = '_db_nearby_cache_charger_foot';
            $keys[] = '_db_isochrones_v1_foot-walking';
        }
        
        return $keys;
    }
    
    /**
     * Zkontrolovat stav zpracování
     */
    public static function check_processing_status($point_id) {
        $token = get_transient('db_ondemand_token_' . $point_id);
        
        if (!$token) {
            return array(
                'status' => 'not_found',
                'message' => 'Zpracování nenalezeno nebo vypršelo'
            );
        }
        
        // Zkontrolovat cache status
        $cache_status = self::check_cache_status($point_id, 'auto');
        
        if ($cache_status['is_fresh']) {
            // Zpracování dokončeno
            delete_transient('db_ondemand_token_' . $point_id);
            
            return array(
                'status' => 'completed',
                'data' => $cache_status['data'],
                'message' => 'Zpracování dokončeno'
            );
        }
        
        return array(
            'status' => 'processing',
            'message' => 'Zpracování probíhá...'
        );
    }
    
    /**
     * Zpracovat bod synchronně (pro admin/testování)
     */
    public static function process_point_sync($point_id, $point_type) {
        $start_time = microtime(true);
        
        // Zkontrolovat cache
        $cache_status = self::check_cache_status($point_id, $point_type);
        
        if ($cache_status['is_fresh']) {
            return array(
                'status' => 'cached',
                'data' => $cache_status['data'],
                'processing_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
            );
        }
        
        // Zpracovat nearby data
        $nearby_result = self::process_nearby_data($point_id, $point_type);
        
        // Zpracovat isochrony
        $isochrones_result = self::process_isochrones($point_id, $point_type);
        
        $processing_time = round((microtime(true) - $start_time) * 1000, 2);
        
        return array(
            'status' => 'completed',
            'nearby' => $nearby_result,
            'isochrones' => $isochrones_result,
            'processing_time' => $processing_time . 'ms'
        );
    }
    
    /**
     * Zpracovat nearby data
     */
    private static function process_nearby_data($point_id, $point_type) {
        // Použít existující Nearby_Recompute_Job
        $recompute_job = new Nearby_Recompute_Job();
        
        // Získat souřadnice
        $post = get_post($point_id);
        if (!$post) {
            return array('error' => 'Bod nenalezen');
        }
        
        $lat = $lng = null;
        if ($post->post_type === 'charging_location') {
            $lat = (float)get_post_meta($point_id, '_db_lat', true);
            $lng = (float)get_post_meta($point_id, '_db_lng', true);
        } elseif ($post->post_type === 'poi') {
            $lat = (float)get_post_meta($point_id, '_poi_lat', true);
            $lng = (float)get_post_meta($point_id, '_poi_lng', true);
        } elseif ($post->post_type === 'rv_spot') {
            $lat = (float)get_post_meta($point_id, '_rv_lat', true);
            $lng = (float)get_post_meta($point_id, '_rv_lng', true);
        }
        
        if (!$lat || !$lng) {
            return array('error' => 'Neplatné souřadnice');
        }
        
        // Zpracovat nearby data
        $result = $recompute_job->recompute_nearby_for_origin($point_id, $point_type);
        
        return $result;
    }
    
    /**
     * Zpracovat isochrony
     */
    private static function process_isochrones($point_id, $point_type) {
        // Implementovat isochrony zpracování
        // Prozatím vracíme placeholder
        return array(
            'status' => 'placeholder',
            'message' => 'Isochrony zpracování bude implementováno'
        );
    }
}
