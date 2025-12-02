<?php
/**
 * Nearby Batch Processor - Zpracování fronty nearby bodů
 * @package DobityBaterky
 */

namespace DB\Jobs;

use DB\Jobs\Nearby_Logger;

class Nearby_Batch_Processor {
    
    private $queue_manager;
    private $recompute_job;
    private $config;
    
    public function __construct() {
        $this->queue_manager = new Nearby_Queue_Manager();
        $this->recompute_job = new Nearby_Recompute_Job();
        $this->config = get_option('db_nearby_config', array());
    }
    
    /**
     * Zpracovat dávku položek z fronty
     */
    public function process_batch($max_items = 1) {
        $processed = 0;
        $errors = 0;
        Nearby_Logger::log('BATCH', 'Process batch started', ['requested' => $max_items]);
        
        // Zkontrolovat API kvóty před zpracováním
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        if (!$quota_manager->can_process_queue()) {
            Nearby_Logger::log('BATCH', 'Terminating batch due to quota check failure', []);
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Nedostatečná API kvóta - čeká na reset'
            );
        }
        
        // Získat položky k zpracování
        $requested = max(1, (int)$max_items);
        $items = $this->queue_manager->get_pending_items($requested);
        Nearby_Logger::log('BATCH', 'Fetched pending items', ['requested' => $requested, 'found' => count($items)]);
        
        if (empty($items)) {
            Nearby_Logger::log('BATCH', 'No items to process', []);
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Žádné položky k zpracování'
            );
        }
        
        foreach ($items as $item) {
            try {
                Nearby_Logger::log('BATCH', 'Processing item', [
                    'queue_id' => $item->id,
                    'origin_id' => $item->origin_id,
                    'origin_type' => $item->origin_type,
                    'priority' => $item->priority,
                    'attempts' => $item->attempts,
                ]);
                // Označit jako zpracovávanou
                $this->queue_manager->mark_processing($item->id);
                
                // Zpracovat nearby data
                $result = $this->process_single_item($item);
                
                if ($result['success']) {
                    Nearby_Logger::log('BATCH', 'Item processed successfully', [
                        'queue_id' => $item->id,
                        'origin_id' => $item->origin_id,
                        'message' => $result['message'] ?? ''
                    ]);
                    // Označit jako zpracované v queue i v processed tabulce
                    $this->queue_manager->mark_as_processed($item->id, $result);
                    $processed++;
                } else {
                    Nearby_Logger::log('BATCH', 'Item processing failed', [
                        'queue_id' => $item->id,
                        'origin_id' => $item->origin_id,
                        'error' => $result['error'] ?? 'unknown'
                    ]);
                    $this->queue_manager->mark_failed($item->id, $result['error']);
                    $errors++;
                }
                
            } catch (\Exception $e) {
                Nearby_Logger::log('BATCH', 'Exception during item processing', [
                    'queue_id' => $item->id,
                    'origin_id' => $item->origin_id,
                    'error' => $e->getMessage()
                ]);
                $this->queue_manager->mark_failed($item->id, $e->getMessage());
                $errors++;
            }
        }
        
        Nearby_Logger::log('BATCH', 'Process batch finished', ['processed' => $processed, 'errors' => $errors]);
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'message' => "Zpracováno: {$processed}, chyb: {$errors}"
        );
    }
    
    /**
     * Zpracovat jednu položku
     */
    private function process_single_item($item) {
        // Získat souřadnice origin bodu
        $post = get_post($item->origin_id);
        if (!$post) {
            return array('success' => false, 'error' => 'Origin bod nenalezen');
        }
        
        $lat = $lng = null;
        if ($post->post_type === 'charging_location') {
            $lat = (float)get_post_meta($item->origin_id, '_db_lat', true);
            $lng = (float)get_post_meta($item->origin_id, '_db_lng', true);
        } elseif ($post->post_type === 'poi') {
            $lat = (float)get_post_meta($item->origin_id, '_poi_lat', true);
            $lng = (float)get_post_meta($item->origin_id, '_poi_lng', true);
        } elseif ($post->post_type === 'rv_spot') {
            $lat = (float)get_post_meta($item->origin_id, '_rv_lat', true);
            $lng = (float)get_post_meta($item->origin_id, '_rv_lng', true);
        }
        
        if (!$lat || !$lng) {
            return array('success' => false, 'error' => 'Neplatné souřadnice');
        }
        
        // Zkontrolovat, zda už máme nearby data
        $meta_key = ($item->origin_type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';
        $existing_cache = get_post_meta($item->origin_id, $meta_key, true);
        $cache_payload = $this->decode_cache_payload($existing_cache);

        $queue_created_ts = isset($item->created_at) ? strtotime($item->created_at) : 0;
        $should_recompute = $this->should_recompute_nearby($cache_payload, $queue_created_ts);

        if (!$should_recompute) {
            $should_recompute = $this->needs_iso_refresh($item->origin_id, $queue_created_ts, $cache_payload);
        }

        if (!$should_recompute) {
            Nearby_Logger::log('BATCH', 'Skipping recompute - cache fresh', [
                'origin_id' => $item->origin_id,
                'origin_type' => $item->origin_type
            ]);
            return array('success' => true, 'message' => 'Data už existují');
        }

        // DŮLEŽITÉ: ORS API se NEPOUŽÍVÁ v batch processoru
        // ORS API se volá pouze on-demand při kliknutí na bod na mapě
        // Batch processor pouze kontroluje cache a detekuje, zda přibylo nové POI
        
        // Zkontrolovat, zda přibylo nové POI v okolí (bez volání ORS API)
        $candidates_count = $this->count_candidates_without_ors(
            $lat, 
            $lng, 
            $item->origin_type, 
            $this->get_radius_km($item->origin_type)
        );
        
        if ($candidates_count === 0) {
            // Žádní kandidáti, označit jako dokončené s prázdnými daty
            $this->save_empty_cache($item->origin_id, $item->origin_type);
            Nearby_Logger::log('BATCH', 'No candidates - saved empty cache', [
                'origin_id' => $item->origin_id,
                'origin_type' => $item->origin_type
            ]);
            return array('success' => true, 'message' => 'Žádní kandidáti');
        }

        // Pokud cache není fresh a jsou kandidáti, označit jako "potřebuje zpracování"
        // ORS API se zavolá až při kliknutí na bod na mapě přes on-demand processor
        Nearby_Logger::log('BATCH', 'Marked for on-demand processing', [
            'origin_id' => $item->origin_id,
            'origin_type' => $item->origin_type,
            'candidates_count' => $candidates_count,
            'note' => 'ORS API will be called on-demand when user clicks on point'
        ]);
        
        // Označit jako dokončené ve frontě (data se zpracují on-demand)
        return array('success' => true, 'message' => 'Označeno pro on-demand zpracování');
    }
    
    /**
     * Získat radius podle typu
     */
    private function get_radius_km($type) {
        return ($type === 'poi') ? 
            (float)($this->config['radius_poi_for_charger'] ?? 5) : 
            (float)($this->config['radius_charger_for_poi'] ?? 5);
    }

    private function decode_cache_payload($raw) {
        if (empty($raw)) {
            return null;
        }

        if (is_array($raw)) {
            return $raw;
        }

        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
                return $decoded;
            }
        }

        return null;
    }

    private function should_recompute_nearby($payload, $queue_created_ts) {
        if (empty($payload)) {
            return true;
        }

        if (!empty($payload['partial']) || !empty($payload['error'])) {
            return true;
        }

        $items = $payload['items'] ?? [];
        if (empty($items)) {
            return true;
        }

        $computed_ts = isset($payload['computed_at']) ? strtotime($payload['computed_at']) : 0;
        if (!$computed_ts) {
            return true;
        }

        if ($queue_created_ts && $computed_ts <= $queue_created_ts) {
            return true;
        }

        $ttl_days = (int)($this->config['cache_ttl_days'] ?? 30);
        if ($ttl_days > 0) {
            $ttl_seconds = $ttl_days * DAY_IN_SECONDS;
            if ((time() - $computed_ts) > $ttl_seconds) {
                return true;
            }
        }

        return false;
    }

    private function needs_iso_refresh($origin_id, $queue_created_ts, $nearby_payload) {
        $iso_meta = get_post_meta($origin_id, 'db_isochrones_v1_foot-walking', true);
        $iso_payload = $this->decode_cache_payload($iso_meta);

        if (empty($iso_payload)) {
            return true;
        }

        if (!empty($iso_payload['error'])) {
            return true;
        }

        $features = $iso_payload['geojson']['features'] ?? [];
        if (empty($features)) {
            return true;
        }

        $iso_computed_ts = isset($iso_payload['computed_at']) ? strtotime($iso_payload['computed_at']) : 0;
        if (!$iso_computed_ts) {
            return true;
        }

        if ($queue_created_ts && $iso_computed_ts <= $queue_created_ts) {
            return true;
        }

        $ttl_days = (int)($this->config['cache_ttl_days'] ?? 30);
        if ($ttl_days > 0) {
            $ttl_seconds = $ttl_days * DAY_IN_SECONDS;
            if ((time() - $iso_computed_ts) > $ttl_seconds) {
                return true;
            }
        }

        if (!empty($nearby_payload['computed_at'])) {
            $nearby_computed_ts = strtotime($nearby_payload['computed_at']);
            if ($nearby_computed_ts && $iso_computed_ts < $nearby_computed_ts) {
                return true;
            }
        }

        return false;
    }
    
    /**
     * Spočítat kandidáty bez volání ORS API (pouze databázový dotaz)
     */
    private function count_candidates_without_ors($lat, $lng, $target_type, $radius_km) {
        global $wpdb;
        
        // Určit meta keys podle target typu
        $lat_key = ($target_type === 'poi') ? '_poi_lat' : 
                   (($target_type === 'rv_spot') ? '_rv_lat' : '_db_lat');
        $lng_key = ($target_type === 'poi') ? '_poi_lng' : 
                   (($target_type === 'rv_spot') ? '_rv_lng' : '_db_lng');
        
        // Použít subquery pro správný výpočet vzdálenosti
        $sql = $wpdb->prepare("
            SELECT COUNT(*) as count
            FROM (
                SELECT p.ID,
                       lat_pm.meta_value+0 AS lat,
                       lng_pm.meta_value+0 AS lng,
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
            ) AS candidates
            WHERE dist_km <= %f
        ", $lat, $lng, $lat, $lat_key, $lng_key, $target_type,
            $lat - ($radius_km / 111.0), $lat + ($radius_km / 111.0),
            $lng - ($radius_km / (111.0 * cos(deg2rad($lat)))), $lng + ($radius_km / (111.0 * cos(deg2rad($lat)))),
            $radius_km);
        
        $result = $wpdb->get_var($sql);
        return (int)($result ?? 0);
    }
    
    /**
     * Uložit prázdnou cache pro body bez kandidátů
     */
    private function save_empty_cache($origin_id, $type) {
        $meta_key = ($type === 'poi') ? '_db_nearby_cache_poi_foot' : '_db_nearby_cache_charger_foot';
        
        $payload = array(
            'computed_at' => current_time('c'),
            'items' => array(),
            'partial' => false,
            'progress' => array('done' => 0, 'total' => 0)
        );
        
        update_post_meta($origin_id, $meta_key, wp_json_encode($payload, JSON_UNESCAPED_UNICODE));
    }
    
    /**
     * Zpracovat denní dávku (500 bodů)
     */
    public function process_daily_batch() {
        $max_items = 500;
        $result = $this->process_batch($max_items);
        
        // Log výsledku
        error_log("[DB Nearby Batch] " . $result['message']);
        
        return $result;
    }
    
    /**
     * Zpracovat všechny pending položky
     */
    public function process_all_pending() {
        $total_processed = 0;
        $total_errors = 0;
        $batch_size = 50;
        
        do {
            $result = $this->process_batch($batch_size);
            $total_processed += $result['processed'];
            $total_errors += $result['errors'];
            
            // Pokud došly kvóty, zastavit
            if (strpos($result['message'], 'Nedostatečná API kvóta') !== false) {
                break;
            }
            
            // Malá pauza mezi dávkami
            sleep(1);
            
        } while ($result['processed'] > 0);
        
        return array(
            'processed' => $total_processed,
            'errors' => $total_errors,
            'message' => "Celkem zpracováno: {$total_processed}, chyb: {$total_errors}"
        );
    }
    
    /**
     * Zkontrolovat API limity a upravit batch size
     */
    public function check_api_limits() {
        $provider = $this->config['provider'] ?? 'ors';
        
        if ($provider === 'ors') {
            // ORS má denní limity, zkontrolovat usage
            $today = date('Y-m-d');
            $usage_key = 'db_nearby_ors_usage_' . $today;
            $usage = get_transient($usage_key) ?: 0;
            
            $max_daily = $this->config['max_pairs_per_day'] ?? 1000;
            $remaining = max(0, $max_daily - $usage);
            
            return min(50, $remaining); // Max 50 na batch
        }
        
        return 50; // Default batch size
    }
    
    /**
     * Zaznamenat API usage
     */
    public function record_api_usage($count) {
        $provider = $this->config['provider'] ?? 'ors';
        
        if ($provider === 'ors') {
            $today = date('Y-m-d');
            $usage_key = 'db_nearby_ors_usage_' . $today;
            $usage = get_transient($usage_key) ?: 0;
            set_transient($usage_key, $usage + $count, DAY_IN_SECONDS);
        }
    }
}
