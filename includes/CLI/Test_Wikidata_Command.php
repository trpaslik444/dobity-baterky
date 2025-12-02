<?php
/**
 * WP-CLI příkaz pro testování Wikidata Provider
 * 
 * Použití: wp db test-wikidata [--limit=5] [--radius=2000]
 */

namespace DB\CLI;

if (!defined('WP_CLI')) {
    return;
}

class Test_Wikidata_Command {
    
    /**
     * Testovat stahování POIs z Wikidata pro nabíječky
     * 
     * ## OPTIONS
     * 
     * [--limit=<number>]
     * : Počet nabíječek k testování
     * ---
     * default: 5
     * 
     * [--radius=<number>]
     * : Radius v metrech
     * ---
     * default: 2000
     * 
     * ## EXAMPLES
     * 
     *     wp db test-wikidata --limit=3 --radius=2000
     * 
     * @param array $args
     * @param array $assoc_args
     */
    public function __invoke($args, $assoc_args) {
        $limit = isset($assoc_args['limit']) ? (int) $assoc_args['limit'] : 5;
        $radius = isset($assoc_args['radius']) ? (int) $assoc_args['radius'] : 2000;
        
        WP_CLI::log("🔍 Testování Wikidata Provider");
        WP_CLI::log("Limit nabíječek: {$limit}");
        WP_CLI::log("Radius: {$radius} metrů");
        WP_CLI::log("");
        
        // Načíst Provider třídy
        if (!class_exists('DB\\Providers\\Wikidata_Provider')) {
            require_once __DIR__ . '/../../Providers/Wikidata_Provider.php';
        }
        
        // Najít nabíječky s GPS souřadnicemi
        global $wpdb;
        
        $charging_locations = $wpdb->get_results($wpdb->prepare("
            SELECT p.ID, p.post_title,
                   pm_lat.meta_value AS lat,
                   pm_lng.meta_value AS lng
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_db_lat'
            INNER JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_db_lng'
            WHERE p.post_type = 'charging_location'
            AND p.post_status = 'publish'
            AND pm_lat.meta_value != ''
            AND pm_lng.meta_value != ''
            ORDER BY p.ID DESC
            LIMIT %d
        ", $limit));
        
        if (empty($charging_locations)) {
            WP_CLI::error('Nenalezeny žádné nabíječky s GPS souřadnicemi');
        }
        
        WP_CLI::log("✅ Nalezeno " . count($charging_locations) . " nabíječek");
        WP_CLI::log("");
        
        // Inicializovat Wikidata Provider
        $wikidata = new \DB\Providers\Wikidata_Provider();
        
        $total_pois = 0;
        $total_unique = 0;
        $categories_found = array();
        $all_pois = array();
        $errors = array();
        
        foreach ($charging_locations as $location) {
            $lat = (float) $location->lat;
            $lng = (float) $location->lng;
            
            WP_CLI::log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
            WP_CLI::log("📍 Nabíječka: #{$location->ID} - {$location->post_title}");
            WP_CLI::log("   GPS: {$lat}, {$lng}");
            WP_CLI::log("");
            
            // Zavolat Wikidata Provider
            $allowed_categories = array('museum', 'gallery', 'tourist_attraction', 'viewpoint', 'park');
            
            WP_CLI::log("   🔄 Stahování POIs z Wikidata...");
            $start_time = microtime(true);
            
            try {
                $pois = $wikidata->search_around($lat, $lng, $radius, $allowed_categories);
                $duration = round((microtime(true) - $start_time) * 1000, 2);
                
                WP_CLI::log("   ✅ Nalezeno " . count($pois) . " POIs (trvalo {$duration}ms)");
                WP_CLI::log("");
                
                if (!empty($pois)) {
                    WP_CLI::log("   📋 Seznam POIs:");
                    foreach ($pois as $index => $poi) {
                        $category = isset($poi['category']) ? $poi['category'] : 'N/A';
                        $source_id = isset($poi['source_id']) ? $poi['source_id'] : 'N/A';
                        
                        WP_CLI::log(sprintf(
                            "   %d. %s",
                            $index + 1,
                            $poi['name']
                        ));
                        WP_CLI::log(sprintf(
                            "      📍 GPS: %.6f, %.6f | Kategorie: %s | Wikidata ID: %s",
                            $poi['lat'],
                            $poi['lon'],
                            $category,
                            $source_id
                        ));
                        
                        // Statistiky
                        if (!isset($categories_found[$category])) {
                            $categories_found[$category] = 0;
                        }
                        $categories_found[$category]++;
                        
                        // Unikátní POIs (podle source_id)
                        $poi_key = $poi['source'] . ':' . ($poi['source_id'] ?? 'unknown');
                        if (!isset($all_pois[$poi_key])) {
                            $all_pois[$poi_key] = $poi;
                            $total_unique++;
                        }
                    }
                    WP_CLI::log("");
                } else {
                    WP_CLI::log("   ⚠️  Žádné POIs nenalezeny");
                    WP_CLI::log("");
                }
            } catch (\Exception $e) {
                WP_CLI::warning("   ❌ Chyba při stahování POIs: " . $e->getMessage());
                $errors[] = array(
                    'location' => $location->post_title,
                    'error' => $e->getMessage()
                );
            }
            
            $total_pois += isset($pois) ? count($pois) : 0;
            
            // Malá pauza mezi requesty (rate limiting)
            if (count($charging_locations) > 1) {
                sleep(1);
            }
        }
        
        // Shrnutí
        WP_CLI::log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        WP_CLI::log("📊 SHRNUTÍ");
        WP_CLI::log("━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━");
        WP_CLI::log("Celkem testovaných nabíječek: " . count($charging_locations));
        WP_CLI::log("Celkem nalezených POIs: {$total_pois}");
        WP_CLI::log("Unikátních POIs: {$total_unique}");
        WP_CLI::log("");
        
        if (!empty($categories_found)) {
            WP_CLI::log("Kategorie POIs:");
            arsort($categories_found);
            foreach ($categories_found as $category => $count) {
                WP_CLI::log("  - {$category}: {$count}");
            }
            WP_CLI::log("");
        }
        
        if (!empty($errors)) {
            WP_CLI::warning("Chyby:");
            foreach ($errors as $error) {
                WP_CLI::log("  - {$error['location']}: {$error['error']}");
            }
            WP_CLI::log("");
        }
        
        // Ukázka dat jednoho POI
        if (!empty($all_pois)) {
            $sample_poi = reset($all_pois);
            WP_CLI::log("📝 Ukázka dat POI:");
            WP_CLI::log("   Název: " . $sample_poi['name']);
            WP_CLI::log("   GPS: " . $sample_poi['lat'] . ", " . $sample_poi['lon']);
            WP_CLI::log("   Kategorie: " . ($sample_poi['category'] ?? 'N/A'));
            WP_CLI::log("   Rating: " . ($sample_poi['rating'] ?? 'N/A'));
            WP_CLI::log("   Rating source: " . ($sample_poi['rating_source'] ?? 'N/A'));
            WP_CLI::log("   Source: " . ($sample_poi['source'] ?? 'N/A'));
            WP_CLI::log("   Source ID: " . ($sample_poi['source_id'] ?? 'N/A'));
            WP_CLI::log("");
            
            WP_CLI::log("📋 Kompletní struktura POI:");
            WP_CLI::log(json_encode($sample_poi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        
        WP_CLI::success("Test dokončen!");
    }
}

// Registrace WP-CLI příkazu
if (class_exists('WP_CLI')) {
    WP_CLI::add_command('db test-wikidata', array('DB\\CLI\\Test_Wikidata_Command', '__invoke'));
}

