<?php
/**
 * Database Optimizer - Optimalizace databázových dotazů
 * @package DobityBaterky
 */

namespace DB;

class Database_Optimizer {
    
    public static function create_indexes() {
        global $wpdb;
        
        $indexes_created = 0;
        $errors = array();
        
        // Indexy pro postmeta tabulku - klíčové pro nearby dotazy
        $meta_indexes = array(
            // Index pro lat/lng meta klíče
            "CREATE INDEX IF NOT EXISTS idx_postmeta_lat ON {$wpdb->postmeta} (meta_key, meta_value+0) WHERE meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')",
            "CREATE INDEX IF NOT EXISTS idx_postmeta_lng ON {$wpdb->postmeta} (meta_key, meta_value+0) WHERE meta_key IN ('_db_lng', '_poi_lng', '_rv_lng')",
            
            // Kompozitní indexy pro post_id + meta_key
            "CREATE INDEX IF NOT EXISTS idx_postmeta_post_key ON {$wpdb->postmeta} (post_id, meta_key) WHERE meta_key IN ('_db_lat', '_db_lng', '_poi_lat', '_poi_lng', '_rv_lat', '_rv_lng')",
            
            // Index pro post_type + post_status
            "CREATE INDEX IF NOT EXISTS idx_posts_type_status ON {$wpdb->posts} (post_type, post_status) WHERE post_type IN ('poi', 'charging_location', 'rv_spot')",
        );
        
        foreach ($meta_indexes as $sql) {
            $result = $wpdb->query($sql);
            if ($result === false) {
                $errors[] = "Chyba při vytváření indexu: " . $wpdb->last_error;
            } else {
                $indexes_created++;
            }
        }
        
        // Vytvoření optimalizované tabulky pro nearby cache
        $cache_table = $wpdb->prefix . 'db_nearby_cache';
        $cache_sql = "CREATE TABLE IF NOT EXISTS {$cache_table} (
            id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
            origin_id bigint(20) unsigned NOT NULL,
            origin_type varchar(20) NOT NULL,
            target_type varchar(20) NOT NULL,
            lat decimal(10,8) NOT NULL,
            lng decimal(11,8) NOT NULL,
            distance_km decimal(8,4) NOT NULL,
            post_id bigint(20) unsigned NOT NULL,
            post_type varchar(20) NOT NULL,
            post_title varchar(255) NOT NULL,
            created_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_origin (origin_id, origin_type, target_type),
            KEY idx_location (lat, lng),
            KEY idx_distance (distance_km),
            KEY idx_post (post_id, post_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";
        
        $result = $wpdb->query($cache_sql);
        if ($result === false) {
            $errors[] = "Chyba při vytváření cache tabulky: " . $wpdb->last_error;
        } else {
            $indexes_created++;
        }
        
        return array(
            'indexes_created' => $indexes_created,
            'errors' => $errors
        );
    }
    
    public static function cleanup_old_cache() {
        global $wpdb;
        
        $cache_table = $wpdb->prefix . 'db_nearby_cache';
        
        // Smazat cache starší než 7 dní
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$cache_table} WHERE created_at < %s",
            date('Y-m-d H:i:s', time() - (7 * DAY_IN_SECONDS))
        ));
        
        return $deleted;
    }
    
    public static function get_performance_stats() {
        global $wpdb;
        
        $stats = array();
        
        // Počet záznamů v cache tabulce
        $cache_table = $wpdb->prefix . 'db_nearby_cache';
        $stats['cache_records'] = $wpdb->get_var("SELECT COUNT(*) FROM {$cache_table}");
        
        // Velikost cache tabulky
        $stats['cache_size'] = $wpdb->get_var("
            SELECT ROUND(((data_length + index_length) / 1024 / 1024), 2) AS 'Size in MB'
            FROM information_schema.tables 
            WHERE table_schema = DATABASE() 
            AND table_name = '{$cache_table}'
        ");
        
        // Počet postmeta záznamů s koordináty
        $stats['meta_records'] = $wpdb->get_var("
            SELECT COUNT(*) FROM {$wpdb->postmeta} 
            WHERE meta_key IN ('_db_lat', '_db_lng', '_poi_lat', '_poi_lng', '_rv_lat', '_rv_lng')
        ");
        
        return $stats;
    }
}
