<?php
/**
 * Testovací skript pro POI synchronizaci
 * 
 * Použití: wp eval-file scripts/test-poi-sync.php
 */

if (!defined('ABSPATH')) {
    require_once dirname(__FILE__) . '/../../wp-load.php';
}

if (!class_exists('DB\\Services\\POI_Microservice_Client')) {
    require_once dirname(__DIR__) . '/includes/Services/POI_Microservice_Client.php';
}

echo "=== POI Synchronizace Test ===\n\n";

// Test 1: Základní připojení
echo "Test 1: Základní připojení k POI microservice\n";
$client = \DB\Services\POI_Microservice_Client::get_instance();
$result = $client->get_nearby_pois(50.0755, 14.4378, 2000, 1, false);

if (is_wp_error($result)) {
    echo "❌ CHYBA: " . $result->get_error_message() . "\n";
    exit(1);
} else {
    $count = isset($result['pois']) ? count($result['pois']) : 0;
    echo "✅ Úspěšně připojeno! Nalezeno {$count} POIs\n";
    echo "   Providers: " . implode(', ', $result['providers_used'] ?? []) . "\n\n";
}

// Test 2: Synchronizace do WordPressu
echo "Test 2: Synchronizace POIs do WordPressu\n";
$sync_result = $client->sync_nearby_pois_to_wordpress(50.0755, 14.4378, 2000, false);

if ($sync_result['success']) {
    echo "✅ Synchronizace úspěšná!\n";
    echo "   Synchronizováno: {$sync_result['synced']}\n";
    echo "   Selhalo: {$sync_result['failed']}\n";
    echo "   Celkem: {$sync_result['total']}\n";
    echo "   Providers: " . implode(', ', $sync_result['providers_used'] ?? []) . "\n\n";
} else {
    echo "❌ CHYBA: " . ($sync_result['error'] ?? 'Unknown error') . "\n\n";
}

// Test 3: Kontrola vytvořených POIs
echo "Test 3: Kontrola vytvořených POIs v WordPressu\n";
global $wpdb;
$poi_count = $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'poi'");
echo "   Celkem POIs v WordPressu: {$poi_count}\n";

$recent_pois = $wpdb->get_results(
    "SELECT p.ID, p.post_title, 
            pm_lat.meta_value as lat,
            pm_lng.meta_value as lon
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
     LEFT JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
     WHERE p.post_type = 'poi'
     ORDER BY p.post_date DESC
     LIMIT 5"
);

if ($recent_pois) {
    echo "   Posledních 5 POIs:\n";
    foreach ($recent_pois as $poi) {
        echo "   - {$poi->post_title} ({$poi->lat}, {$poi->lon})\n";
    }
} else {
    echo "   Žádné POIs nenalezeny\n";
}
echo "\n";

// Test 4: Statistiky
echo "Test 4: Statistiky synchronizace\n";
$stats = get_option('db_poi_sync_stats', array(
    'total_synced' => 0,
    'total_failed' => 0,
    'last_sync' => null,
));
echo "   Celkem synchronizováno: {$stats['total_synced']}\n";
echo "   Celkem selhalo: {$stats['total_failed']}\n";
echo "   Poslední synchronizace: " . ($stats['last_sync'] ?? 'Nikdy') . "\n\n";

// Test 5: Konfigurace
echo "Test 5: Konfigurace\n";
$url = get_option('db_poi_service_url', 'http://localhost:3333');
$timeout = get_option('db_poi_service_timeout', 30);
$max_retries = get_option('db_poi_service_max_retries', 3);
echo "   URL: {$url}\n";
echo "   Timeout: {$timeout}s\n";
echo "   Max retries: {$max_retries}\n";
if (defined('DB_POI_SERVICE_URL')) {
    echo "   ⚠️  URL je nastaveno pomocí konstanty DB_POI_SERVICE_URL\n";
}
echo "\n";

// Test 6: Cache
echo "Test 6: Cache synchronizace\n";
$cache_key = 'poi_sync_' . md5('50.0755_14.4378_2000');
$cached = get_transient($cache_key);
if ($cached !== false) {
    echo "   ✅ Cache aktivní (5 minut)\n";
} else {
    echo "   ℹ️  Cache není aktivní (první synchronizace nebo cache expirovala)\n";
}
echo "\n";

echo "=== Test dokončen ===\n";

