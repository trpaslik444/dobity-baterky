<?php
/**
 * Nearby Batch Processor - Zpracování fronty nearby bodů
 * @package DobityBaterky
 */

namespace DB\Jobs;

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
    public function process_batch($max_items = 10) {
        $processed = 0;
        $errors = 0;
        
        // Debug: Log start
        error_log("[DB DEBUG] Batch processing started with max_items: {$max_items}");
        
        // Zkontrolovat API kvóty před zpracováním (bez spotřebování tokenu)
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        if (!$quota_manager->can_process_queue()) {
            error_log("[DB DEBUG] API quota check failed");
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Nedostatečná API kvóta - čeká na reset'
            );
        }
        
        // Zkontrolovat token bucket bez spotřebování
        $token_check = $quota_manager->check_minute_limit('matrix', false);
        if (!$token_check['allowed']) {
            $wait_seconds = isset($token_check['wait_seconds']) ? (int)$token_check['wait_seconds'] : 60;
            error_log("[DB DEBUG] Token bucket insufficient, wait {$wait_seconds}s");
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Lokální minutový limit. Počkej ' . $wait_seconds . 's'
            );
        }
        
        error_log("[DB DEBUG] API quota check passed");
        
        $max_items = $quota_manager->get_max_batch_limit($max_items);
        error_log("[DB DEBUG] Adjusted max_items to: {$max_items}");

        // Získat položky k zpracování
        $items = $this->queue_manager->get_pending_items($max_items);
        
        error_log("[DB DEBUG] Found " . count($items) . " items to process");
        
        if (empty($items)) {
            error_log("[DB DEBUG] No items to process");
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Žádné položky k zpracování'
            );
        }
        $last_error = null;
        
        foreach ($items as $item) {
            try {
                error_log("[DB DEBUG] Processing item ID: {$item->id}, origin_id: {$item->origin_id}, origin_type: {$item->origin_type}");
                
                // Označit jako zpracovávanou
                $this->queue_manager->mark_processing($item->id);
                
                // Zpracovat nearby data
                $result = $this->process_single_item($item);
                
                error_log("[DB DEBUG] Process result: " . json_encode($result));

                if (!$result['success'] && !empty($result['rate_limited'])) {
                    $message = $result['error'] ?? 'ORS kvóta vyčerpána';
                    $this->queue_manager->mark_rate_limited($item->id, $message);
                    $wait = isset($result['retry_after']) ? (int)$result['retry_after'] : 60;
                    $next_run = $quota_manager->schedule_next_run($wait + 5);
                    return array(
                        'processed' => $processed,
                        'errors' => $errors,
                        'message' => $message,
                        'last_error' => $message,
                        'rate_limited' => true,
                        'next_run' => $next_run
                    );
                }
                
                // Zkontrolovat, zda je to chyba kvóty
                if (!$result['success'] && (strpos($result['error'] ?? '', 'kvóta') !== false || strpos($result['error'] ?? '', 'quota') !== false)) {
                    $message = $result['error'] ?? 'API kvóta vyčerpána';
                    $this->queue_manager->mark_rate_limited($item->id, $message);
                    $next_run = $quota_manager->schedule_next_run(60);
                    return array(
                        'processed' => $processed,
                        'errors' => $errors,
                        'message' => $message,
                        'last_error' => $message,
                        'rate_limited' => true,
                        'next_run' => $next_run
                    );
                }

                if (!$result['success'] && !empty($result['isochrones_error'])) {
                    $message = 'Isochrones ' . $result['isochrones_error'];
                    $this->queue_manager->mark_rate_limited($item->id, $message);
                    $wait = isset($result['retry_after']) ? (int)$result['retry_after'] : 60;
                    $next_run = $quota_manager->schedule_next_run($wait + 5);
                    return array(
                        'processed' => $processed,
                        'errors' => $errors,
                        'message' => $message,
                        'last_error' => $message,
                        'rate_limited' => true,
                        'next_run' => $next_run
                    );
                }

                if ($result['success']) {
                    // Označit jako zpracované v queue i v processed tabulce
                    error_log("[DB DEBUG] Marking item {$item->id} as processed");
                    $mark_result = $this->queue_manager->mark_as_processed($item->id, $result);
                    error_log("[DB DEBUG] Mark as processed result: " . ($mark_result ? 'success' : 'failed'));
                    $processed++;
                } else {
                    $error_message = $result['error'] ?? 'Neznámá chyba';
                    error_log("[DB DEBUG] Marking item {$item->id} as failed: {$error_message}");
                    $this->queue_manager->mark_failed($item->id, $error_message);
                    $last_error = $error_message;
                    $errors++;
                }
                
            } catch (Exception $e) {
                $this->queue_manager->mark_failed($item->id, $e->getMessage());
                $last_error = $e->getMessage();
                $errors++;
            }
        }
        
        return array(
            'processed' => $processed,
            'errors' => $errors,
            'message' => "Zpracováno: {$processed}, chyb: {$errors}",
            'last_error' => $last_error
        );
    }
    
    /**
     * Zpracovat jednu položku (sdíleno i pro test API)
     */
    public function process_single_item($item) {
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
        if ($existing_cache) {
            $cache_payload = is_string($existing_cache) ? json_decode($existing_cache, true) : $existing_cache;
            if (is_array($cache_payload)) {
                $is_valid_cache = !empty($cache_payload['items'])
                    && empty($cache_payload['partial'])
                    && empty($cache_payload['error']);
                if ($is_valid_cache) {
                    $items_count = count($cache_payload['items']);
                    return array(
                        'success' => true,
                        'message' => 'Data už existují',
                        'processed' => 0,
                        'items_count' => $items_count,
                        'candidates_count' => $items_count,
                        'api_calls' => 0,
                        'processing_time' => 0,
                        'api_provider' => 'cache.hit'
                    );
                }
            }
        }

        // Přemapovat typ: origin charging_location => hledat poi, origin poi => hledat charging_location
        $search_type = $item->origin_type;
        if ($post->post_type === 'charging_location' && $item->origin_type === 'charging_location') {
            $search_type = 'poi'; // Hledat POI pro nabíječku
        } elseif ($post->post_type === 'poi' && $item->origin_type === 'poi') {
            $search_type = 'charging_location'; // Hledat nabíječky pro POI
        }
        
        // Získat kandidáty
        error_log("[DB DEBUG] Getting candidates for origin_id: {$item->origin_id}, origin_type: {$item->origin_type}, search_type: {$search_type}, lat: {$lat}, lng: {$lng}");
        $max_cand_cfg = isset($this->config['max_candidates']) ? (int)$this->config['max_candidates'] : 50;
        $max_cand_cfg = max(1, min(50, $max_cand_cfg));
        $candidates = $this->recompute_job->get_candidates(
            $lat, 
            $lng, 
            $search_type, 
            $this->get_radius_km($search_type),
            $max_cand_cfg
        );
        $candidates = array_slice($candidates, 0, $max_cand_cfg);
        
        error_log("[DB DEBUG] Found " . count($candidates) . " candidates");
        
        if (empty($candidates)) {
            // Žádní kandidáti, označit jako dokončené s prázdnými daty
            $this->save_empty_cache($item->origin_id, $search_type);
            return array(
                'success' => true,
                'message' => 'Žádní kandidáti',
                'processed' => 0,
                'items_count' => 0,
                'candidates_count' => 0,
                'api_calls' => 0,
                'processing_time' => 0,
                'api_provider' => 'cache.empty'
            );
        }
        
        $candidates_count = count($candidates);

        // Zpracovat nearby data pomocí existujícího jobu
        $result = $this->recompute_job->process_nearby_data(
            $item->origin_id, 
            $search_type, 
            $candidates
        );
        
        if ($result['success']) {
            if (empty($result['candidates_count'])) {
                $result['candidates_count'] = $candidates_count;
            }
            if (empty($result['items_count'])) {
                $result['items_count'] = $candidates_count;
            }
            if (empty($result['status'])) {
                $result['status'] = 'completed';
            }
            return array_merge(
                array(
                    'success' => true,
                    'message' => $result['message'] ?? 'Nearby data zpracována'
                ),
                $result
            );
        }

        return array(
            'success' => false,
            'error' => $result['error'] ?? 'Neznámá chyba',
            'candidates_count' => $candidates_count,
            'status' => 'failed'
        );
    }
    
    /**
     * Získat radius podle typu
     */
    private function get_radius_km($search_type) {
        // search_type = co hledáme (poi nebo charging_location)
        return ($search_type === 'poi') ? 
            (float)($this->config['radius_poi_for_charger'] ?? 5) : 
            (float)($this->config['radius_charger_for_poi'] ?? 5);
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
