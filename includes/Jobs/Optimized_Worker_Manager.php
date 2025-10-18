<?php
/**
 * Optimized Worker Manager - Optimalizovaný správce workerů pro on-demand zpracování
 * @package DobityBaterky
 */

namespace DB\Jobs;

use DB\Jobs\On_Demand_Processor;
use DB\Jobs\Nearby_Recompute_Job;
use DB\Jobs\POI_Discovery_Job;
use DB\Jobs\Charging_Discovery_Job;

class Optimized_Worker_Manager {
    
    private $on_demand_processor;
    private $nearby_job;
    private $poi_job;
    private $charging_job;
    
    public function __construct() {
        $this->on_demand_processor = new On_Demand_Processor();
        $this->nearby_job = new Nearby_Recompute_Job();
        $this->poi_job = new POI_Discovery_Job();
        $this->charging_job = new Charging_Discovery_Job();
    }
    
    /**
     * Zpracuje bod on-demand s optimalizacemi
     */
    public function process_on_demand(int $point_id, string $point_type, string $priority = 'normal'): array {
        $start_time = microtime(true);
        
        // Zkontrolovat cache
        $cache_key = "db_ondemand_processed_{$point_id}_{$point_type}";
        $cached_result = wp_cache_get($cache_key, 'db_ondemand');
        
        if ($cached_result !== false) {
            return array_merge($cached_result, array(
                'status' => 'cached',
                'processing_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms',
                'cached' => true
            ));
        }
        
        // Zpracovat podle typu
        $result = array(
            'point_id' => $point_id,
            'point_type' => $point_type,
            'priority' => $priority,
            'status' => 'processing',
            'cached' => false
        );
        
        try {
            switch ($point_type) {
                case 'charging_location':
                    $result = array_merge($result, $this->process_charging_location($point_id, $priority));
                    break;
                    
                case 'poi':
                    $result = array_merge($result, $this->process_poi($point_id, $priority));
                    break;
                    
                case 'rv_spot':
                    $result = array_merge($result, $this->process_rv_spot($point_id, $priority));
                    break;
                    
                default:
                    throw new \Exception("Neznámý typ bodu: {$point_type}");
            }
            
            $result['status'] = 'completed';
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
        }
        
        $result['processing_time'] = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
        
        // Cache výsledek na 1 hodinu
        wp_cache_set($cache_key, $result, 'db_ondemand', 3600);
        
        return $result;
    }
    
    /**
     * Zpracuje charging location
     */
    private function process_charging_location(int $point_id, string $priority): array {
        $result = array();
        
        // 1. Zkontrolovat existenci
        $post = get_post($point_id);
        if (!$post || $post->post_type !== 'charging_location') {
            throw new \Exception("Charging location s ID {$point_id} neexistuje");
        }
        
        // 2. Zpracovat nearby data
        if ($priority === 'high') {
            $nearby_result = $this->nearby_job->recompute($point_id, 'charging_location');
            $result['nearby_processed'] = $nearby_result['processed'] ?? 0;
            $result['nearby_errors'] = $nearby_result['errors'] ?? 0;
        }
        
        // 3. Zkontrolovat Google Places data
        $google_place_id = get_post_meta($point_id, '_google_place_id', true);
        if (empty($google_place_id)) {
            $result['google_place_id'] = 'missing';
        } else {
            $result['google_place_id'] = $google_place_id;
        }
        
        // 4. Zkontrolovat OCM data
        $ocm_id = get_post_meta($point_id, '_ocm_id', true);
        if (empty($ocm_id)) {
            $result['ocm_id'] = 'missing';
        } else {
            $result['ocm_id'] = $ocm_id;
        }
        
        return $result;
    }
    
    /**
     * Zpracuje POI
     */
    private function process_poi(int $point_id, string $priority): array {
        $result = array();
        
        // 1. Zkontrolovat existenci
        $post = get_post($point_id);
        if (!$post || $post->post_type !== 'poi') {
            throw new \Exception("POI s ID {$point_id} neexistuje");
        }
        
        // 2. Zpracovat nearby data
        if ($priority === 'high') {
            $nearby_result = $this->nearby_job->recompute($point_id, 'poi');
            $result['nearby_processed'] = $nearby_result['processed'] ?? 0;
            $result['nearby_errors'] = $nearby_result['errors'] ?? 0;
        }
        
        // 3. Zkontrolovat Google Places data
        $google_place_id = get_post_meta($point_id, '_google_place_id', true);
        if (empty($google_place_id)) {
            $result['google_place_id'] = 'missing';
        } else {
            $result['google_place_id'] = $google_place_id;
        }
        
        // 4. Zkontrolovat Tripadvisor data
        $tripadvisor_id = get_post_meta($point_id, '_tripadvisor_id', true);
        if (empty($tripadvisor_id)) {
            $result['tripadvisor_id'] = 'missing';
        } else {
            $result['tripadvisor_id'] = $tripadvisor_id;
        }
        
        return $result;
    }
    
    /**
     * Zpracuje RV spot
     */
    private function process_rv_spot(int $point_id, string $priority): array {
        $result = array();
        
        // 1. Zkontrolovat existenci
        $post = get_post($point_id);
        if (!$post || $post->post_type !== 'rv_spot') {
            throw new \Exception("RV spot s ID {$point_id} neexistuje");
        }
        
        // 2. Zpracovat nearby data
        if ($priority === 'high') {
            $nearby_result = $this->nearby_job->recompute($point_id, 'rv_spot');
            $result['nearby_processed'] = $nearby_result['processed'] ?? 0;
            $result['nearby_errors'] = $nearby_result['errors'] ?? 0;
        }
        
        // 3. Zkontrolovat základní data
        $lat = get_post_meta($point_id, '_rv_lat', true);
        $lng = get_post_meta($point_id, '_rv_lng', true);
        
        if (empty($lat) || empty($lng)) {
            $result['coordinates'] = 'missing';
        } else {
            $result['coordinates'] = "{$lat}, {$lng}";
        }
        
        return $result;
    }
    
    /**
     * Získá statistiky zpracování
     */
    public function get_processing_stats(): array {
        global $wpdb;
        
        // Celkový počet bodů
        $total_points = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type IN ('charging_location', 'poi', 'rv_spot') 
            AND post_status = 'publish'
        ");
        
        // Počet bodů s cache
        $cached_points = $wpdb->get_var("
            SELECT COUNT(DISTINCT post_id) 
            FROM {$wpdb->postmeta} 
            WHERE meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')
            AND meta_value != ''
        ");
        
        return array(
            'total_points' => intval($total_points),
            'cached_points' => intval($cached_points),
            'uncached_points' => intval($total_points) - intval($cached_points),
            'cache_coverage' => $total_points > 0 ? round(($cached_points / $total_points) * 100, 2) : 0
        );
    }
    
    /**
     * Získá statistiky výkonu
     */
    public function get_performance_stats(): array {
        $cache_stats = wp_cache_get('db_performance_stats', 'db_stats');
        
        if ($cache_stats === false) {
            $cache_stats = array(
                'total_requests' => 0,
                'cached_requests' => 0,
                'avg_processing_time' => 0,
                'error_rate' => 0
            );
        }
        
        return $cache_stats;
    }
    
    /**
     * Aktualizuje statistiky výkonu
     */
    public function update_performance_stats(array $stats): void {
        wp_cache_set('db_performance_stats', $stats, 'db_stats', 3600);
    }
    
    /**
     * Získá seznam bodů k zpracování
     */
    public function get_points_to_process(string $point_type, int $limit = 100): array {
        global $wpdb;
        
        $sql = $wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_type
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID 
                AND pm.meta_key = %s
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.post_date DESC
            LIMIT %d
        ", "_{$point_type}_lat", $point_type, $limit);
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Zkontroluje stav zpracování bodu
     */
    public function check_point_status(int $point_id, string $point_type): array {
        $cache_key = "db_ondemand_processed_{$point_id}_{$point_type}";
        $cached_result = wp_cache_get($cache_key, 'db_ondemand');
        
        if ($cached_result !== false) {
            return array(
                'status' => 'cached',
                'cached_at' => $cached_result['cached_at'] ?? 'unknown',
                'processing_time' => $cached_result['processing_time'] ?? 'unknown'
            );
        }
        
        // Zkontrolovat základní data
        $post = get_post($point_id);
        if (!$post || $post->post_type !== $point_type) {
            return array(
                'status' => 'not_found',
                'error' => 'Bod neexistuje nebo má špatný typ'
            );
        }
        
        // Zkontrolovat souřadnice
        $lat_key = "_{$point_type}_lat";
        $lng_key = "_{$point_type}_lng";
        
        $lat = get_post_meta($point_id, $lat_key, true);
        $lng = get_post_meta($point_id, $lng_key, true);
        
        if (empty($lat) || empty($lng)) {
            return array(
                'status' => 'missing_coordinates',
                'error' => 'Chybí souřadnice'
            );
        }
        
        return array(
            'status' => 'ready',
            'coordinates' => "{$lat}, {$lng}",
            'last_modified' => $post->post_modified
        );
    }
}