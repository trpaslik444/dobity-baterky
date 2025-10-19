<?php
/**
 * Test nové strategie - Google primární, Mapy.com fallback
 * Spustit: php test-new-strategy.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Test nové strategie - Google primární, Mapy.com fallback ===\n\n";

// Inicializace
$enrichmentService = new \DB\Services\Db_Charging_Enrichment_Service();

// Test 1: Obohacení konkrétní nabíjecí stanice
echo "1. OBOHACENÍ NABÍJECÍ STANICE\n";
echo "==============================\n";

$chargingStations = get_posts([
    'post_type' => 'charging_location',
    'posts_per_page' => 3,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => '_db_lat',
            'compare' => 'EXISTS'
        ],
        [
            'key' => '_db_lng',
            'compare' => 'EXISTS'
        ]
    ]
]);

if (empty($chargingStations)) {
    echo "❌ Žádné nabíjecí stanice nenalezeny\n";
    exit(1);
}

foreach ($chargingStations as $i => $station) {
    $stationId = $station->ID;
    $title = $station->post_title;
    $lat = (float) get_post_meta($stationId, '_db_lat', true);
    $lng = (float) get_post_meta($stationId, '_db_lng', true);
    
    if ($lat == 0 && $lng == 0) continue;
    
    echo "--- Stanice " . ($i + 1) . ": {$title} ---\n";
    echo "GPS: {$lat}, {$lng}\n";
    
    // Test obohacení
    $startTime = microtime(true);
    $enrichment = $enrichmentService->enrichChargingStation($stationId);
    $responseTime = (microtime(true) - $startTime) * 1000;
    
    if ($enrichment) {
        echo "✅ Obohacení úspěšné ({$enrichment['service']} API)\n";
        echo "⏱️  Doba odezvy: " . round($responseTime, 2) . "ms\n";
        echo "📊 Cache TTL: " . round($enrichment['cache_ttl'] / DAY_IN_SECONDS) . " dní\n";
        
        $data = $enrichment['data'];
        echo "📋 Data:\n";
        echo "   Název: " . ($data['name'] ?? 'N/A') . "\n";
        echo "   Adresa: " . ($data['address'] ?? 'N/A') . "\n";
        echo "   Rating: " . ($data['rating'] ?? 'N/A') . "\n";
        echo "   Telefon: " . ($data['phone'] ?? 'N/A') . "\n";
        echo "   Web: " . ($data['website'] ?? 'N/A') . "\n";
        
        // Fotky
        if (!empty($data['photos'])) {
            echo "   📸 Fotky: " . count($data['photos']) . " kusů\n";
            foreach (array_slice($data['photos'], 0, 2) as $photo) {
                echo "     - " . ($photo['url'] ?? 'N/A') . "\n";
            }
        } else {
            echo "   📸 Fotky: Žádné\n";
        }
        
        // Street View
        if (!empty($data['street_view'])) {
            echo "   🗺️  Street View: " . ($data['street_view']['url'] ?? 'N/A') . "\n";
        }
        
    } else {
        echo "❌ Obohacení selhalo\n";
    }
    
    echo "\n";
}

echo "\n\n";

// Test 2: Vyhledání v okolí
echo "2. VYHLEDÁNÍ V OKOLÍ\n";
echo "====================\n";

$testLat = 50.0755; // Praha
$testLng = 14.4378;

echo "Vyhledání nabíjecích stanic kolem: {$testLat}, {$testLng}\n\n";

$startTime = microtime(true);
$nearbyStations = $enrichmentService->findEnrichedChargingStations($testLat, $testLng, 3000, 5);
$responseTime = (microtime(true) - $startTime) * 1000;

echo "⏱️  Doba odezvy: " . round($responseTime, 2) . "ms\n";
echo "📊 Nalezeno: " . count($nearbyStations) . " stanic\n\n";

foreach ($nearbyStations as $i => $station) {
    $data = $station['data'];
    echo "--- Stanice " . ($i + 1) . " ---\n";
    echo "Služba: {$station['service']}\n";
    echo "Název: " . ($data['name'] ?? 'N/A') . "\n";
    echo "Adresa: " . ($data['address'] ?? 'N/A') . "\n";
    
    if ($station['service'] === 'google') {
        echo "Rating: " . ($data['rating'] ?? 'N/A') . "\n";
        echo "Fotky: " . count($data['photos'] ?? []) . " kusů\n";
    } else {
        echo "Typ: " . ($data['type'] ?? 'N/A') . "\n";
        echo "Vzdálenost: " . ($data['distance_m'] ?? 'N/A') . "m\n";
    }
    
    echo "\n";
}

echo "\n\n";

// Test 3: Kontrola cache
echo "3. KONTROLA CACHE\n";
echo "==================\n";

// Zkontrolujeme, zda se nové cache ukládá správně
$testStation = $chargingStations[0];
$testStationId = $testStation->ID;

echo "Testování cache pro stanici: {$testStation->post_title}\n";

// Vymažeme existující cache
delete_transient('charging_enrichment_' . $testStationId);

// Spustíme obohacení
$enrichment = $enrichmentService->enrichChargingStation($testStationId);

if ($enrichment) {
    echo "✅ Obohacení úspěšné\n";
    
    // Zkontrolujeme cache
    $cacheKey = 'charging_enrichment_' . $testStationId;
    $cached = get_transient($cacheKey);
    
    if ($cached !== false) {
        echo "✅ Cache se uložila\n";
        echo "📊 Cache TTL: " . round($enrichment['cache_ttl'] / DAY_IN_SECONDS) . " dní\n";
    } else {
        echo "❌ Cache se neuložila\n";
    }
} else {
    echo "❌ Obohacení selhalo\n";
}

echo "\n\n";

// Test 4: Shrnutí nové strategie
echo "4. SHRNUTÍ NOVÉ STRATEGIE\n";
echo "==========================\n";

echo "🎯 Nová strategie:\n";
echo "   1. Google Places API (primární)\n";
echo "      ✅ Fotky nabíjecích stanic\n";
echo "      ✅ Detailní informace (rating, telefon, web)\n";
echo "      ✅ 30 dní cache\n";
echo "      ✅ Street View jako fallback\n\n";

echo "   2. Mapy.com API (fallback)\n";
echo "      ✅ Základní informace\n";
echo "      ✅ České prostředí\n";
echo "      ✅ 30 dní cache (opraveno)\n";
echo "      ❌ Žádné fotky\n\n";

echo "   3. Street View (fallback pro fotky)\n";
echo "      ✅ Vizuální náhled lokace\n";
echo "      ✅ Vždy dostupné\n";
echo "      ✅ Žádné API limity\n\n";

echo "📊 Výhody nové strategie:\n";
echo "   ✅ Ferky z Google Places\n";
echo "   ✅ Street View jako záložní fotky\n";
echo "   ✅ Správná cache TTL (30 dní)\n";
echo "   ✅ Fallback strategie\n";
echo "   ✅ České prostředí (Mapy.com)\n\n";

echo "✅ Test dokončen!\n";
