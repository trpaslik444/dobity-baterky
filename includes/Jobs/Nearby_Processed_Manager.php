<?php
/**
 * Nearby Processed Manager - Správa zpracovaných nearby dat
 * @package DobityBaterky
 */

namespace DB\Jobs;

class Nearby_Processed_Manager {
    
    private $table_name;
    
    public function __construct() {
        global $wpdb;
        $this->table_name = $wpdb->prefix . 'nearby_processed';
        $this->create_table();
    }
    
    /**
     * Vytvořit tabulku pro zpracovaná nearby data
     */
    private function create_table() {
        global $wpdb;
        
        $charset_collate = $wpdb->get_charset_collate();
        
        $sql = "CREATE TABLE IF NOT EXISTS {$this->table_name} (
            id bigint(20) NOT NULL AUTO_INCREMENT,
            origin_id bigint(20) NOT NULL,
            origin_type varchar(20) NOT NULL,
            origin_title varchar(255),
            origin_lat decimal(10,8),
            origin_lng decimal(11,8),
            processed_type varchar(20) NOT NULL,
            candidates_count int(11) DEFAULT 0,
            api_calls_used int(11) DEFAULT 0,
            processing_time_seconds int(11) DEFAULT 0,
            api_provider varchar(20) DEFAULT 'ors',
            cache_size_kb int(11) DEFAULT 0,
            processing_date datetime DEFAULT CURRENT_TIMESTAMP,
            last_updated datetime DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            status varchar(20) DEFAULT 'completed',
            error_message text,
            PRIMARY KEY (id),
            UNIQUE KEY unique_origin (origin_id),
            KEY origin_type (origin_type),
            KEY processed_type (processed_type),
            KEY processing_date (processing_date),
            KEY status (status),
            KEY api_provider (api_provider)
        ) $charset_collate;";
        
        require_once(ABSPATH . 'wp-admin/includes/upgrade.php');
        dbDelta($sql);
    }
    
    /**
     * Přidat záznam o zpracovaném místě (upsert podle origin_id)
     */
    public function add_processed($origin_id, $origin_type, $processed_type, $stats = array()) {
        global $wpdb;
        
        // Získat informace o origin bodu
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
        
        $data = array(
            'origin_id' => $origin_id,
            'origin_type' => $origin_type,
            'origin_title' => $post->post_title,
            'origin_lat' => $lat,
            'origin_lng' => $lng,
            'processed_type' => $processed_type,
            'candidates_count' => (int)($stats['candidates_count'] ?? 0),
            'api_calls_used' => (int)($stats['api_calls'] ?? 0),
            'processing_time_seconds' => (int)($stats['processing_time'] ?? 0),
            'api_provider' => $stats['api_provider'] ?? 'ors',
            'cache_size_kb' => (int)($stats['cache_size_kb'] ?? 0),
            'status' => $stats['status'] ?? 'completed',
            'error_message' => $stats['error_message'] ?? null,
            'processing_date' => current_time('mysql')
        );
        
        // Upsert: pokud existuje záznam pro origin_id, update; jinak insert
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE origin_id = %d", $origin_id));
        if ($exists) {
            return $wpdb->update(
                $this->table_name,
                $data,
                array('id' => (int)$exists),
                array('%d','%s','%s','%f','%f','%s','%d','%d','%d','%s','%d','%s','%s','%s'),
                array('%d')
            );
        }
        
        return $wpdb->insert(
            $this->table_name,
            $data,
            array('%d','%s','%s','%f','%f','%s','%d','%d','%d','%s','%d','%s','%s','%s')
        );
    }
    
    /**
     * Získat seznam zpracovaných míst s paginací
     */
    public function get_processed_locations($limit = 50, $offset = 0, $filters = array()) {
        global $wpdb;
        
        $where = "WHERE 1=1";
        if (!empty($filters['origin_type'])) {
            $where .= $wpdb->prepare(" AND origin_type = %s", $filters['origin_type']);
        }
        if (!empty($filters['status'])) {
            $where .= $wpdb->prepare(" AND status = %s", $filters['status']);
        }
        
        // Nezobrazovat položky, které jsou aktuálně ve frontě (pending/processing)
        $queue_table = $wpdb->prefix . 'nearby_queue';
        $sql = $wpdb->prepare(
            "SELECT p.*\n             FROM {$this->table_name} p\n             LEFT JOIN {$queue_table} q\n               ON q.origin_id = p.origin_id\n              AND q.status IN ('pending','processing')\n             {$where}\n             AND q.id IS NULL\n             ORDER BY p.processing_date DESC\n             LIMIT %d OFFSET %d",
            (int)$limit,
            (int)$offset
        );
        return $wpdb->get_results($sql);
    }
    
    /**
     * Získat statistiky zpracovaných míst
     */
    public function get_processed_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Celkový počet zpracovaných míst
        $stats['total_processed'] = (int)$wpdb->get_var("
            SELECT COUNT(DISTINCT CONCAT(origin_id, '-', origin_type)) 
            FROM {$this->table_name}
        ");
        
        // Počet podle typu
        $stats['by_type'] = $wpdb->get_results("
            SELECT origin_type, COUNT(*) as count
            FROM {$this->table_name}
            GROUP BY origin_type
        ", ARRAY_A);
        
        // Počet podle API providera
        $stats['by_provider'] = $wpdb->get_results("
            SELECT api_provider, COUNT(*) as count
            FROM {$this->table_name}
            GROUP BY api_provider
        ", ARRAY_A);
        
        // Celkové API volání
        $stats['total_api_calls'] = (int)$wpdb->get_var("
            SELECT SUM(api_calls_used) 
            FROM {$this->table_name}
        ");
        
        // Celková velikost cache
        $stats['total_cache_size_kb'] = (int)$wpdb->get_var("
            SELECT SUM(cache_size_kb) 
            FROM {$this->table_name}
        ");
        
        // Poslední zpracování
        $stats['last_processed'] = $wpdb->get_var("
            SELECT MAX(processing_date) 
            FROM {$this->table_name}
        ");
        
        // Průměrný čas zpracování
        $stats['avg_processing_time'] = (float)$wpdb->get_var("
            SELECT AVG(processing_time_seconds) 
            FROM {$this->table_name}
            WHERE processing_time_seconds > 0
        ");
        
        return $stats;
    }
    
    /**
     * Získat informace o konkrétním zpracovaném místě
     */
    public function get_processed_details($origin_id, $origin_type, $processed_type) {
        global $wpdb;
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * 
            FROM {$this->table_name} 
            WHERE origin_id = %d AND origin_type = %s AND processed_type = %s",
            $origin_id, $origin_type, $processed_type
        ));
    }
    
    /**
     * Zkontrolovat, zda je místo již zpracované
     */
    public function is_processed($origin_id, $origin_type, $processed_type) {
        global $wpdb;
        
        $count = $wpdb->get_var($wpdb->prepare(
            "SELECT COUNT(*) 
            FROM {$this->table_name} 
            WHERE origin_id = %d AND origin_type = %s AND processed_type = %s AND status = 'completed'",
            $origin_id, $origin_type, $processed_type
        ));
        
        return $count > 0;
    }
    
    /**
     * Smazat záznam o zpracovaném místě
     */
    public function delete_processed($origin_id, $origin_type, $processed_type) {
        global $wpdb;
        
        return $wpdb->delete(
            $this->table_name,
            array(
                'origin_id' => $origin_id,
                'origin_type' => $origin_type,
                'processed_type' => $processed_type
            ),
            array('%d', '%s', '%s')
        );
    }
    
    /**
     * Vyčistit staré záznamy
     */
    public function cleanup_old_records($days = 90) {
        global $wpdb;
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$this->table_name} 
            WHERE processing_date < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return $deleted;
    }
    
    /**
     * Získat paginaci pro zpracovaná místa
     */
    public function get_processed_pagination($limit = 50, $offset = 0, $filters = array()) {
        // Placeholder - zachovat signaturu
        return array('limit' => $limit, 'offset' => $offset);
    }
}
