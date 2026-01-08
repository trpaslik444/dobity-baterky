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
        // Vytvoření tabulky pro POI nearby queue
        self::create_poi_nearby_queue_table();

        // Vytvoření tabulky pro sledování denní kvóty Places API
        self::create_places_usage_table();
        
        // Vytvoření tabulek pro EV Data Bridge
        self::create_ev_sources_table();
        self::create_ev_import_files_table();
        
        // Seedování základních zdrojů do tabulky ev_sources
        self::seed_ev_sources();
        
        // Vypnout automatické zpracování při aktivaci
        update_option('db_nearby_auto_enabled', false);
        
        // Naplánovat POI nearby cron
        self::schedule_poi_nearby_cron();
    }
    
    /**
     * Naplánuje POI nearby cron event
     */
    private static function schedule_poi_nearby_cron() {
        if (file_exists(__DIR__ . '/Jobs/POI_Nearby_Cron.php')) {
            require_once __DIR__ . '/Jobs/POI_Nearby_Cron.php';
            if (class_exists('DB\Jobs\POI_Nearby_Cron')) {
                $cron = new \DB\Jobs\POI_Nearby_Cron();
                $cron->schedule_cron();
            }
        }
    }

    /**
     * Deaktivace pluginu
     */
    public static function deactivate() {
        // Flush rewrite rules
        flush_rewrite_rules();
        
        // Zrušit POI nearby cron
        self::unschedule_poi_nearby_cron();
    }
    
    /**
     * Zruší POI nearby cron event
     */
    private static function unschedule_poi_nearby_cron() {
        if (file_exists(__DIR__ . '/Jobs/POI_Nearby_Cron.php')) {
            require_once __DIR__ . '/Jobs/POI_Nearby_Cron.php';
            if (class_exists('DB\Jobs\POI_Nearby_Cron')) {
                $cron = new \DB\Jobs\POI_Nearby_Cron();
                $cron->unschedule_cron();
            }
        }
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
        
        // Zajistit existenci tabulky pro POI nearby queue
        $poi_queue_table = $wpdb->prefix . 'db_nearby_queue';
        $exists_poi_queue = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $poi_queue_table ) );
        if ( $exists_poi_queue !== $poi_queue_table ) {
            self::create_poi_nearby_queue_table();
        }
        
        // Zajistit existenci tabulek pro EV Data Bridge
        $ev_sources_table = $wpdb->prefix . 'ev_sources';
        $exists_ev_sources = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ev_sources_table ) );
        if ( $exists_ev_sources !== $ev_sources_table ) {
            self::create_ev_sources_table();
        }
        
        $ev_import_files_table = $wpdb->prefix . 'ev_import_files';
        $exists_ev_import_files = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $ev_import_files_table ) );
        if ( $exists_ev_import_files !== $ev_import_files_table ) {
            self::create_ev_import_files_table();
        }
        
        // Zajistit seedování zdrojů (pokud tabulka existuje, ale je prázdná)
        $ev_sources_table = $wpdb->prefix . 'ev_sources';
        $source_count = $wpdb->get_var("SELECT COUNT(*) FROM {$ev_sources_table}");
        if ($source_count == 0) {
            self::seed_ev_sources();
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
    
    /**
     * Vytvoří DB tabulku pro POI nearby queue
     */
    private static function create_poi_nearby_queue_table() {
        if (file_exists(__DIR__ . '/Jobs/POI_Nearby_Queue_Manager.php')) {
            require_once __DIR__ . '/Jobs/POI_Nearby_Queue_Manager.php';
            if (class_exists('DB\Jobs\POI_Nearby_Queue_Manager')) {
                $queue_manager = new \DB\Jobs\POI_Nearby_Queue_Manager();
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
    
    /**
     * Vytvoří tabulku pro EV Data Bridge zdroje (ev_sources)
     */
    private static function create_ev_sources_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ev_sources';
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            country_code VARCHAR(2) NOT NULL,
            adapter_key VARCHAR(50) NOT NULL,
            landing_url TEXT DEFAULT NULL,
            fetch_type VARCHAR(20) NOT NULL DEFAULT 'rest',
            update_frequency VARCHAR(20) NOT NULL DEFAULT 'monthly',
            enabled TINYINT(1) NOT NULL DEFAULT 1,
            last_version_label VARCHAR(255) DEFAULT NULL,
            last_success_at DATETIME DEFAULT NULL,
            last_error_at DATETIME DEFAULT NULL,
            last_error_message TEXT DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            UNIQUE KEY unique_country_adapter (country_code, adapter_key),
            KEY country_code_idx (country_code),
            KEY adapter_key_idx (adapter_key),
            KEY enabled_idx (enabled)
        ) {$charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Vytvoří tabulku pro EV Data Bridge import soubory (ev_import_files)
     */
    private static function create_ev_import_files_table() {
        global $wpdb;
        $table_name = $wpdb->prefix . 'ev_import_files';
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        
        $sql = "CREATE TABLE {$table_name} (
            id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
            source_id BIGINT(20) UNSIGNED NOT NULL,
            source_url TEXT NOT NULL,
            file_path TEXT NOT NULL,
            file_size BIGINT(20) UNSIGNED NOT NULL,
            file_sha256 VARCHAR(64) NOT NULL,
            content_type VARCHAR(255) DEFAULT NULL,
            etag VARCHAR(255) DEFAULT NULL,
            last_modified VARCHAR(255) DEFAULT NULL,
            status VARCHAR(20) NOT NULL DEFAULT 'pending',
            download_completed_at DATETIME DEFAULT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY  (id),
            KEY source_id_idx (source_id),
            KEY file_sha256_idx (file_sha256),
            KEY status_idx (status),
            KEY download_completed_at_idx (download_completed_at)
        ) {$charset_collate};";
        
        dbDelta($sql);
    }
    
    /**
     * Seeduje základní zdroje dat do tabulky ev_sources
     */
    private static function seed_ev_sources(): void {
        global $wpdb;
        $table = $wpdb->prefix . 'ev_sources';
        
        // Zkontrolovat, zda tabulka existuje
        $table_exists = $wpdb->get_var($wpdb->prepare("SHOW TABLES LIKE %s", $table));
        if ($table_exists !== $table) {
            return; // Tabulka ještě neexistuje, seedování proběhne později
        }
        
        // Základní zdroje dat - implementované adaptéry
        $sources = [
            [
                'country_code' => 'CZ',
                'adapter_key' => 'cz_mpo',
                'landing_url' => 'https://www.mpo.gov.cz/cz/energetika/statistika/evidence-dobijecich-stanic/',
                'fetch_type' => 'xlsx',
                'update_frequency' => 'monthly',
                'enabled' => 1
            ],
            [
                'country_code' => 'DE',
                'adapter_key' => 'de_bnetza',
                'landing_url' => 'https://www.bundesnetzagentur.de/DE/Sachgebiete/ElektrizitaetundGas/Unternehmen_Institutionen/HandelundVermarktung/Ladesaeulenregister/Ladesaeulenregister.html',
                'fetch_type' => 'csv',
                'update_frequency' => 'monthly',
                'enabled' => 1
            ],
            [
                'country_code' => 'FR',
                'adapter_key' => 'fr_irve',
                'landing_url' => 'https://www.data.gouv.fr/fr/datasets/fichier-consolide-des-bornes-de-recharge-pour-vehicules-electriques/',
                'fetch_type' => 'rest',
                'update_frequency' => 'monthly',
                'enabled' => 1
            ],
            [
                'country_code' => 'ES',
                'adapter_key' => 'es_arcgis',
                'landing_url' => 'https://services.arcgis.com/HRPe58bUySqysFmQ/arcgis/rest/services/Red_Recarga/FeatureServer/0',
                'fetch_type' => 'arcgis',
                'update_frequency' => 'monthly',
                'enabled' => 1
            ],
            [
                'country_code' => 'AT',
                'adapter_key' => 'at_econtrol',
                'landing_url' => 'https://www.e-control.at/',
                'fetch_type' => 'rest',
                'update_frequency' => 'monthly',
                'enabled' => 1
            ],
        ];
        
        foreach ($sources as $source) {
            // Použít INSERT ... ON DUPLICATE KEY UPDATE pro idempotentní seedování
            $wpdb->replace(
                $table,
                $source,
                [
                    '%s', // country_code
                    '%s', // adapter_key
                    '%s', // landing_url
                    '%s', // fetch_type
                    '%s', // update_frequency
                    '%d'  // enabled
                ]
            );
        }
    }
}
