<?php
/**
 * On-Demand Processor - Procesor pro on-demand zpracování dat
 * @package DobityBaterky
 */

namespace DB\Jobs;

use DB\Jobs\Nearby_Recompute_Job;
use DB\Jobs\POI_Discovery_Job;
use DB\Jobs\Charging_Discovery_Job;

class On_Demand_Processor {
    
    private $nearby_job;
    private $poi_job;
    private $charging_job;
    
    public function __construct() {
        $this->nearby_job = new Nearby_Recompute_Job();
        // $this->poi_job = new POI_Discovery_Job(); // Třída neexistuje
        // $this->charging_job = new Charging_Discovery_Job(); // Třída neexistuje
    }
    
    /**
     * Zpracuje bod on-demand s optimalizacemi
     */
    public function process_point(int $point_id, string $point_type, array $options = array()): array {
        $start_time = microtime(true);
        
        // Výchozí možnosti
        $options = array_merge(array(
            'priority' => 'normal',
            'force_refresh' => false,
            'include_nearby' => true,
            'include_discovery' => true,
            'cache_duration' => 3600 // 1 hodina
        ), $options);
        
        // Zkontrolovat cache (pokud není force_refresh)
        if (!$options['force_refresh']) {
            $cache_key = "db_ondemand_{$point_id}_{$point_type}";
            $cached_result = wp_cache_get($cache_key, 'db_ondemand');
            
            if ($cached_result !== false) {
                return array_merge($cached_result, array(
                    'status' => 'cached',
                    'processing_time' => round((microtime(true) - $start_time) * 1000, 2) . 'ms',
                    'cached' => true
                ));
            }
        }
        
        $result = array(
            'point_id' => $point_id,
            'point_type' => $point_type,
            'priority' => $options['priority'],
            'status' => 'processing',
            'cached' => false,
            'start_time' => date('Y-m-d H:i:s'),
            'operations' => array()
        );
        
        try {
            // 1. Zkontrolovat existenci bodu
            $this->validate_point($point_id, $point_type);
            $result['operations'][] = 'validation_passed';
            
            // 2. Zpracovat nearby data (pokud je požadováno)
            if ($options['include_nearby']) {
                $nearby_result = $this->process_nearby_data($point_id, $point_type, $options['priority']);
                $result['nearby'] = $nearby_result;
                $result['operations'][] = 'nearby_processed';
            }
            
            // 2a. Zpracovat isochrony nezávisle na nearby datech
            $isochrones_result = $this->process_isochrones_independent($point_id, $point_type);
            $result['isochrones'] = $isochrones_result;
            $result['operations'][] = 'isochrones_processed';
            
            // 3. Zpracovat discovery data (pokud je požadováno)
            if ($options['include_discovery']) {
                $discovery_result = $this->process_discovery_data($point_id, $point_type, $options['priority']);
                $result['discovery'] = $discovery_result;
                $result['operations'][] = 'discovery_processed';
            }
            
            // 4. Zkontrolovat výsledky
            $validation_result = $this->validate_processing_results($point_id, $point_type);
            $result['validation'] = $validation_result;
            $result['operations'][] = 'validation_completed';
            
            $result['status'] = 'completed';
            
            // Načíst nearby data a isochrony pro frontend
            $nearby_data = $this->get_nearby_data_for_frontend($point_id, $point_type);
            if ($nearby_data) {
                $result['items'] = $nearby_data['items'] ?? [];
                $result['isochrones'] = $nearby_data['isochrones'] ?? null;
            } else {
                // Pokud nearby data nejsou, zkusit načíst alespoň isochrony
                $isochrones_keys = array(
                    'db_isochrones_v1_foot-walking',
                    '_db_isochrones_cache'
                );
                
                foreach ($isochrones_keys as $key) {
                    $data = get_post_meta($point_id, $key, true);
                    if ($data) {
                        $isochrones_data = is_string($data) ? json_decode($data, true) : $data;
                        if ($isochrones_data && isset($isochrones_data['geojson']) && isset($isochrones_data['geojson']['features'])) {
                            // Přidat user_settings pokud chybí
                            if (!isset($isochrones_data['user_settings'])) {
                                $isochrones_data['user_settings'] = array(
                                    'enabled' => true,
                                    'walking_speed' => 4.5
                                );
                            }
                            $result['items'] = [];
                            $result['isochrones'] = $isochrones_data;
                            break;
                        }
                    }
                }
            }
            
        } catch (\Exception $e) {
            $result['status'] = 'error';
            $result['error'] = $e->getMessage();
            $result['operations'][] = 'error_occurred';
        }
        
        $result['processing_time'] = round((microtime(true) - $start_time) * 1000, 2) . 'ms';
        $result['end_time'] = date('Y-m-d H:i:s');
        
        // Cache výsledek
        $cache_key = "db_ondemand_{$point_id}_{$point_type}";
        wp_cache_set($cache_key, $result, 'db_ondemand', $options['cache_duration']);
        
        return $result;
    }
    
    /**
     * Načte nearby data a isochrony pro frontend
     */
    private function get_nearby_data_for_frontend(int $point_id, string $point_type): ?array {
        try {
            // Načíst nearby data z databáze - správné meta klíče podle typu
            $nearby_data = null;
            $isochrones_data = null;
            
            // Zjistit origin post type pro správné přemapování
            $origin_post = get_post($point_id);
            $origin_post_type = $origin_post ? $origin_post->post_type : $point_type;
            
            // Určit správný meta klíč podle typu - musí odpovídat tomu, jak se typ přemapoval v recompute_nearby_for_origin
            // Pokud je origin poi, hledá se charging_location (a ukládá se pod _db_nearby_cache_charger_foot)
            // Pokud je origin charging_location, hledá se poi (a ukládá se pod _db_nearby_cache_poi_foot)
            $meta_key = null;
            if ($origin_post_type === 'poi') {
                // Pro POI origin se hledají charging locations, ukládá se pod charger_foot
                $meta_key = '_db_nearby_cache_charger_foot';
            } elseif ($origin_post_type === 'charging_location') {
                // Pro charging_location origin se hledají POI, ukládá se pod poi_foot
                $meta_key = '_db_nearby_cache_poi_foot';
            } elseif ($origin_post_type === 'rv_spot') {
                // Pro rv_spot origin se hledají charging locations, ukládá se pod charger_foot
                $meta_key = '_db_nearby_cache_charger_foot';
            } else {
                // Fallback na původní logiku
                $meta_key = ($point_type === 'poi') ? '_db_nearby_cache_poi_foot' : 
                           (($point_type === 'rv_spot') ? '_db_nearby_cache_rv_foot' : '_db_nearby_cache_charger_foot');
            }
            
            // Zkusit různé meta klíče pro nearby data - nejdříve správný podle origin typu
            $nearby_keys = array(
                $meta_key,
                '_db_nearby_cache_poi_foot',
                '_db_nearby_cache_charging_location_foot',
                '_db_nearby_cache_charger_foot',
                '_db_nearby_cache_rv_foot',
                '_db_nearby_data'
            );
            
            foreach ($nearby_keys as $key) {
                $data = get_post_meta($point_id, $key, true);
                if ($data) {
                    $nearby_data = is_string($data) ? json_decode($data, true) : $data;
                    break;
                }
            }
            
            // Načíst isochrony - správné meta klíče
            $isochrones_keys = array(
                'db_isochrones_v1_foot-walking',
                '_db_isochrones_cache'
            );
            
            foreach ($isochrones_keys as $key) {
                $data = get_post_meta($point_id, $key, true);
                if ($data) {
                    $isochrones_data = is_string($data) ? json_decode($data, true) : $data;
                    break;
                }
            }
            
            // Přidat user_settings do isochrony dat pro frontend
            if ($isochrones_data && !isset($isochrones_data['user_settings'])) {
                $isochrones_data['user_settings'] = array(
                    'enabled' => true,
                    'walking_speed' => 4.5
                );
            }
            
            // Vrátit data, i když nemáme nearby items, ale máme isochrony
            if (!$nearby_data && $isochrones_data) {
                return array(
                    'items' => [],
                    'isochrones' => $isochrones_data
                );
            }
            
            // Pokud máme nearby data, vždy vrátit i isochrony (pokud jsou)
            if ($nearby_data) {
                return array(
                    'items' => $nearby_data['items'] ?? [],
                    'isochrones' => $isochrones_data
                );
            }
            
            // Pokud nemáme ani nearby data ani isochrony, vrátit null
            return null;
            
        } catch (\Exception $e) {
            return null;
        }
    }
    
    /**
     * Zkontroluje existenci a platnost bodu
     */
    private function validate_point(int $point_id, string $point_type): void {
        $post = get_post($point_id);
        
        if (!$post) {
            throw new \Exception("Bod s ID {$point_id} neexistuje");
        }
        
        if ($post->post_type !== $point_type) {
            throw new \Exception("Bod s ID {$point_id} má typ '{$post->post_type}', očekáváno '{$point_type}'");
        }
        
        if ($post->post_status !== 'publish') {
            throw new \Exception("Bod s ID {$point_id} není publikován");
        }
    }
    
    /**
     * Zpracuje nearby data
     */
    private function process_nearby_data(int $point_id, string $point_type, string $priority): array {
        $result = array(
            'processed' => 0,
            'errors' => 0,
            'cached' => false
        );
        
        try {
            // Zkontrolovat cache pro nearby data
            $cache_key = "db_nearby_processed_{$point_id}_{$point_type}";
            $cached_nearby = wp_cache_get($cache_key, 'db_nearby');
            
            if ($cached_nearby !== false) {
                $result['cached'] = true;
                $result['cached_at'] = $cached_nearby['cached_at'] ?? 'unknown';
                return $result;
            }
            
            // Zpracovat nearby data - typ se automaticky přemapuje v recompute_nearby_for_origin
            // Předat originální point_type pro automatické přemapování v recompute_nearby_for_origin
            $search_type = $point_type; // Předat originální typ pro automatické přemapování
            
            // Log pro debug
            error_log("[On-Demand] Calling recompute_nearby_for_origin for point_id={$point_id}, point_type={$point_type}");

            $nearby_result = $this->nearby_job->recompute_nearby_for_origin($point_id, $point_type);
            
            
            error_log("[On-Demand] recompute_nearby_for_origin result: " . print_r($nearby_result, true));
            
            // recompute_nearby_for_origin nevrací hodnotu (void), jen ukládá data do DB
            $result['processed'] = 1;
            $result['errors'] = 0;
            $result['cached'] = false;
            
            // Cache nearby výsledek
            $nearby_cache_data = array_merge($result, array(
                'cached_at' => date('Y-m-d H:i:s')
            ));
            wp_cache_set($cache_key, $nearby_cache_data, 'db_nearby', 3600);
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['errors']++;
        }
        
        return $result;
    }
    
    /**
     * Zpracuje isochrony nezávisle na nearby datech
     */
    private function process_isochrones_independent(int $point_id, string $point_type): array {
        $result = array(
            'processed' => 0,
            'errors' => 0,
            'cached' => false
        );
        
        try {
            // Získat souřadnice bodu
            $post = get_post($point_id);
            if (!$post) {
                return $result;
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
                return $result;
            }
            
            // Zkontrolovat, zda už máme isochrones data
            $meta_key = 'db_isochrones_v1_foot-walking';
            $existing_cache = get_post_meta($point_id, $meta_key, true);
            
            if ($existing_cache && !empty($existing_cache)) {
                $payload = is_string($existing_cache) ? json_decode($existing_cache, true) : $existing_cache;
                // Pokud máme platná data a nejsou starší než TTL, nepřenačítat
                if ($payload && isset($payload['geojson']) && isset($payload['geojson']['features']) && 
                    !empty($payload['geojson']['features']) && !isset($payload['error'])) {
                    $ttl_days = 30;
                    $computed_at = isset($payload['computed_at']) ? strtotime($payload['computed_at']) : 0;
                    if ((time() - $computed_at) < ($ttl_days * DAY_IN_SECONDS)) {
                        $result['cached'] = true;
                        return $result; // Data jsou ještě platná
                    }
                }
            }
            
            // Zkontrolovat konfiguraci
            $cfg = get_option('db_nearby_config', []);
            $orsKey = trim((string)($cfg['ors_api_key'] ?? ''));
            $provider = (string)($cfg['provider'] ?? 'ors');
            
            if ($provider !== 'ors' || empty($orsKey)) {
                $result['error'] = 'ORS provider not configured';
                $result['errors']++;
                return $result;
            }
            
            // Zavolat fetch_and_cache_isochrones přes nearby_job
            // Musíme použít reflexi, protože metoda je private
            $reflection = new \ReflectionClass($this->nearby_job);
            $method = $reflection->getMethod('fetch_and_cache_isochrones');
            $method->setAccessible(true);
            $method->invoke($this->nearby_job, $point_id, $lat, $lng, $orsKey);
            
            $result['processed'] = 1;
            error_log("[On-Demand] Isochrones processed for point_id={$point_id}");
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['errors']++;
            error_log("[On-Demand] Isochrones error for point_id={$point_id}: " . $e->getMessage());
        }
        
        return $result;
    }
    
    /**
     * Zpracuje discovery data
     */
    private function process_discovery_data(int $point_id, string $point_type, string $priority): array {
        $result = array(
            'google_place_id' => null,
            'tripadvisor_id' => null,
            'ocm_id' => null,
            'processed' => 0,
            'errors' => 0
        );
        
        try {
            // Zkontrolovat existující data
            $google_place_id = get_post_meta($point_id, '_google_place_id', true);
            $tripadvisor_id = get_post_meta($point_id, '_tripadvisor_id', true);
            $ocm_id = get_post_meta($point_id, '_ocm_id', true);
            
            $result['google_place_id'] = $google_place_id;
            $result['tripadvisor_id'] = $tripadvisor_id;
            $result['ocm_id'] = $ocm_id;
            
            // Zpracovat discovery pouze pokud chybí data
            // Discovery proces je dočasně zakázán - třídy neexistují
            /*
            if (empty($google_place_id) || empty($tripadvisor_id) || empty($ocm_id)) {
                if ($point_type === 'poi' && (empty($google_place_id) || empty($tripadvisor_id))) {
                    $poi_result = $this->poi_job->process_single($point_id);
                    $result['processed'] += $poi_result['processed'] ?? 0;
                    $result['errors'] += $poi_result['errors'] ?? 0;
                }
                
                if ($point_type === 'charging_location' && empty($ocm_id)) {
                    $charging_result = $this->charging_job->process_single($point_id);
                    $result['processed'] += $charging_result['processed'] ?? 0;
                    $result['errors'] += $charging_result['errors'] ?? 0;
                }
            }
            */
            
        } catch (\Exception $e) {
            $result['error'] = $e->getMessage();
            $result['errors']++;
        }
        
        return $result;
    }
    
    /**
     * Zkontroluje výsledky zpracování
     */
    private function validate_processing_results(int $point_id, string $point_type): array {
        $result = array(
            'coordinates' => false,
            'google_place_id' => false,
            'tripadvisor_id' => false,
            'ocm_id' => false,
            'nearby_data' => false
        );
        
        // Zkontrolovat souřadnice
        $lat_key = "_{$point_type}_lat";
        $lng_key = "_{$point_type}_lng";
        
        $lat = get_post_meta($point_id, $lat_key, true);
        $lng = get_post_meta($point_id, $lng_key, true);
        
        if (!empty($lat) && !empty($lng)) {
            $result['coordinates'] = true;
        }
        
        // Zkontrolovat Google Places ID
        $google_place_id = get_post_meta($point_id, '_google_place_id', true);
        if (!empty($google_place_id)) {
            $result['google_place_id'] = true;
        }
        
        // Zkontrolovat Tripadvisor ID
        $tripadvisor_id = get_post_meta($point_id, '_tripadvisor_id', true);
        if (!empty($tripadvisor_id)) {
            $result['tripadvisor_id'] = true;
        }
        
        // Zkontrolovat OCM ID
        $ocm_id = get_post_meta($point_id, '_ocm_id', true);
        if (!empty($ocm_id)) {
            $result['ocm_id'] = true;
        }
        
        // Zkontrolovat nearby data
        $nearby_cache_key = "db_nearby_processed_{$point_id}_{$point_type}";
        $nearby_cached = wp_cache_get($nearby_cache_key, 'db_nearby');
        if ($nearby_cached !== false) {
            $result['nearby_data'] = true;
        }
        
        return $result;
    }
    
    /**
     * Zkontroluje stav zpracování bodu
     */
    public static function check_processing_status(int $point_id, ?string $point_type = null): array {
        // Zkusit různé typy, pokud není specifikován
        $types_to_check = $point_type ? [$point_type] : ['poi', 'charging_location', 'rv_spot'];
        
        foreach ($types_to_check as $type) {
            $cache_key = "db_ondemand_{$point_id}_{$type}";
            $cached_result = wp_cache_get($cache_key, 'db_ondemand');
            
            if ($cached_result !== false) {
                return array(
                    'status' => 'completed',
                    'point_id' => $point_id,
                    'point_type' => $type,
                    'items' => $cached_result['items'] ?? [],
                    'isochrones' => $cached_result['isochrones'] ?? null,
                    'cached_at' => $cached_result['cached_at'] ?? 'unknown',
                    'processing_time' => $cached_result['processing_time'] ?? 'unknown'
                );
            }
        }
        
        // Zkusit načíst data z databáze
        $processor = new self();
        $nearby_data = $processor->get_nearby_data_for_frontend($point_id, $point_type ?: 'poi');
        
        error_log("[On-Demand] check_processing_status - nearby_data exists: " . ($nearby_data ? 'YES' : 'NO'));
        if ($nearby_data) {
            error_log("[On-Demand] check_processing_status - items: " . count($nearby_data['items'] ?? []));
            error_log("[On-Demand] check_processing_status - isochrones: " . (isset($nearby_data['isochrones']) ? 'YES' : 'NO'));
        }
        
        // Vrátit data, pokud máme nearby items NEBO isochrony
        if ($nearby_data && (!empty($nearby_data['items']) || !empty($nearby_data['isochrones']))) {
            error_log("[On-Demand] check_processing_status - returning completed status with data");
            return array(
                'status' => 'completed',
                'point_id' => $point_id,
                'point_type' => $point_type ?: 'poi',
                'items' => $nearby_data['items'] ?? [],
                'isochrones' => $nearby_data['isochrones'] ?? null,
                'cached_at' => 'database',
                'processing_time' => 'unknown'
            );
        }
        
        error_log("[On-Demand] check_processing_status - no data found, returning not_cached");
        return array(
            'status' => 'not_cached',
            'message' => 'Bod nebyl zpracován nebo cache vypršel'
        );
    }
    
    /**
     * Získá seznam bodů k zpracování
     */
    public function get_points_to_process(string $point_type, int $limit = 100): array {
        global $wpdb;
        
        // Mapování meta klíčů podle typu postu
        $meta_key = $this->get_lat_meta_key_for_type($point_type);
        
        $sql = $wpdb->prepare("
            SELECT p.ID, p.post_title, p.post_type, p.post_date
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID 
                AND pm.meta_key = %s
            WHERE p.post_type = %s 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            ORDER BY p.post_date DESC
            LIMIT %d
        ", $meta_key, $point_type, $limit);
        
        return $wpdb->get_results($sql, ARRAY_A);
    }
    
    /**
     * Získá správný meta klíč pro lat podle typu postu
     */
    private function get_lat_meta_key_for_type(string $point_type): string {
        switch ($point_type) {
            case 'charging_location':
                return '_db_lat';
            case 'poi':
                return '_poi_lat';
            case 'rv_spot':
                return '_rv_lat';
            default:
                return '_db_lat';
        }
    }
    
    /**
     * Zpracuje více bodů najednou
     */
    public function process_bulk(array $point_ids, string $point_type, array $options = array()): array {
        $results = array();
        $total_processed = 0;
        $total_errors = 0;
        
        foreach ($point_ids as $point_id) {
            try {
                $result = $this->process_point($point_id, $point_type, $options);
                $results[] = $result;
                
                if ($result['status'] === 'completed') {
                    $total_processed++;
                } else {
                    $total_errors++;
                }
                
            } catch (\Exception $e) {
                $results[] = array(
                    'point_id' => $point_id,
                    'point_type' => $point_type,
                    'status' => 'error',
                    'error' => $e->getMessage()
                );
                $total_errors++;
            }
        }
        
        return array(
            'total_points' => count($point_ids),
            'processed' => $total_processed,
            'errors' => $total_errors,
            'results' => $results
        );
    }
    
    /**
     * Vymaže cache pro bod
     */
    public function clear_point_cache(int $point_id, string $point_type): bool {
        $cache_key = "db_ondemand_{$point_id}_{$point_type}";
        $nearby_cache_key = "db_nearby_processed_{$point_id}_{$point_type}";
        
        wp_cache_delete($cache_key, 'db_ondemand');
        wp_cache_delete($nearby_cache_key, 'db_nearby');
        
        return true;
    }
    
    /**
     * Vymaže všechny cache
     */
    public function clear_all_cache(): bool {
        wp_cache_flush();
        return true;
    }
}