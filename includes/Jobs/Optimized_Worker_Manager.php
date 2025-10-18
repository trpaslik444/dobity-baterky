<?php
/**
 * Optimized Worker Manager - Správa optimalizovaných workerů
 * @package DobityBaterky
 */

namespace DB\Jobs;

class Optimized_Worker_Manager {
    
    private static $instance = null;
    
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    /**
     * Zkontrolovat, zda je potřeba zpracovat bod
     */
    public static function needs_processing($point_id, $point_type) {
        $cache_keys = self::get_cache_keys($point_type);
        $needs_processing = false;
        $missing_caches = array();
        
        foreach ($cache_keys as $cache_key) {
            $cache_data = get_post_meta($point_id, $cache_key, true);
            
            if (empty($cache_data)) {
                $needs_processing = true;
                $missing_caches[] = $cache_key;
                continue;
            }
            
            $payload = is_string($cache_data) ? json_decode($cache_data, true) : $cache_data;
            $computed_at = $payload['computed_at'] ?? null;
            
            if (!$computed_at) {
                $needs_processing = true;
                $missing_caches[] = $cache_key;
                continue;
            }
            
            $age_days = (time() - strtotime($computed_at)) / DAY_IN_SECONDS;
            
            if ($age_days > 30) {
                $needs_processing = true;
                $missing_caches[] = $cache_key;
            }
        }
        
        return array(
            'needs_processing' => $needs_processing,
            'missing_caches' => $missing_caches,
            'reason' => $needs_processing ? 'Cache chybí nebo je starší než 30 dní' : 'Cache je aktuální'
        );
    }
    
    /**
     * Zpracovat bod na požádání
     */
    public static function process_on_demand($point_id, $point_type, $priority = 'normal') {
        $start_time = microtime(true);
        
        // Zkontrolovat, zda je potřeba zpracování
        $check_result = self::needs_processing($point_id, $point_type);
        
        if (!$check_result['needs_processing']) {
            return array(
                'status' => 'cached',
                'message' => 'Data jsou aktuální, zpracování není potřeba',
                'processing_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
            );
        }
        
        // Zpracovat podle priority
        if ($priority === 'high') {
            return self::process_sync($point_id, $point_type);
        } else {
            return self::process_async($point_id, $point_type);
        }
    }
    
    /**
     * Synchronní zpracování
     */
    private static function process_sync($point_id, $point_type) {
        $start_time = microtime(true);
        
        try {
            // Zpracovat nearby data
            $nearby_result = self::process_nearby_sync($point_id, $point_type);
            
            // Zpracovat isochrony
            $isochrones_result = self::process_isochrones_sync($point_id, $point_type);
            
            $processing_time = round((microtime(true) - $start_time) * 1000, 2);
            
            return array(
                'status' => 'completed',
                'nearby' => $nearby_result,
                'isochrones' => $isochrones_result,
                'processing_time' => $processing_time . 'ms'
            );
            
        } catch (\Exception $e) {
            return array(
                'status' => 'error',
                'error' => $e->getMessage(),
                'processing_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms'
            );
        }
    }
    
    /**
     * Asynchronní zpracování
     */
    private static function process_async($point_id, $point_type) {
        // Spustit asynchronní worker
        $token = wp_generate_password(24, false, false);
        set_transient('db_ondemand_token_' . $point_id, $token, 300);
        
        $url = rest_url('db/v1/ondemand/process');
        $args = array(
            'timeout' => 0.01,
            'blocking' => false,
            'body' => array(
                'point_id' => $point_id,
                'point_type' => $point_type,
                'token' => $token,
                'priority' => 'normal'
            ),
        );
        
        wp_remote_post($url, $args);
        
        return array(
            'status' => 'processing',
            'message' => 'Zpracování spuštěno asynchronně',
            'check_url' => rest_url('db/v1/ondemand/status/' . $point_id)
        );
    }
    
    /**
     * Synchronní zpracování nearby dat
     */
    private static function process_nearby_sync($point_id, $point_type) {
        $recompute_job = new Nearby_Recompute_Job();
        
        // Získat souřadnice
        $post = get_post($point_id);
        if (!$post) {
            throw new \Exception('Bod nenalezen');
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
            throw new \Exception('Neplatné souřadnice');
        }
        
        // Zpracovat nearby data
        $result = $recompute_job->recompute_nearby_for_origin($point_id, $point_type);
        
        return $result;
    }
    
    /**
     * Synchronní zpracování isochron
     */
    private static function process_isochrones_sync($point_id, $point_type) {
        // Implementovat isochrony zpracování
        // Prozatím vracíme placeholder
        return array(
            'status' => 'placeholder',
            'message' => 'Isochrony zpracování bude implementováno'
        );
    }
    
    /**
     * Získat cache klíče podle typu bodu
     */
    private static function get_cache_keys($point_type) {
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
     * Získat statistiky zpracování
     */
    public static function get_processing_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Počet bodů s aktuálním cache
        $cache_keys = array(
            '_db_nearby_cache_poi_foot',
            '_db_nearby_cache_charger_foot',
            '_db_nearby_cache_rv_foot',
            '_db_isochrones_v1_foot-walking'
        );
        
        foreach ($cache_keys as $cache_key) {
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s",
                $cache_key
            ));
            
            $stats[$cache_key] = (int)$count;
        }
        
        // Počet bodů bez cache
        $total_points = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->posts} 
            WHERE post_type IN ('charging_location', 'poi', 'rv_spot') 
            AND post_status = 'publish'
        ");
        
        $stats['total_points'] = (int)$total_points;
        $stats['cached_points'] = array_sum($stats);
        $stats['uncached_points'] = $stats['total_points'] - $stats['cached_points'];
        
        return $stats;
    }
}
