<?php
/**
 * Třída pro aktivaci a deaktivaci pluginu
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Aktivace a deaktivace pluginu
 */
class Activation {

    /**
     * Aktivace pluginu
     */
    public static function activate() {
        // Flush rewrite rules pro Custom Post Type
        flush_rewrite_rules();
        
        // Vytvoření výchozích rolí nebo nastavení (pokud je potřeba)
        self::create_default_settings();

        // Vytvoření tabulky pro zpětnou vazbu
        self::create_feedback_table();
        
        // Vytvoření tabulky pro nearby queue
        self::create_nearby_queue_table();
        // Vytvoření tabulky pro POI discovery queue
        self::create_poi_discovery_queue_table();

        // Vytvoření tabulky pro sledování denní kvóty Places API
        self::create_places_usage_table();
        
        // Vypnout automatické zpracování při aktivaci
        update_option('db_nearby_auto_enabled', false);
    }

    /**
     * Deaktivace pluginu
     */
    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
    }

    /**
     * Vytvoření výchozích nastavení
     */
    private static function create_default_settings() {
        // Zde můžete přidat výchozí nastavení pluginu
        // Například výchozí možnosti, které se uloží do wp_options
    }

    /**
     * Vytvoří DB tabulku pro ukládání zpětné vazby
     */
    private static function create_feedback_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_feedback';
        $charset_collate = $wpdb->get_charset_collate();

        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            status VARCHAR(20) NOT NULL DEFAULT 'open',
            type VARCHAR(20) DEFAULT NULL,
            severity VARCHAR(20) DEFAULT NULL,
            page_url TEXT NOT NULL,
            page_type VARCHAR(50) DEFAULT NULL,
            template VARCHAR(100) DEFAULT NULL,
            component_key VARCHAR(150) DEFAULT NULL,
            dom_selector TEXT DEFAULT NULL,
            text_snippet TEXT DEFAULT NULL,
            message LONGTEXT NOT NULL,
            screenshot_attachment_id BIGINT(20) UNSIGNED DEFAULT NULL,
            meta_json LONGTEXT DEFAULT NULL,
            user_id BIGINT(20) UNSIGNED DEFAULT NULL,
            user_agent VARCHAR(255) DEFAULT NULL,
            locale VARCHAR(20) DEFAULT NULL,
            ip_hash VARCHAR(64) DEFAULT NULL,
            PRIMARY KEY  (id),
            KEY status_idx (status),
            KEY component_idx (component_key),
            KEY page_type_idx (page_type)
        ) {$charset_collate};";

        dbDelta($sql);
    }

    /**
     * Zkontroluje existenci tabulek a vytvoří chybějící (pro případ update bez reaktivace)
     */
    public static function ensure_tables() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_feedback';
        $exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );
        if ( $exists !== $table_name ) {
            self::create_feedback_table();
        }

        // Zajistit existenci tabulky pro denní kvóty Places API
        $usage_table = $wpdb->prefix . 'db_places_usage';
        $exists_usage = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $usage_table ) );
        if ( $exists_usage !== $usage_table ) {
            self::create_places_usage_table();
        }
    }
    
    /**
     * Vytvoří DB tabulku pro nearby queue
     */
    private static function create_nearby_queue_table() {
        if (file_exists(__DIR__ . '/Jobs/Nearby_Queue_Manager.php')) {
            require_once __DIR__ . '/Jobs/Nearby_Queue_Manager.php';
            if (class_exists('DB\Jobs\Nearby_Queue_Manager')) {
                $queue_manager = new \DB\Jobs\Nearby_Queue_Manager();
                $queue_manager->create_table();
            }
        }
    }

    private static function create_poi_discovery_queue_table() {
        if (file_exists(__DIR__ . '/Jobs/POI_Discovery_Queue_Manager.php')) {
            require_once __DIR__ . '/Jobs/POI_Discovery_Queue_Manager.php';
            if (class_exists('DB\Jobs\POI_Discovery_Queue_Manager')) {
                $qm = new \DB\Jobs\POI_Discovery_Queue_Manager();
                $qm->create_table();
            }
        }
    }

    /**
     * Vytvoří tabulku pro sledování denní kvóty Google Places API.
     */
    private static function create_places_usage_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'db_places_usage';
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table_name} (
            usage_date DATE NOT NULL,
            api_name VARCHAR(64) NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (usage_date, api_name),
            KEY api_name_idx (api_name)
        ) {$charset_collate};";
        dbDelta($sql);
    }
}
