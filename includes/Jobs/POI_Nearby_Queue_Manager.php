<?php
/**
 * POI Nearby Queue Manager - správa fronty pro nearby výpočty POI
 * 
 * @package DobityBaterky
 */

namespace DB\Jobs;

if (!defined('ABSPATH')) {
    exit;
}

class POI_Nearby_Queue_Manager {
    
    /**
     * Vytvoří tabulku pro frontu
     */
    public function create_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        $charset_collate = $wpdb->get_charset_collate();
        
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            post_id BIGINT(20) UNSIGNED NOT NULL,
            origin_type VARCHAR(32) NOT NULL DEFAULT 'poi',
            status VARCHAR(16) NOT NULL DEFAULT 'pending',
            attempts INT NOT NULL DEFAULT 0,
            last_error TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            done_at DATETIME NULL,
            dts DATETIME NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY unique_post (post_id),
            KEY status_idx (status),
            KEY post_id_idx (post_id),
            KEY created_at_idx (created_at),
            KEY origin_type_idx (origin_type)
        ) {$charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Zařadí post do fronty pro nearby výpočet
     * 
     * @param int $post_id ID postu
     * @param string $origin_type Typ originu ('poi' nebo 'charging_location')
     * @return bool|int ID záznamu nebo false při chybě
     */
    public function enqueue($post_id, $origin_type = 'poi') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        $post_id = (int) $post_id;
        $origin_type = sanitize_key($origin_type);
        
        if ($post_id <= 0) {
            return false;
        }
        
        // Kontrola, zda post existuje
        $post = get_post($post_id);
        if (!$post) {
            return false;
        }
        
        // Kontrola souřadnic podle typu postu
        if ($post->post_type === 'poi') {
            $lat = (float) get_post_meta($post_id, '_poi_lat', true);
            $lng = (float) get_post_meta($post_id, '_poi_lng', true);
            $cache_key = '_db_nearby_cache_charger_foot';
        } elseif ($post->post_type === 'charging_location') {
            $lat = (float) get_post_meta($post_id, '_db_lat', true);
            $lng = (float) get_post_meta($post_id, '_db_lng', true);
            $cache_key = '_db_nearby_cache_poi_foot';
        } else {
            return false;
        }
        
        if (!$lat || !$lng) {
            return false;
        }
        
        // Kontrola, zda už nemá fresh cache
        $cache = get_post_meta($post_id, $cache_key, true);
        if ($cache) {
            $payload = is_string($cache) ? json_decode($cache, true) : $cache;
            if ($payload && !isset($payload['error']) && !empty($payload['items'])) {
                $computed_at = isset($payload['computed_at']) ? strtotime($payload['computed_at']) : null;
                if ($computed_at) {
                    $ttl_days = 30;
                    $is_stale = (time() - $computed_at) > ($ttl_days * DAY_IN_SECONDS);
                    if (!$is_stale) {
                        // Má fresh cache, není potřeba zařadit
                        return false;
                    }
                }
            }
        }
        
        // Zkontrolovat, zda už existuje záznam pro tento post_id
        $existing = $wpdb->get_row($wpdb->prepare(
            "SELECT id, status FROM {$table_name} WHERE post_id = %d LIMIT 1",
            $post_id
        ), ARRAY_A);
        
        if ($existing) {
            $existing_status = $existing['status'];
            $existing_id = (int) $existing['id'];
            
            // Pokud je pending nebo processing → vrať id, nic nevkládej
            if (in_array($existing_status, array('pending', 'processing'), true)) {
                return $existing_id;
            }
            
            // Pokud je failed → resetuj na pending a zvýš attempts
            if ($existing_status === 'failed') {
                $wpdb->update(
                    $table_name,
                    array(
                        'status' => 'pending',
                        'last_error' => null,
                        'attempts' => $existing['attempts'] + 1,
                        'origin_type' => $origin_type,
                        'dts' => current_time('mysql')
                    ),
                    array('id' => $existing_id),
                    array('%s', '%s', '%d', '%s', '%s'),
                    array('%d')
                );
                return $existing_id;
            }
            
            // Pokud je done → ověř stářím cache
            if ($existing_status === 'done') {
                // Pokud je cache stale → reset na pending
                if ($cache) {
                    $payload = is_string($cache) ? json_decode($cache, true) : $cache;
                    if ($payload && !isset($payload['error']) && !empty($payload['items'])) {
                        $computed_at = isset($payload['computed_at']) ? strtotime($payload['computed_at']) : null;
                        if ($computed_at) {
                            $ttl_days = 30;
                            $is_stale = (time() - $computed_at) > ($ttl_days * DAY_IN_SECONDS);
                            if ($is_stale) {
                                // Cache je stale → reset na pending
                                $wpdb->update(
                                    $table_name,
                                    array(
                                        'status' => 'pending',
                                        'origin_type' => $origin_type,
                                        'dts' => current_time('mysql')
                                    ),
                                    array('id' => $existing_id),
                                    array('%s', '%s', '%s'),
                                    array('%d')
                                );
                                return $existing_id;
                            }
                        }
                    }
                }
                // Cache není stale → přeskočit
                return false;
            }
        }
        
        // Cleanup done záznamy starší než 30 dní (periodicky)
        $this->cleanup_old_done();
        
        // Vložit nový záznam
        $result = $wpdb->insert(
            $table_name,
            array(
                'post_id' => $post_id,
                'origin_type' => $origin_type,
                'status' => 'pending',
                'attempts' => 0,
                'created_at' => current_time('mysql'),
            ),
            array('%d', '%s', '%s', '%d', '%s')
        );
        
        if ($result === false) {
            // Pokud selhal insert kvůli unique constraint, zkusit získat existující
            $existing = $wpdb->get_var($wpdb->prepare(
                "SELECT id FROM {$table_name} WHERE post_id = %d LIMIT 1",
                $post_id
            ));
            if ($existing) {
                return (int) $existing;
            }
            error_log('[POI Nearby Queue] Chyba při vkládání do fronty: ' . $wpdb->last_error);
            return false;
        }
        
        return $wpdb->insert_id;
    }
    
    /**
     * Cleanup done záznamy starší než 30 dní (voláno periodicky)
     */
    private function cleanup_old_done() {
        // Spustit cleanup pouze v 10% případů (aby nebylo drahé)
        if (rand(1, 10) !== 1) {
            return;
        }
        $this->clear_done_older_than(30);
    }
    
    /**
     * Získá pending záznamy pro zpracování
     * 
     * @param int $limit Maximální počet záznamů
     * @return array
     */
    public function get_pending($limit = 50) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        // Vrátit processing záznamy starší než 30 minut na pending (dead-letter recovery)
        $wpdb->query($wpdb->prepare(
            "UPDATE {$table_name} 
             SET status = 'pending', attempts = attempts + 1 
             WHERE status = 'processing' 
             AND dts < DATE_SUB(NOW(), INTERVAL 30 MINUTE)"
        ));
        
        // Získat pending záznamy
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE status = 'pending' 
             ORDER BY created_at ASC 
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Získá záznam podle ID
     * 
     * @param int $id ID záznamu
     * @return array|null
     */
    public function get_item($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        return $wpdb->get_row($wpdb->prepare(
            "SELECT * FROM {$table_name} WHERE id = %d LIMIT 1",
            $id
        ), ARRAY_A);
    }
    
    /**
     * Označí záznam jako processing
     * 
     * @param int $id ID záznamu
     * @return bool
     */
    public function mark_processing($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        return $wpdb->update(
            $table_name,
            array('status' => 'processing', 'dts' => current_time('mysql')),
            array('id' => $id),
            array('%s', '%s'),
            array('%d')
        ) !== false;
    }
    
    /**
     * Označí záznam jako done
     * 
     * @param int $id ID záznamu
     * @return bool
     */
    public function mark_done($id) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        return $wpdb->update(
            $table_name,
            array(
                'status' => 'done',
                'done_at' => current_time('mysql'),
                'dts' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s', '%s'),
            array('%d')
        ) !== false;
    }
    
    /**
     * Označí záznam jako failed
     * 
     * @param int $id ID záznamu
     * @param string $error Chybová zpráva
     * @return bool
     */
    public function mark_failed($id, $error = '') {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        return $wpdb->update(
            $table_name,
            array(
                'status' => 'failed',
                'last_error' => $error,
                'attempts' => $wpdb->get_var($wpdb->prepare("SELECT attempts FROM {$table_name} WHERE id = %d", $id)) + 1,
                'dts' => current_time('mysql')
            ),
            array('id' => $id),
            array('%s', '%s', '%d', '%s'),
            array('%d')
        ) !== false;
    }
    
    /**
     * Získá statistiky fronty
     * 
     * @return array
     */
    public function get_stats() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        $stats = array(
            'pending' => 0,
            'processing' => 0,
            'done_today' => 0,
            'failed' => 0,
            'processed_last_24h' => 0,
            'processed_last_hour' => 0,
        );
        
        // Pending
        $stats['pending'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = 'pending'"
        );
        
        // Processing
        $stats['processing'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = 'processing'"
        );
        
        // Done dnes
        $stats['done_today'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE status = 'done' 
             AND DATE(done_at) = CURDATE()"
        );
        
        // Failed
        $stats['failed'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} WHERE status = 'failed'"
        );
        
        // Zpracováno za posledních 24 hodin
        $stats['processed_last_24h'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE status = 'done' 
             AND done_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
        );
        
        // Zpracováno za poslední hodinu
        $stats['processed_last_hour'] = (int) $wpdb->get_var(
            "SELECT COUNT(*) FROM {$table_name} 
             WHERE status = 'done' 
             AND done_at >= DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        
        return $stats;
    }
    
    /**
     * Získá posledních N failed záznamů
     * 
     * @param int $limit Počet záznamů
     * @return array
     */
    public function get_failed_items($limit = 20) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        return $wpdb->get_results($wpdb->prepare(
            "SELECT * FROM {$table_name} 
             WHERE status = 'failed' 
             ORDER BY dts DESC 
             LIMIT %d",
            $limit
        ), ARRAY_A);
    }
    
    /**
     * Resetuje failed záznamy na pending
     * 
     * @return int Počet resetovaných záznamů
     */
    public function reset_failed_to_pending() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        $updated = $wpdb->query(
            "UPDATE {$table_name} 
             SET status = 'pending', last_error = NULL, dts = NOW() 
             WHERE status = 'failed'"
        );
        
        return $updated !== false ? $updated : 0;
    }
    
    /**
     * Smaže done záznamy starší než X dní
     * 
     * @param int $days Počet dní
     * @return int Počet smazaných záznamů
     */
    public function clear_done_older_than($days = 30) {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_nearby_queue';
        
        $deleted = $wpdb->query($wpdb->prepare(
            "DELETE FROM {$table_name} 
             WHERE status = 'done' 
             AND done_at < DATE_SUB(NOW(), INTERVAL %d DAY)",
            $days
        ));
        
        return $deleted !== false ? $deleted : 0;
    }
}

