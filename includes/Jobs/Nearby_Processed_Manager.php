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
        
        $sql = "CREATE TABLE {$this->table_name} (
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
            nearby_items_count int(11) DEFAULT 0,
            iso_features int(11) DEFAULT 0,
            iso_calls int(11) DEFAULT 0,
            has_nearby tinyint(1) DEFAULT 0,
            has_isochrones tinyint(1) DEFAULT 0,
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

        $this->maybe_upgrade_schema();
    }

    /**
     * Doplnit chybějící sloupce do tabulky při upgradu pluginu
     */
    private function maybe_upgrade_schema() {
        global $wpdb;

        $table = esc_sql($this->table_name);
        $columns = $wpdb->get_col("SHOW COLUMNS FROM {$table}");
        if (!is_array($columns)) {
            return;
        }

        $columns = array_map('strtolower', $columns);
        $queries = [];

        if (!in_array('nearby_items_count', $columns, true)) {
            $queries[] = "ALTER TABLE {$this->table_name} ADD COLUMN nearby_items_count int(11) DEFAULT 0 AFTER cache_size_kb";
        }

        if (!in_array('iso_features', $columns, true)) {
            $queries[] = "ALTER TABLE {$this->table_name} ADD COLUMN iso_features int(11) DEFAULT 0 AFTER nearby_items_count";
            if (in_array('isochrones_features', $columns, true)) {
                $queries[] = "UPDATE {$this->table_name} SET iso_features = isochrones_features WHERE iso_features = 0";
            }
        }

        if (!in_array('iso_calls', $columns, true)) {
            $queries[] = "ALTER TABLE {$this->table_name} ADD COLUMN iso_calls int(11) DEFAULT 0 AFTER iso_features";
        }

        if (!in_array('has_nearby', $columns, true)) {
            $queries[] = "ALTER TABLE {$this->table_name} ADD COLUMN has_nearby tinyint(1) DEFAULT 0 AFTER iso_calls";
            $queries[] = "UPDATE {$this->table_name} SET has_nearby = 1 WHERE nearby_items_count > 0";
        }

        if (!in_array('has_isochrones', $columns, true)) {
            $queries[] = "ALTER TABLE {$this->table_name} ADD COLUMN has_isochrones tinyint(1) DEFAULT 0 AFTER has_nearby";
            if (in_array('isochrones_features', $columns, true)) {
                $queries[] = "UPDATE {$this->table_name} SET has_isochrones = 1 WHERE isochrones_features > 0";
            }
        }

        foreach ($queries as $query) {
            $wpdb->query($query);
        }
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
        
        $api_calls = $stats['api_calls'] ?? 0;
        $matrix_calls = 0;
        $iso_calls = 0;
        if (is_array($api_calls)) {
            $matrix_calls = (int)($api_calls['matrix'] ?? 0);
            $iso_calls = (int)($api_calls['isochrones'] ?? 0);
            $api_calls = $matrix_calls + $iso_calls;
        } else {
            $api_calls = (int)$api_calls;
            $iso_calls = (int)($stats['iso_calls'] ?? 0);
        }

        $nearby_items = (int)($stats['nearby_items'] ?? $stats['nearby_items_count'] ?? 0);
        $iso_features = (int)($stats['iso_features'] ?? 0);

        $data = array(
            'origin_id' => $origin_id,
            'origin_type' => $origin_type,
            'origin_title' => $post->post_title,
            'origin_lat' => $lat,
            'origin_lng' => $lng,
            'processed_type' => $processed_type,
            'candidates_count' => (int)($stats['candidates_count'] ?? 0),
            'api_calls_used' => $api_calls,
            'processing_time_seconds' => (int)($stats['processing_time'] ?? 0),
            'api_provider' => $stats['api_provider'] ?? 'ors',
            'cache_size_kb' => (int)($stats['cache_size_kb'] ?? 0),
            'status' => $stats['status'] ?? 'completed',
            'error_message' => $stats['error_message'] ?? null,
            'processing_date' => current_time('mysql'),
            'nearby_items_count' => $nearby_items,
            'iso_features' => $iso_features,
            'iso_calls' => $iso_calls,
            'has_nearby' => $nearby_items > 0 ? 1 : 0,
            'has_isochrones' => ($iso_features > 0 || $iso_calls > 0) ? 1 : 0
        );
        
        // Upsert: pokud existuje záznam pro origin_id, update; jinak insert
        $exists = $wpdb->get_var($wpdb->prepare("SELECT id FROM {$this->table_name} WHERE origin_id = %d", $origin_id));
        if ($exists) {
            $updated = $wpdb->update(
                $this->table_name,
                $data,
                array('id' => (int)$exists),
                array('%d','%s','%s','%f','%f','%s','%d','%d','%d','%s','%d','%s','%s','%s','%d','%d','%d','%d','%d'),
                array('%d')
            );
            $this->maybe_update_row_stats($origin_id, $this->compute_meta_stats($origin_id, $processed_type));
            return $updated;
        }

        $inserted = $wpdb->insert(
            $this->table_name,
            $data,
            array('%d','%s','%s','%f','%f','%s','%d','%d','%d','%s','%d','%s','%s','%s','%d','%d','%d','%d','%d')
        );
        if ($inserted) {
            $this->maybe_update_row_stats($origin_id, $this->compute_meta_stats($origin_id, $processed_type));
        }
        return $inserted;
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
        if (!empty($filters['api_provider'])) {
            $where .= $wpdb->prepare(" AND api_provider = %s", $filters['api_provider']);
        }
        if (isset($filters['has_nearby']) && $filters['has_nearby'] !== '') {
            $where .= $wpdb->prepare(" AND has_nearby = %d", $filters['has_nearby'] ? 1 : 0);
        }
        if (isset($filters['has_isochrones']) && $filters['has_isochrones'] !== '') {
            $where .= $wpdb->prepare(" AND has_isochrones = %d", $filters['has_isochrones'] ? 1 : 0);
        }
        
        $limit = max(1, (int)$limit);
        $offset = max(0, (int)$offset);
        $batch = max($limit * 2, 100);
        $raw_offset = 0;
        $matched = 0;
        $results = array();

        while (count($results) < $limit) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY processing_date DESC LIMIT %d OFFSET %d",
                $batch,
                $raw_offset
            ));
            if (!$rows) {
                break;
            }
            $raw_offset += $batch;

            foreach ($rows as $row) {
                $row = $this->decorate_row($row);
                if (!$this->row_matches_filters($row, $filters)) {
                    continue;
                }

                if ($matched < $offset) {
                    $matched++;
                    continue;
                }

                $results[] = $row;
                $matched++;

                if (count($results) >= $limit) {
                    break 2;
                }
            }
        }

        return $results;
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

        $rows = $wpdb->get_results("SELECT origin_id, processed_type FROM {$this->table_name}");
        $missing_nearby = 0;
        $missing_iso = 0;
        foreach ($rows as $row) {
            $computed = $this->compute_meta_stats((int)$row->origin_id, $row->processed_type);
            if ($computed['has_nearby'] === 0) {
                $missing_nearby++;
            }
            if ($computed['has_isochrones'] === 0) {
                $missing_iso++;
            }
            $this->maybe_update_row_stats((int)$row->origin_id, $computed);
        }
        $stats['missing_nearby'] = $missing_nearby;
        $stats['missing_isochrones'] = $missing_iso;
        
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
        $total = $this->count_processed_matching($filters);
        $limit = max(1, (int)$limit);
        $total_pages = $limit > 0 ? max(1, (int)ceil($total / $limit)) : 1;
        $current_page = $limit > 0 ? (int)floor($offset / $limit) + 1 : 1;

        return array(
            'total_items' => $total,
            'total_pages' => $total_pages,
            'current_page' => $current_page,
            'limit' => $limit,
            'offset' => $offset
        );
    }

    public function get_filtered_origin_ids(array $filters = array(), $limit = 500) {
        $limit = max(1, (int)$limit);

        global $wpdb;
        $batch = max($limit * 2, 100);
        $raw_offset = 0;
        $results = array();

        while (count($results) < $limit) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY processing_date DESC LIMIT %d OFFSET %d",
                $batch,
                $raw_offset
            ));
            if (!$rows) {
                break;
            }
            $raw_offset += $batch;
            foreach ($rows as $row) {
                $row = $this->decorate_row($row);
                if ($this->row_matches_filters($row, $filters)) {
                    $results[] = array(
                        'origin_id' => (int)$row->origin_id,
                        'processed_type' => $row->processed_type,
                    );
                    if (count($results) >= $limit) {
                        break 2;
                    }
                }
            }
        }

        return $results;
    }

    private function count_processed_matching($filters) {
        global $wpdb;

        $batch = 500;
        $raw_offset = 0;
        $count = 0;

        while (true) {
            $rows = $wpdb->get_results($wpdb->prepare(
                "SELECT * FROM {$this->table_name} ORDER BY processing_date DESC LIMIT %d OFFSET %d",
                $batch,
                $raw_offset
            ));
            if (!$rows) {
                break;
            }
            $raw_offset += $batch;
            foreach ($rows as $row) {
                $row = $this->decorate_row($row, false);
                if ($this->row_matches_filters($row, $filters)) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function row_matches_filters($row, $filters) {
        if (!empty($filters['origin_type']) && $row->origin_type !== $filters['origin_type']) {
            return false;
        }
        if (!empty($filters['status']) && $row->status !== $filters['status']) {
            return false;
        }
        if (!empty($filters['api_provider']) && $row->api_provider !== $filters['api_provider']) {
            return false;
        }
        if (isset($filters['has_nearby']) && $filters['has_nearby'] !== '') {
            if ((int)$row->has_nearby !== ($filters['has_nearby'] ? 1 : 0)) {
                return false;
            }
        }
        if (isset($filters['has_isochrones']) && $filters['has_isochrones'] !== '') {
            if ((int)$row->has_isochrones !== ($filters['has_isochrones'] ? 1 : 0)) {
                return false;
            }
        }
        return true;
    }

    private function decorate_row($row, $update_db = true) {
        $computed = $this->compute_meta_stats((int)$row->origin_id, $row->processed_type);
        $row->cache_size_kb = (int)round($computed['cache_size_kb']);
        $row->nearby_items_count = (int)$computed['nearby_items'];
        $row->iso_features = (int)$computed['iso_features'];
        $row->iso_calls = (int)$computed['iso_calls'];
        $row->has_nearby = (int)$computed['has_nearby'];
        $row->has_isochrones = (int)$computed['has_isochrones'];

        if ($update_db) {
            $this->maybe_update_row_stats((int)$row->origin_id, $computed);
        }

        return $row;
    }

    private function compute_meta_stats($origin_id, $processed_type) {
        static $cache = array();
        if (isset($cache[$origin_id][$processed_type])) {
            return $cache[$origin_id][$processed_type];
        }

        $meta_key = $this->get_nearby_meta_key($processed_type);
        $cache_raw = get_post_meta($origin_id, $meta_key, true);
        $nearby_items = 0;
        $cache_size_kb = 0;
        if (!empty($cache_raw)) {
            if (is_string($cache_raw)) {
                $cache_size_kb = strlen($cache_raw) / 1024;
                $payload = json_decode($cache_raw, true);
            } else {
                $cache_size_kb = strlen(serialize($cache_raw)) / 1024;
                $payload = $cache_raw;
            }
            if (is_array($payload) && isset($payload['items']) && is_array($payload['items'])) {
                $nearby_items = count($payload['items']);
            }
        }

        $iso_raw = get_post_meta($origin_id, 'db_isochrones_v1_foot-walking', true);
        $iso_features = 0;
        if (!empty($iso_raw)) {
            $iso_payload = is_string($iso_raw) ? json_decode($iso_raw, true) : (is_array($iso_raw) ? $iso_raw : array());
            if (is_array($iso_payload) && isset($iso_payload['geojson']['features']) && is_array($iso_payload['geojson']['features'])) {
                $iso_features = count($iso_payload['geojson']['features']);
            }
        }

        $iso_calls = $iso_features > 0 ? 1 : 0;

        $computed = array(
            'cache_size_kb' => $cache_size_kb,
            'nearby_items' => $nearby_items,
            'iso_features' => $iso_features,
            'iso_calls' => $iso_calls,
            'has_nearby' => $nearby_items > 0 ? 1 : 0,
            'has_isochrones' => $iso_features > 0 ? 1 : 0,
        );

        $cache[$origin_id][$processed_type] = $computed;
        return $computed;
    }

    private function maybe_update_row_stats($origin_id, array $computed) {
        global $wpdb;
        $wpdb->update(
            $this->table_name,
            array(
                'cache_size_kb' => (int)round($computed['cache_size_kb']),
                'nearby_items_count' => (int)$computed['nearby_items'],
                'iso_features' => (int)$computed['iso_features'],
                'iso_calls' => (int)$computed['iso_calls'],
                'has_nearby' => (int)$computed['has_nearby'],
                'has_isochrones' => (int)$computed['has_isochrones'],
            ),
            array('origin_id' => $origin_id),
            array('%d','%d','%d','%d','%d','%d'),
            array('%d')
        );
    }

    private function get_nearby_meta_key($processed_type) {
        switch ($processed_type) {
            case 'poi_foot':
                return '_db_nearby_cache_poi_foot';
            case 'rv_foot':
                return '_db_nearby_cache_rv_foot';
            default:
                return '_db_nearby_cache_charger_foot';
        }
    }
}
