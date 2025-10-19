<?php
/**
 * Legacy Admin Manager - Správce starých admin rozhraní pro zpětnou kompatibilitu
 * @package DobityBaterky
 */

namespace DB\Admin;

use DB\Admin\Nearby_Queue_Admin;
use DB\Admin\POI_Discovery_Admin;
use DB\Admin\Charging_Discovery_Admin;

class Legacy_Admin_Manager {
    
    private $nearby_queue_admin;
    private $poi_discovery_admin;
    private $charging_discovery_admin;
    
    public function __construct() {
        // Inicializovat staré admin komponenty pouze pokud jsou potřeba
        if (get_option('db_enable_legacy_admin', false)) {
            $this->nearby_queue_admin = new Nearby_Queue_Admin();
            $this->poi_discovery_admin = new POI_Discovery_Admin();
            $this->charging_discovery_admin = new Charging_Discovery_Admin();
        }
    }
    
    /**
     * Zkontroluje, zda jsou staré admin komponenty aktivní
     */
    public function is_legacy_admin_enabled(): bool {
        return get_option('db_enable_legacy_admin', false);
    }
    
    /**
     * Povolí staré admin komponenty
     */
    public function enable_legacy_admin(): void {
        update_option('db_enable_legacy_admin', true);
    }
    
    /**
     * Zakáže staré admin komponenty
     */
    public function disable_legacy_admin(): void {
        update_option('db_enable_legacy_admin', false);
    }
    
    /**
     * Získá seznam dostupných legacy admin komponent
     */
    public function get_legacy_components(): array {
        return array(
            'nearby_queue' => array(
                'name' => 'Nearby Queue Admin',
                'description' => 'Správa fronty nearby bodů',
                'enabled' => $this->nearby_queue_admin !== null
            ),
            'poi_discovery' => array(
                'name' => 'POI Discovery Admin',
                'description' => 'Správa POI discovery procesů',
                'enabled' => $this->poi_discovery_admin !== null
            ),
            'charging_discovery' => array(
                'name' => 'Charging Discovery Admin',
                'description' => 'Správa charging discovery procesů',
                'enabled' => $this->charging_discovery_admin !== null
            )
        );
    }
    
    /**
     * Získá statistiky z legacy komponent
     */
    public function get_legacy_stats(): array {
        $stats = array(
            'nearby_queue' => array(),
            'poi_discovery' => array(),
            'charging_discovery' => array()
        );
        
        if ($this->nearby_queue_admin) {
            $stats['nearby_queue'] = $this->get_nearby_queue_stats();
        }
        
        if ($this->poi_discovery_admin) {
            $stats['poi_discovery'] = $this->get_poi_discovery_stats();
        }
        
        if ($this->charging_discovery_admin) {
            $stats['charging_discovery'] = $this->get_charging_discovery_stats();
        }
        
        return $stats;
    }
    
    /**
     * Získá statistiky z nearby queue
     */
    private function get_nearby_queue_stats(): array {
        global $wpdb;
        
        $stats = array();
        
        // Počet položek ve frontě
        $stats['queue_count'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_queue_%'
        ");
        
        // Počet zpracovaných položek
        $stats['processed_count'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_processed_%'
        ");
        
        // Počet chybných položek
        $stats['failed_count'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_failed_%'
        ");
        
        return $stats;
    }
    
    /**
     * Získá statistiky z POI discovery
     */
    private function get_poi_discovery_stats(): array {
        global $wpdb;
        
        $stats = array();
        
        // Počet POI bez Google Places ID
        $stats['missing_google_places'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_google_place_id'
            WHERE p.post_type = 'poi' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        // Počet POI bez Tripadvisor ID
        $stats['missing_tripadvisor'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_tripadvisor_id'
            WHERE p.post_type = 'poi' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        return $stats;
    }
    
    /**
     * Získá statistiky z charging discovery
     */
    private function get_charging_discovery_stats(): array {
        global $wpdb;
        
        $stats = array();
        
        // Počet charging locations bez OCM ID
        $stats['missing_ocm_id'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ocm_id'
            WHERE p.post_type = 'charging_location' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        // Počet charging locations bez Google Places ID
        $stats['missing_google_places'] = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_google_place_id'
            WHERE p.post_type = 'charging_location' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
        ");
        
        return $stats;
    }
    
    /**
     * Migruje data z legacy komponent do nového systému
     */
    public function migrate_to_ondemand(): array {
        $migration_results = array(
            'nearby_queue' => array(),
            'poi_discovery' => array(),
            'charging_discovery' => array()
        );
        
        // Migrace nearby queue
        if ($this->nearby_queue_admin) {
            $migration_results['nearby_queue'] = $this->migrate_nearby_queue();
        }
        
        // Migrace POI discovery
        if ($this->poi_discovery_admin) {
            $migration_results['poi_discovery'] = $this->migrate_poi_discovery();
        }
        
        // Migrace charging discovery
        if ($this->charging_discovery_admin) {
            $migration_results['charging_discovery'] = $this->migrate_charging_discovery();
        }
        
        return $migration_results;
    }
    
    /**
     * Migruje nearby queue data
     */
    private function migrate_nearby_queue(): array {
        global $wpdb;
        
        $results = array(
            'migrated' => 0,
            'errors' => 0,
            'skipped' => 0
        );
        
        // Získat všechny položky z fronty
        $queue_items = $wpdb->get_results("
            SELECT option_name, option_value
            FROM {$wpdb->options}
            WHERE option_name LIKE '_transient_db_nearby_queue_%'
        ");
        
        foreach ($queue_items as $item) {
            try {
                $data = maybe_unserialize($item->option_value);
                
                if (is_array($data) && isset($data['point_id'], $data['point_type'])) {
                    // Zpracovat položku on-demand
                    $this->process_ondemand_item($data['point_id'], $data['point_type']);
                    $results['migrated']++;
                } else {
                    $results['skipped']++;
                }
                
            } catch (\Exception $e) {
                $results['errors']++;
                error_log("Migration error for {$item->option_name}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Migruje POI discovery data
     */
    private function migrate_poi_discovery(): array {
        global $wpdb;
        
        $results = array(
            'migrated' => 0,
            'errors' => 0,
            'skipped' => 0
        );
        
        // Získat POI bez Google Places ID
        $pois = $wpdb->get_results("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_google_place_id'
            WHERE p.post_type = 'poi' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT 100
        ");
        
        foreach ($pois as $poi) {
            try {
                $this->process_ondemand_item($poi->ID, 'poi');
                $results['migrated']++;
            } catch (\Exception $e) {
                $results['errors']++;
                error_log("POI migration error for {$poi->ID}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Migruje charging discovery data
     */
    private function migrate_charging_discovery(): array {
        global $wpdb;
        
        $results = array(
            'migrated' => 0,
            'errors' => 0,
            'skipped' => 0
        );
        
        // Získat charging locations bez OCM ID
        $charging_locations = $wpdb->get_results("
            SELECT p.ID
            FROM {$wpdb->posts} p
            LEFT JOIN {$wpdb->postmeta} pm ON pm.post_id = p.ID AND pm.meta_key = '_ocm_id'
            WHERE p.post_type = 'charging_location' 
            AND p.post_status = 'publish'
            AND (pm.meta_value IS NULL OR pm.meta_value = '')
            LIMIT 100
        ");
        
        foreach ($charging_locations as $location) {
            try {
                $this->process_ondemand_item($location->ID, 'charging_location');
                $results['migrated']++;
            } catch (\Exception $e) {
                $results['errors']++;
                error_log("Charging location migration error for {$location->ID}: " . $e->getMessage());
            }
        }
        
        return $results;
    }
    
    /**
     * Zpracuje položku on-demand
     */
    private function process_ondemand_item(int $point_id, string $point_type): void {
        // Implementace on-demand zpracování
        // Toto by mělo volat On_Demand_Processor
        error_log("Processing on-demand item: {$point_id} ({$point_type})");
    }
    
    /**
     * Vyčistí legacy data
     */
    public function cleanup_legacy_data(): array {
        global $wpdb;
        
        $results = array(
            'cleaned' => 0,
            'errors' => 0
        );
        
        try {
            // Vymazat nearby queue transients
            $cleaned = $wpdb->query("
                DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_db_nearby_%'
            ");
            
            $results['cleaned'] += $cleaned;
            
            // Vymazat POI discovery transients
            $cleaned = $wpdb->query("
                DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_db_poi_%'
            ");
            
            $results['cleaned'] += $cleaned;
            
            // Vymazat charging discovery transients
            $cleaned = $wpdb->query("
                DELETE FROM {$wpdb->options}
                WHERE option_name LIKE '_transient_db_charging_%'
            ");
            
            $results['cleaned'] += $cleaned;
            
        } catch (\Exception $e) {
            $results['errors']++;
            error_log("Legacy cleanup error: " . $e->getMessage());
        }
        
        return $results;
    }
}
