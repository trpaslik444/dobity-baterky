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
        
        // Zkontrolovat API kvóty před zpracováním
        $quota_manager = new \DB\Jobs\API_Quota_Manager();
        if (!$quota_manager->can_process_queue()) {
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Nedostatečná API kvóta - čeká na reset'
            );
        }
        
        // Získat položky k zpracování
        $items = $this->queue_manager->get_pending_items($max_items);
        
        if (empty($items)) {
            return array(
                'processed' => 0,
                'errors' => 0,
                'message' => 'Žádné položky k zpracování'
            );
        }
        
        foreach ($items as $item) {
            try {
                // Označit jako zpracovávanou
                $this->queue_manager->mark_processing($item->id);
                
                // Zpracovat nearby data
                $result = $this->process_single_item($item);
                
                if ($result['success']) {
                    // Označit jako zpracované v queue i v processed tabulce
                    $this->queue_manager->mark_as_processed($item->id, $result);
                    $processed++;
                } else {
                    $this->queue_manager->mark_failed($item->id, $result['error']);
                    $errors++;
                }
                
            } catch (Exception $e) {
                $this->queue_manager->mark_failed($item->id, $e->getMessage());
                $errors++;
            }
        }
        
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
        
        if ($existing_cache && !empty($existing_cache)) {
            // Už máme data, označit jako dokončené
            return array('success' => true, 'message' => 'Data už existují');
        }
        
        // Získat kandidáty
        $candidates = $this->recompute_job->get_candidates(
            $lat, 
            $lng, 
            $item->origin_type, 
            $this->get_radius_km($item->origin_type),
            $this->config['max_candidates'] ?? 24
        );
        
        if (empty($candidates)) {
            // Žádní kandidáti, označit jako dokončené s prázdnými daty
            $this->save_empty_cache($item->origin_id, $item->origin_type);
            return array('success' => true, 'message' => 'Žádní kandidáti');
        }
        
        // Zpracovat nearby data pomocí existujícího jobu
        $result = $this->recompute_job->process_nearby_data(
            $item->origin_id, 
            $item->origin_type, 
            $candidates
        );
        
        if ($result['success']) {
            return array('success' => true, 'message' => 'Nearby data zpracována');
        } else {
            return array('success' => false, 'error' => $result['error']);
        }
    }
    
    /**
     * Získat radius podle typu
     */
    private function get_radius_km($type) {
        return ($type === 'poi') ? 
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
