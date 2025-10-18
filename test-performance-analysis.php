<?php
/**
 * Performance Analysis Test Script
 * Analyzuje výkon nearby API volání a isochronů
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}
require_once ABSPATH . 'wp-load.php';

// Ensure WP-CLI functions are available if not running via WP-CLI
if (!function_exists('WP_CLI_log')) {
    function WP_CLI_log($message) {
        echo $message . "\n";
    }
    function WP_CLI_success($message) {
        echo "SUCCESS: " . $message . "\n";
    }
    function WP_CLI_error($message) {
        echo "ERROR: " . $message . "\n";
    }
}

class PerformanceAnalyzer {
    
    private $results = [];
    private $start_time;
    
    public function __construct() {
        $this->start_time = microtime(true);
    }
    
    public function run_analysis() {
        WP_CLI_log("🔍 Spouštím analýzu výkonu...");
        
        $this->analyze_nearby_api_calls();
        $this->analyze_isochrones_calls();
        $this->analyze_cache_performance();
        $this->analyze_database_queries();
        $this->analyze_memory_usage();
        
        $this->generate_report();
    }
    
    private function analyze_nearby_api_calls() {
        WP_CLI_log("📊 Analyzuji nearby API volání...");
        
        global $wpdb;
        
        // Počet nearby volání za posledních 24 hodin
        $recent_calls = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_%' 
            AND option_value LIKE '%\"timestamp\":%'
            AND option_value > '" . date('Y-m-d H:i:s', strtotime('-24 hours')) . "'
        ");
        
        // Počet unikátních origin_id v cache
        $unique_origins = $wpdb->get_var("
            SELECT COUNT(DISTINCT SUBSTRING(option_name, 20, 10))
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_response_%'
        ");
        
        // Průměrná velikost nearby response
        $avg_response_size = $wpdb->get_var("
            SELECT AVG(LENGTH(option_value))
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_response_%'
        ");
        
        $this->results['nearby_api'] = [
            'recent_calls_24h' => intval($recent_calls),
            'unique_origins_cached' => intval($unique_origins),
            'avg_response_size_bytes' => round($avg_response_size, 2),
            'cache_hit_rate' => $this->calculate_cache_hit_rate()
        ];
        
        WP_CLI_success("Nearby API: {$recent_calls} volání za 24h, {$unique_origins} unikátních originů v cache");
    }
    
    private function analyze_isochrones_calls() {
        WP_CLI_log("🗺️ Analyzuji isochrony...");
        
        global $wpdb;
        
        // Počet isochronů v cache
        $isochrones_cached = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_isochrones_%'
        ");
        
        // Průměrná velikost isochronů
        $avg_isochrones_size = $wpdb->get_var("
            SELECT AVG(LENGTH(option_value))
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_isochrones_%'
        ");
        
        // Počet isochronů za posledních 24 hodin
        $recent_isochrones = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_isochrones_%'
            AND option_value LIKE '%\"timestamp\":%'
            AND option_value > '" . date('Y-m-d H:i:s', strtotime('-24 hours')) . "'
        ");
        
        $this->results['isochrones'] = [
            'cached_count' => intval($isochrones_cached),
            'avg_size_bytes' => round($avg_isochrones_size, 2),
            'recent_24h' => intval($recent_isochrones)
        ];
        
        WP_CLI_success("Isochrony: {$isochrones_cached} v cache, {$recent_isochrones} za 24h");
    }
    
    private function analyze_cache_performance() {
        WP_CLI_log("💾 Analyzuji cache výkon...");
        
        global $wpdb;
        
        // Celkový počet cache záznamů
        $total_cache = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_%'
        ");
        
        // Cache podle typu
        $cache_by_type = [
            'nearby' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_db_nearby_%'"),
            'isochrones' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_db_isochrones_%'"),
            'candidates' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_db_candidates_%'"),
            'ondemand' => $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->options} WHERE option_name LIKE '_transient_db_ondemand_%'")
        ];
        
        // Celková velikost cache
        $total_cache_size = $wpdb->get_var("
            SELECT SUM(LENGTH(option_value))
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_%'
        ");
        
        $this->results['cache'] = [
            'total_records' => intval($total_cache),
            'by_type' => $cache_by_type,
            'total_size_bytes' => intval($total_cache_size),
            'total_size_mb' => round($total_cache_size / 1024 / 1024, 2)
        ];
        
        WP_CLI_success("Cache: {$total_cache} záznamů, " . round($total_cache_size / 1024 / 1024, 2) . " MB");
    }
    
    private function analyze_database_queries() {
        WP_CLI_log("🗄️ Analyzuji databázové dotazy...");
        
        global $wpdb;
        
        // Počet charging locations
        $charging_locations = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'charging_location' 
            AND post_status = 'publish'
        ");
        
        // Počet POI
        $pois = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'poi' 
            AND post_status = 'publish'
        ");
        
        // Počet RV spots
        $rv_spots = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->posts} 
            WHERE post_type = 'rv_spot' 
            AND post_status = 'publish'
        ");
        
        // Počet bodů s koordináty
        $with_coordinates = $wpdb->get_var("
            SELECT COUNT(DISTINCT p.ID)
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')
            INNER JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key IN ('_db_lng', '_poi_lng', '_rv_lng')
            WHERE p.post_type IN ('charging_location', 'poi', 'rv_spot')
            AND p.post_status = 'publish'
            AND lat.meta_value != '' AND lng.meta_value != ''
        ");
        
        // Test rychlosti nearby dotazu
        $start_time = microtime(true);
        $test_query = $wpdb->get_results("
            SELECT p.ID, lat.meta_value+0 AS lat, lng.meta_value+0 AS lng
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key = '_db_lat'
            INNER JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key = '_db_lng'
            WHERE p.post_type = 'charging_location'
            AND p.post_status = 'publish'
            AND lat.meta_value != '' AND lng.meta_value != ''
            LIMIT 10
        ");
        $query_time = microtime(true) - $start_time;
        
        $this->results['database'] = [
            'charging_locations' => intval($charging_locations),
            'pois' => intval($pois),
            'rv_spots' => intval($rv_spots),
            'with_coordinates' => intval($with_coordinates),
            'test_query_time_ms' => round($query_time * 1000, 2)
        ];
        
        WP_CLI_success("DB: {$charging_locations} nabíječek, {$pois} POI, {$rv_spots} RV spotů");
    }
    
    private function analyze_memory_usage() {
        WP_CLI_log("🧠 Analyzuji spotřebu paměti...");
        
        $memory_usage = memory_get_usage(true);
        $memory_peak = memory_get_peak_usage(true);
        $memory_limit = ini_get('memory_limit');
        
        $this->results['memory'] = [
            'current_mb' => round($memory_usage / 1024 / 1024, 2),
            'peak_mb' => round($memory_peak / 1024 / 1024, 2),
            'limit' => $memory_limit
        ];
        
        WP_CLI_success("Paměť: " . round($memory_usage / 1024 / 1024, 2) . " MB aktuálně, " . round($memory_peak / 1024 / 1024, 2) . " MB peak");
    }
    
    private function calculate_cache_hit_rate() {
        global $wpdb;
        
        // Počet cache hitů (úspěšných načtení z cache)
        $cache_hits = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_response_%'
            AND option_value LIKE '%\"cached\":true%'
        ");
        
        // Celkový počet nearby volání
        $total_calls = $wpdb->get_var("
            SELECT COUNT(*) 
            FROM {$wpdb->options} 
            WHERE option_name LIKE '_transient_db_nearby_response_%'
        ");
        
        if ($total_calls > 0) {
            return round(($cache_hits / $total_calls) * 100, 2);
        }
        
        return 0;
    }
    
    private function generate_report() {
        $total_time = round(microtime(true) - $this->start_time, 2);
        
        WP_CLI_log("\n" . str_repeat("=", 60));
        WP_CLI_log("📋 VÝSLEDKY ANALÝZY VÝKONU");
        WP_CLI_log(str_repeat("=", 60));
        
        // Nearby API
        WP_CLI_log("\n🔗 NEARBY API VOLÁNÍ:");
        WP_CLI_log("  • Volání za 24h: " . $this->results['nearby_api']['recent_calls_24h']);
        WP_CLI_log("  • Unikátní originy v cache: " . $this->results['nearby_api']['unique_origins_cached']);
        WP_CLI_log("  • Průměrná velikost response: " . $this->results['nearby_api']['avg_response_size_bytes'] . " B");
        WP_CLI_log("  • Cache hit rate: " . $this->results['nearby_api']['cache_hit_rate'] . "%");
        
        // Isochrony
        WP_CLI_log("\n🗺️ ISOCHRONY:");
        WP_CLI_log("  • V cache: " . $this->results['isochrones']['cached_count']);
        WP_CLI_log("  • Za 24h: " . $this->results['isochrones']['recent_24h']);
        WP_CLI_log("  • Průměrná velikost: " . $this->results['isochrones']['avg_size_bytes'] . " B");
        
        // Cache
        WP_CLI_log("\n💾 CACHE:");
        WP_CLI_log("  • Celkem záznamů: " . $this->results['cache']['total_records']);
        WP_CLI_log("  • Celková velikost: " . $this->results['cache']['total_size_mb'] . " MB");
        WP_CLI_log("  • Nearby: " . $this->results['cache']['by_type']['nearby']);
        WP_CLI_log("  • Isochrony: " . $this->results['cache']['by_type']['isochrones']);
        WP_CLI_log("  • Candidates: " . $this->results['cache']['by_type']['candidates']);
        WP_CLI_log("  • On-demand: " . $this->results['cache']['by_type']['ondemand']);
        
        // Database
        WP_CLI_log("\n🗄️ DATABÁZE:");
        WP_CLI_log("  • Nabíječky: " . $this->results['database']['charging_locations']);
        WP_CLI_log("  • POI: " . $this->results['database']['pois']);
        WP_CLI_log("  • RV spoty: " . $this->results['database']['rv_spots']);
        WP_CLI_log("  • S koordináty: " . $this->results['database']['with_coordinates']);
        WP_CLI_log("  • Test dotaz: " . $this->results['database']['test_query_time_ms'] . " ms");
        
        // Memory
        WP_CLI_log("\n🧠 PAMĚŤ:");
        WP_CLI_log("  • Aktuální: " . $this->results['memory']['current_mb'] . " MB");
        WP_CLI_log("  • Peak: " . $this->results['memory']['peak_mb'] . " MB");
        WP_CLI_log("  • Limit: " . $this->results['memory']['limit']);
        
        WP_CLI_log("\n⏱️ Celková doba analýzy: {$total_time}s");
        WP_CLI_log(str_repeat("=", 60));
        
        // Doporučení
        $this->generate_recommendations();
    }
    
    private function generate_recommendations() {
        WP_CLI_log("\n💡 DOPORUČENÍ:");
        
        $nearby_calls = $this->results['nearby_api']['recent_calls_24h'];
        $cache_hit_rate = $this->results['nearby_api']['cache_hit_rate'];
        $cache_size_mb = $this->results['cache']['total_size_mb'];
        
        if ($nearby_calls > 1000) {
            WP_CLI_log("  ⚠️  Vysoký počet nearby volání ({$nearby_calls}/24h) - zvažte agresivnější cache");
        }
        
        if ($cache_hit_rate < 50) {
            WP_CLI_log("  ⚠️  Nízká cache hit rate ({$cache_hit_rate}%) - zkontrolujte cache strategii");
        }
        
        if ($cache_size_mb > 100) {
            WP_CLI_log("  ⚠️  Velká cache ({$cache_size_mb} MB) - zvažte čištění starých záznamů");
        }
        
        if ($this->results['database']['test_query_time_ms'] > 100) {
            WP_CLI_log("  ⚠️  Pomalý databázový dotaz ({$this->results['database']['test_query_time_ms']}ms) - vytvořte indexy");
        }
        
        WP_CLI_log("  ✅ Implementujte optimalizované admin rozhraní");
        WP_CLI_log("  ✅ Používejte on-demand zpracování místo workerů");
        WP_CLI_log("  ✅ Optimalizujte cache strategii");
    }
}

// Spustit analýzu
$analyzer = new PerformanceAnalyzer();
$analyzer->run_analysis();
