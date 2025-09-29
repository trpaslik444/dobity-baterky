<?php
/**
 * Nearby Queue Manager - Správa fronty pro batch zpracování nearby bodů
 * @package DobityBaterky
 */

namespace DB\Jobs;

class Nearby_Queue_Manager {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'nearby_queue';
    }
    
    /**
     * Vytvořit tabulku pro frontu
     */
    public function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            origin_id bigint(20) NOT NULL,
            origin_type varchar(50) NOT NULL,
            priority int(11) DEFAULT 0,
            status varchar(20) DEFAULT 'pending',
            attempts int(11) DEFAULT 0,
            max_attempts int(11) DEFAULT 3,
            error_message text,
            created_at datetime DEFAULT CURRENT_TIMESTAMP,
            updated_at datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            processed_at datetime NULL,
            PRIMARY KEY (id),
            KEY origin_id (origin_id),
            KEY status (status),
            KEY priority (priority),
            KEY created_at (created_at)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Přidat bod do fronty s kontrolou kandidátů v okolí
     */
    public function enqueue($origin_id, $origin_type, $priority = 0) {
        global $wpdb;
        
        // Zkontrolovat, zda už není ve frontě
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id FROM {$this->table_name} WHERE origin_id = %d AND origin_type = %s AND status IN ('pending', 'processing')",
            $origin_id, $origin_type
        ));
        
        if ($existing) {
            return false; // Už je ve frontě
        }
        
        // Neblokovat zařazení na základě processed – requeue může úmyslně přepracovat
        
        // Server rozhoduje - zkontrolovat, zda má bod kandidáty v okolí
        if (!$this->has_candidates_in_area($origin_id, $origin_type)) {
            return false; // Nemá kandidáty, nezařadit do fronty
        }
        
        // Normalizovat cílový typ: povoleny pouze 'poi', 'charging_location', 'rv_spot'
        $origin_type = in_array($origin_type, array('poi','charging_location','rv_spot'), true) ? $origin_type : 'charging_location';

        // Přidat do fronty
        $result = $wpdb->insert(
            $this->table_name,
            array(
                'origin_id' => $origin_id,
                'origin_type' => $origin_type,
                'priority' => $priority,
                'status' => 'pending'
            ),
            array('%d', '%s', '%d', '%s')
        );
        
        if ($result !== false) {
            // Po úspěšném zařazení smaž z processed, aby nikdy nesoužily zároveň
            $real_origin_type = get_post_type((int)$origin_id) ?: $origin_type;
            $processed_type = ($origin_type === 'poi') ? 'poi_foot' : 'charger_foot';
            try {
                $processed_manager = new \DB\Jobs\Nearby_Processed_Manager();
                $processed_manager->delete_processed($origin_id, $real_origin_type, $processed_type);
            } catch (\Throwable $__) {}

            if (get_option('db_nearby_auto_enabled', false)) {
                try {
                    Nearby_Worker::dispatch();
                } catch (\Throwable $__) {}
            }
            return true;
        }
        
        return false;
    }
    
    /**
     * Zkontrolovat, zda má bod kandidáty v okolí
     */
    private function has_candidates_in_area($origin_id, $origin_type) {
        // Získat souřadnice origin bodu
        $post = get_post($origin_id);
        if (!$post) {
            return false;
        }
        
        $lat = $lng = null;
        if ($post->post_type === 'charging_location') {
            $lat = (float)get_post_meta($origin_id, '_db_lat', true);
            $lng = (float)get_post_meta($origin_id, '_db_lng', true);
        } elseif ($post->post_type === 'poi') {
            $lat = (float)get_post_meta($origin_id, '_poi_lat', true);
            $lng = (float)get_post_meta($origin_id, '_poi_lng', true);
        } elseif ($post->post_type === 'rv_spot') {
            $lat = (float)get_post_meta($origin_id, '_rv_lat', true);
            $lng = (float)get_post_meta($origin_id, '_rv_lng', true);
        }
        
        if (!$lat || !$lng) {
            return false;
        }
        
        // Získat konfiguraci pro radius
        $config = get_option('db_nearby_config', array());
        $radius_km = ($origin_type === 'poi') ? 
            (float)($config['radius_poi_for_charger'] ?? 5) : 
            (float)($config['radius_charger_for_poi'] ?? 5);
        
        // Použít existující job helper pro získání kandidátů
        if (!class_exists('DB\\Jobs\\Nearby_Recompute_Job')) {
            require_once dirname(__DIR__) . '/Nearby_Recompute_Job.php';
        }
        
        $job = new \DB\Jobs\Nearby_Recompute_Job();
        if (!method_exists($job, 'get_candidates')) {
            return false;
        }
        
        $max_candidates = (int)($config['max_candidates'] ?? 24);
        $candidates = $job->get_candidates($lat, $lng, $origin_type, $radius_km, $max_candidates);
        
        return !empty($candidates);
    }
    
    /**
     * Získat položky k zpracování
     */
    public function get_pending_items($limit = 10) {
        global $wpdb;
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$this->table_name} 
             WHERE status = 'pending' 
             ORDER BY priority DESC, created_at ASC 
             LIMIT %d",
            $limit
        ));
    }
    
    /**
     * Označit položku jako zpracovávanou
     */
    public function mark_processing($id) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array('status' => 'processing'),
            array('id' => $id),
            array('%s'),
            array('%d')
        );
    }
    
    /**
     * Označit položku jako dokončenou
     */
    public function mark_completed($id) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array(
                'status' => 'completed',
                'processed_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Označit položku jako chybnou
     */
    public function mark_failed($id, $error_message = '') {
        global $wpdb;
        
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
        
        if (!$item) {
            return false;
        }
        
        $attempts = $item->attempts + 1;
        $status = ($attempts >= $item->max_attempts) ? 'failed' : 'pending';
        
        return $wpdb->update(
            $this->table_name,
            array(
                'status' => $status,
                'attempts' => $attempts,
                'error_message' => $error_message,
                'updated_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%d', '%s', '%s'),
            array('%d')
        );
    }
    
    /**
     * Získat statistiky fronty
     */
    public function get_stats() {
        global $wpdb;
        
        $stats = $wpdb->get_row("
            SELECT 
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'processing' THEN 1 ELSE 0 END) as processing,
                SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
                SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) as failed
            FROM {$this->table_name}
        ");
        
        return $stats;
    }
    
    /**
     * Získat podrobnosti o frontě s informacemi o bodech
     */
    public function get_queue_details($limit = 50, $offset = 0) {
        global $wpdb;
        
        $items = $wpdb->get_results($wpdb->prepare("
            SELECT 
                q.*,
                p.post_title,
                p.post_type,
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
            FROM {$this->table_name} q
            LEFT JOIN {$wpdb->posts} p ON q.origin_id = p.ID
            LEFT JOIN {$wpdb->postmeta} pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')
            LEFT JOIN {$wpdb->postmeta} pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key IN ('_db_lng', '_poi_lng', '_rv_lng')
            WHERE q.status IN ('pending', 'processing')
            ORDER BY q.priority DESC, q.created_at ASC
            LIMIT %d OFFSET %d
        ", $limit, $offset));
        
        // Zajistit, že vracíme pole
        if (!is_array($items)) {
            $items = array();
        }
        
        // Obohacení o počet kandidátů v okolí - optimalizováno pro výkon
        if (!empty($items)) {
            $this->bulk_get_candidates_count($items);
        }
        
        return $items;
    }
    
    /**
     * Získat informace o paginaci fronty
     */
    public function get_queue_pagination($limit = 50, $offset = 0) {
        global $wpdb;
        
        // Celkový počet položek
        $total_items = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$this->table_name} 
            WHERE status IN ('pending', 'processing')
        ");
        
        $total_pages = ceil($total_items / $limit);
        $current_page = floor($offset / $limit) + 1;
        
        return array(
            'total_items' => (int)$total_items,
            'total_pages' => (int)$total_pages,
            'current_page' => (int)$current_page,
            'limit' => (int)$limit,
            'offset' => (int)$offset
        );
    }
    
    /**
     * Získat první pending položku pro testování
     */
    public function get_next_pending_item() {
        global $wpdb;
        
        return $wpdb->get_row("
            SELECT * 
            FROM {$this->table_name} 
            WHERE status = 'pending' 
            ORDER BY priority DESC, created_at ASC 
            LIMIT 1
        ");
    }
    
    /**
     * Optimalizované získání počtu kandidátů pro všechny položky najednou
     */
    private function bulk_get_candidates_count(&$items) {
        $config = get_option('db_nearby_config', array());
        $max_candidates = (int)($config['max_candidates'] ?? 24);
        
        // Skupinovat podle typu pro efektivnější dotazy
        $groups = array();
        foreach ($items as $item) {
            if ($item->lat && $item->lng) {
                $groups[$item->origin_type][] = $item;
            } else {
                $item->candidates_count = 0;
            }
        }
        
        foreach ($groups as $origin_type => $type_items) {
            $radius_km = ($origin_type === 'poi') ? 
                (float)($config['radius_poi_for_charger'] ?? 5) : 
                (float)($config['radius_charger_for_poi'] ?? 5);
            
            // Použít jednou načtenou třídu pro všechny položky stejného typu
            if (!class_exists('DB\\Jobs\\Nearby_Recompute_Job')) {
                require_once dirname(__DIR__) . '/Nearby_Recompute_Job.php';
            }
            
            $job = new \DB\Jobs\Nearby_Recompute_Job();
            if (method_exists($job, 'get_candidates')) {
                foreach ($type_items as $item) {
                    $candidates = $job->get_candidates(
                        (float)$item->lat, 
                        (float)$item->lng, 
                        $origin_type, 
                        $radius_km, 
                        $max_candidates
                    );
                    $item->candidates_count = count($candidates);
                }
            } else {
                foreach ($type_items as $item) {
                    $item->candidates_count = 0;
                }
            }
        }
    }
    
    /**
     * Získat počet kandidátů pro bod (pouze pro jednotlivé použití)
     */
    private function get_candidates_count($origin_id, $origin_type) {
        $post = get_post($origin_id);
        if (!$post) {
            return 0;
        }
        
        $lat = $lng = null;
        if ($post->post_type === 'charging_location') {
            $lat = (float)get_post_meta($origin_id, '_db_lat', true);
            $lng = (float)get_post_meta($origin_id, '_db_lng', true);
        } elseif ($post->post_type === 'poi') {
            $lat = (float)get_post_meta($origin_id, '_poi_lat', true);
            $lng = (float)get_post_meta($origin_id, '_poi_lng', true);
        } elseif ($post->post_type === 'rv_spot') {
            $lat = (float)get_post_meta($origin_id, '_rv_lat', true);
            $lng = (float)get_post_meta($origin_id, '_rv_lng', true);
        }
        
        if (!$lat || !$lng) {
            return 0;
        }
        
        $config = get_option('db_nearby_config', array());
        $radius_km = ($origin_type === 'poi') ? 
            (float)($config['radius_poi_for_charger'] ?? 5) : 
            (float)($config['radius_charger_for_poi'] ?? 5);
        
        if (!class_exists('DB\\Jobs\\Nearby_Recompute_Job')) {
            require_once dirname(__DIR__) . '/Nearby_Recompute_Job.php';
        }
        
        $job = new \DB\Jobs\Nearby_Recompute_Job();
        if (!method_exists($job, 'get_candidates')) {
            return 0;
        }
        
        $max_candidates = (int)($config['max_candidates'] ?? 24);
        $candidates = $job->get_candidates($lat, $lng, $origin_type, $radius_km, $max_candidates);
        
        return count($candidates);
    }
    
    /**
     * Nastavit prioritu položky ve frontě
     */
    public function set_priority($id, $priority) {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array('priority' => $priority),
            array('id' => $id),
            array('%d'),
            array('%d')
        );
    }
    
    /**
     * Přesunout položku na začátek fronty (nejvyšší priorita)
     */
    public function move_to_front($id) {
        global $wpdb;
        // Najít nejvyšší prioritu
        $max_priority = $wpdb->get_var("SELECT MAX(priority) FROM {$this->table_name}");
        $new_priority = ($max_priority ?: 0) + 1;
        
        return $this->set_priority($id, $new_priority);
    }
    
    /**
     * Označit položku jako úspěšně zpracovanou
     */
    public function mark_as_processed($id, $stats = array()) {
        global $wpdb;
        
        // Získat informace o položce
        $item = $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$this->table_name} WHERE id = %d",
            $id
        ));
        
        if (!$item) {
            return false;
        }
        
        // Přidat do zpracovaných
        $processed_manager = new \DB\Jobs\Nearby_Processed_Manager();
        $processed_type = ($item->origin_type === 'poi') ? 'poi_foot' : 'charger_foot';
        
        // Uložit reálný typ origin postu (ne cílový typ zpracování)
        $real_origin_type = get_post_type((int)$item->origin_id) ?: $item->origin_type;
        
        $processed_manager->add_processed(
            $item->origin_id, 
            $real_origin_type, 
            $processed_type, 
            $stats
        );
        
        // Označit jako dokončené ve frontě
        $res = $wpdb->update(
            $this->table_name,
            array(
                'status' => 'completed',
                'processed_at' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        );

        // Odstranit případné další položky ve frontě pro stejný origin (jiný origin_type), aby nebyl současně ve frontě i v processed
        $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} WHERE origin_id = %d AND status IN ('pending','processing')",
            $item->origin_id
        ));

        return $res;
    }
    
    /**
     * Vyčistit staré dokončené položky (starší než 30 dní)
     */
    public function cleanup_old_items() {
        global $wpdb;
        
        return $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
             WHERE status = 'completed' 
             AND processed_at < %s",
            date('Y-m-d H:i:s', strtotime('-30 days'))
        ));
    }
    
    /**
     * Resetovat failed položky pro opakování
     */
    public function reset_failed_items() {
        global $wpdb;
        
        return $wpdb->update(
            $this->table_name,
            array(
                'status' => 'pending',
                'attempts' => 0,
                'error_message' => '',
                'updated_at' => current_time('mysql')
            ),
            array('status' => 'failed'),
            array('%s', '%d', '%s', '%s'),
            array('%s')
        );
    }
    
    /**
     * Přidat všechny body do fronty (pro inicializaci)
     */
    public function enqueue_all_points() {
        global $wpdb;
        
        $added_count = 0;
        
        // Nabíjecí stanice
        $charging_locations = $wpdb->get_results("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'charging_location' 
            AND post_status = 'publish'
        ");
        
        foreach ($charging_locations as $location) {
            if ($this->enqueue($location->ID, 'poi', 1)) { $added_count++; }
            if ($this->enqueue($location->ID, 'rv_spot', 1)) { $added_count++; }
        }
        
        // POI
        $pois = $wpdb->get_results("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'poi' 
            AND post_status = 'publish'
        ");
        
        foreach ($pois as $poi) {
            if ($this->enqueue($poi->ID, 'charging_location', 1)) {
                $added_count++;
            }
        }
        
        // RV spoty
        $rv_spots = $wpdb->get_results("
            SELECT ID FROM {$wpdb->posts} 
            WHERE post_type = 'rv_spot' 
            AND post_status = 'publish'
        ");
        
        foreach ($rv_spots as $spot) {
            if ($this->enqueue($spot->ID, 'charging_location', 1)) { $added_count++; }
            if ($this->enqueue($spot->ID, 'poi', 1)) { $added_count++; }
        }
        
        return $added_count;
    }
    
    /**
     * Získat body, které potřebují aktualizaci (změna v okolí)
     */
    public function enqueue_affected_points($changed_point_id, $changed_point_type) {
        global $wpdb;
        
        $added_count = 0;
        
        // Získat souřadnice změněného bodu
        $post = get_post($changed_point_id);
        if (!$post) {
            return 0;
        }
        
        $lat = $lng = null;
        if ($post->post_type === 'charging_location') {
            $lat = (float)get_post_meta($changed_point_id, '_db_lat', true);
            $lng = (float)get_post_meta($changed_point_id, '_db_lng', true);
        } elseif ($post->post_type === 'poi') {
            $lat = (float)get_post_meta($changed_point_id, '_poi_lat', true);
            $lng = (float)get_post_meta($changed_point_id, '_poi_lng', true);
        } elseif ($post->post_type === 'rv_spot') {
            $lat = (float)get_post_meta($changed_point_id, '_rv_lat', true);
            $lng = (float)get_post_meta($changed_point_id, '_rv_lng', true);
        }
        
        if (!$lat || !$lng) {
            return 0;
        }
        
        // Najít body v okolí (5km radius)
        $radius_km = 5;
        $earth_radius = 6371; // km
        
        $sql = $wpdb->prepare("
            SELECT p.ID, p.post_type,
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
            AND p.post_type IN ('charging_location', 'poi', 'rv_spot')
            AND p.ID != %d
            AND pm_lat.meta_value IS NOT NULL
            AND pm_lng.meta_value IS NOT NULL
            HAVING (
                6371 * acos(
                    cos(radians(%f)) * cos(radians(CAST(lat AS DECIMAL(10,6)))) * 
                    cos(radians(CAST(lng AS DECIMAL(10,6))) - radians(%f)) + 
                    sin(radians(%f)) * sin(radians(CAST(lat AS DECIMAL(10,6))))
            ) <= %f
        ", $changed_point_id, $lat, $lng, $lat, $radius_km);
        
        $affected_points = $wpdb->get_results($sql);
        
        foreach ($affected_points as $point) {
            // Určit typ nearby dat k přepočtu
            $nearby_type = ($point->post_type === 'charging_location') ? 'poi' : 'charging_location';
            
            if ($this->enqueue($point->ID, $nearby_type, 2)) { // Vyšší priorita pro aktualizace
                $added_count++;
            }
        }
        
        return $added_count;
    }
    
    /**
     * Hook pro automatické zařazování do fronty při změnách
     */
    public function init_hooks() {
        // Hook při uložení/aktualizaci bodu
        add_action('save_post', array($this, 'handle_post_save'), 10, 3);
        
        // Hook při smazání bodu
        add_action('delete_post', array($this, 'handle_post_delete'), 10, 3);
    }
    
    /**
     * Zpracovat uložení/aktualizaci bodu
     */
    public function handle_post_save($post_id, $post, $update) {
        // Zkontrolovat, zda je to relevantní post type
        if (!in_array($post->post_type, array('charging_location', 'poi', 'rv_spot'))) {
            return;
        }
        
        // Zkontrolovat, zda má souřadnice
        $lat = $lng = null;
        if ($post->post_type === 'charging_location') {
            $lat = (float)get_post_meta($post_id, '_db_lat', true);
            $lng = (float)get_post_meta($post_id, '_db_lng', true);
        } elseif ($post->post_type === 'poi') {
            $lat = (float)get_post_meta($post_id, '_poi_lat', true);
            $lng = (float)get_post_meta($post_id, '_poi_lng', true);
        } elseif ($post->post_type === 'rv_spot') {
            $lat = (float)get_post_meta($post_id, '_rv_lat', true);
            $lng = (float)get_post_meta($post_id, '_rv_lng', true);
        }
        
        if (!$lat || !$lng) {
            return; // Nemá souřadnice
        }
        
        // Přidat do fronty pro nearby výpočet (proces 1 - zdarma)
        $nearby_type = ($post->post_type === 'charging_location') ? 'poi' : 'charging_location';
        $this->enqueue($post_id, $nearby_type, 1);
        
        // Přidat do fronty všechny body v okolí (pro aktualizaci jejich nearby dat)
        $this->enqueue_affected_points($post_id, $post->post_type);
        
        // POZOR: API zpracování (proces 2) se spouští pouze na povolení admina!
    }
    
    /**
     * Zpracovat smazání bodu
     */
    public function handle_post_delete($post_id, $post, $force_delete) {
        // Zkontrolovat, zda je to relevantní post type
        if (!in_array($post->post_type, array('charging_location', 'poi', 'rv_spot'))) {
            return;
        }
        
        // Přidat do fronty všechny body v okolí (pro aktualizaci jejich nearby dat)
        $this->enqueue_affected_points($post_id, $post->post_type);
    }
}
