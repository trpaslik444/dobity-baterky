<?php
/**
 * Test API na několika bodech z databáze
 * Spustit: php test-api-with-database.php
 */

require_once __DIR__ . '/dobity-baterky.php';

use DB\Simple_API_Selector;
use DB\Mapy_Discovery;

echo "=== API Test s databázovými body ===\n\n";

// Inicializace
$apiSelector = new Simple_API_Selector();
$mapyDiscovery = new Mapy_Discovery();

// Test 1: Načtení bodů z databáze
echo "1. Loading POIs from database...\n";

$pois = get_posts([
    'post_type' => 'poi',
    'posts_per_page' => 10,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => '_poi_lat',
            'compare' => 'EXISTS'
        ],
        [
            'key' => '_poi_lng',
            'compare' => 'EXISTS'
        ]
    ]
]);

if (empty($pois)) {
    echo "   ✗ No POIs found with coordinates\n";
    exit(1);
}

echo "   ✓ Found " . count($pois) . " POIs with coordinates\n\n";

// Test 2: Test Mapy.com API na vybraných bodech
echo "2. Testing Mapy.com API on database POIs...\n";

$mapyResults = [];
$czechRepublicCount = 0;
$internationalCount = 0;

foreach (array_slice($pois, 0, 5) as $poi) {
    $lat = (float) get_post_meta($poi->ID, '_poi_lat', true);
    $lng = (float) get_post_meta($poi->ID, '_poi_lng', true);
    $title = $poi->post_title;
    
    // Kontrola, zda je v ČR
    $isCzechRepublic = ($lat >= 48.5 && $lat <= 51.1 && $lng >= 12.0 && $lng <= 18.9);
    if ($isCzechRepublic) {
        $czechRepublicCount++;
    } else {
        $internationalCount++;
    }
    
    echo "   Testing: {$title} ({$lat}, {$lng}) - " . ($isCzechRepublic ? 'ČR' : 'International') . "\n";
    
    try {
        $startTime = microtime(true);
        $results = $mapyDiscovery->searchPlace($title, $lat, $lng, 3);
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        if (!empty($results)) {
            $mapyResults[] = [
                'poi_id' => $poi->ID,
                'title' => $title,
                'lat' => $lat,
                'lng' => $lng,
                'is_czech' => $isCzechRepublic,
                'results_count' => count($results),
                'response_time' => round($responseTime, 2),
                'first_result' => $results[0] ?? null
            ];
            echo "     ✓ Found " . count($results) . " results ({$responseTime}ms)\n";
        } else {
            echo "     ✗ No results found ({$responseTime}ms)\n";
        }
    } catch (Exception $e) {
        echo "     ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Test 3: Test Simple API Selector
echo "3. Testing Simple API Selector workflow...\n";

$selectorResults = [];
foreach (array_slice($pois, 0, 3) as $poi) {
    $lat = (float) get_post_meta($poi->ID, '_poi_lat', true);
    $lng = (float) get_post_meta($poi->ID, '_poi_lng', true);
    $title = $poi->post_title;
    
    echo "   Testing selector for: {$title}\n";
    
    try {
        $startTime = microtime(true);
        $result = $apiSelector->enrichPOIData($poi->ID, 'search');
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        if ($result) {
            $selectorResults[] = [
                'poi_id' => $poi->ID,
                'title' => $title,
                'service' => $result['service'],
                'region' => $result['region'] ?? 'unknown',
                'response_time' => round($responseTime, 2),
                'cache_ttl' => $result['cache_ttl']
            ];
            echo "     ✓ Enriched using {$result['service']} API ({$responseTime}ms)\n";
            echo "       Region: " . ($result['region'] ?? 'unknown') . "\n";
            echo "       Cache TTL: " . round($result['cache_ttl'] / DAY_IN_SECONDS, 1) . " days\n";
        } else {
            echo "     ✗ Failed to enrich data ({$responseTime}ms)\n";
        }
    } catch (Exception $e) {
        echo "     ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Test 4: Test geocoding
echo "4. Testing geocoding with sample addresses...\n";

$testAddresses = [
    'Václavské náměstí 1, Praha 1, Czech Republic',
    'Times Square, New York, USA',
    'Eiffel Tower, Paris, France'
];

foreach ($testAddresses as $address) {
    echo "   Geocoding: {$address}\n";
    
    try {
        $startTime = microtime(true);
        $result = $mapyDiscovery->geocodeAddress($address);
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        if ($result) {
            echo "     ✓ Found: {$result['formatted_address']} ({$result['lat']}, {$result['lng']}) ({$responseTime}ms)\n";
        } else {
            echo "     ✗ No results found ({$responseTime}ms)\n";
        }
    } catch (Exception $e) {
        echo "     ✗ Error: " . $e->getMessage() . "\n";
    }
}

echo "\n";

// Test 5: Statistiky
echo "5. Test statistics...\n";

$stats = $apiSelector->getServiceStats();
foreach ($stats as $service => $data) {
    echo "   {$service}:\n";
    echo "     API Key: " . ($data['api_key_configured'] ? 'Configured' : 'Not configured') . "\n";
    echo "     Cached Items: {$data['cached_items']}\n";
    echo "     Cache TTL: {$data['cache_ttl']}\n";
}

echo "\n";

// Shrnutí
echo "6. Test summary...\n";
echo "   Total POIs tested: " . count($pois) . "\n";
echo "   Czech Republic POIs: {$czechRepublicCount}\n";
echo "   International POIs: {$internationalCount}\n";
echo "   Mapy.com successful: " . count($mapyResults) . "\n";
echo "   API Selector successful: " . count($selectorResults) . "\n";

if (!empty($mapyResults)) {
    $avgResponseTime = array_sum(array_column($mapyResults, 'response_time')) / count($mapyResults);
    echo "   Average Mapy.com response time: " . round($avgResponseTime, 2) . "ms\n";
}

if (!empty($selectorResults)) {
    $services = array_count_values(array_column($selectorResults, 'service'));
    echo "   Services used:\n";
    foreach ($services as $service => $count) {
        echo "     {$service}: {$count} times\n";
    }
}

echo "\n=== Test completed ===\n";
