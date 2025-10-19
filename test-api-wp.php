<?php
/**
 * WordPress API Test
 * Spustit: php test-api-wp.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== WordPress API Test ===\n\n";

// Kontrola, zda je API klíč nastaven
$mapyApiKey = get_option('db_mapy_api_key');
echo "Mapy.com API Key: " . (empty($mapyApiKey) ? 'NOT SET' : 'SET (' . strlen($mapyApiKey) . ' chars)') . "\n";

$googleApiKey = get_option('db_google_api_key');
echo "Google API Key: " . (empty($googleApiKey) ? 'NOT SET' : 'SET (' . strlen($googleApiKey) . ' chars)') . "\n\n";

// Test Mapy.com API přímo
echo "Testing Mapy.com API directly...\n";

$mapyDiscovery = new DB\Mapy_Discovery();

// Test 1: Vyhledání místa v ČR
echo "1. Searching for 'Praha'...\n";
$results = $mapyDiscovery->searchPlace('Praha', 50.0755, 14.4378, 3);
if (!empty($results)) {
    echo "   ✓ Found " . count($results) . " results\n";
    foreach ($results as $i => $result) {
        echo "   " . ($i + 1) . ". " . $result['name'] . " - " . ($result['address'] ?? $result['formatted_address'] ?? '') . "\n";
    }
} else {
    echo "   ✗ No results found\n";
}

// Test 2: Vyhledání mezinárodního místa
echo "\n2. Searching for 'Berlin'...\n";
$results = $mapyDiscovery->searchPlace('Berlin', 52.5200, 13.4050, 3);
if (!empty($results)) {
    echo "   ✓ Found " . count($results) . " results\n";
    foreach ($results as $i => $result) {
        echo "   " . ($i + 1) . ". " . $result['name'] . " - " . ($result['address'] ?? $result['formatted_address'] ?? '') . "\n";
    }
} else {
    echo "   ✗ No results found\n";
}

// Test 3: Geocoding
echo "\n3. Geocoding 'Václavské náměstí, Praha'...\n";
$geocode = $mapyDiscovery->geocodeAddress('Václavské náměstí, Praha');
if ($geocode) {
    echo "   ✓ Found: " . ($geocode['address'] ?? $geocode['formatted_address'] ?? '') . " (" . $geocode['lat'] . ", " . $geocode['lng'] . ")\n";
} else {
    echo "   ✗ No results found\n";
}

// Test 4: Načtení POI z databáze
echo "\n4. Loading POIs from database...\n";
$pois = get_posts([
    'post_type' => 'poi',
    'posts_per_page' => 5,
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
    echo "   ✗ No POIs found\n";
} else {
    echo "   ✓ Found " . count($pois) . " POIs\n";
    
    foreach ($pois as $poi) {
        $lat = (float) get_post_meta($poi->ID, '_poi_lat', true);
        $lng = (float) get_post_meta($poi->ID, '_poi_lng', true);
        $title = $poi->post_title;
        
        echo "   Testing: {$title} ({$lat}, {$lng})\n";
        
        $results = $mapyDiscovery->searchPlace($title, $lat, $lng, 2);
        if (!empty($results)) {
            echo "     ✓ Found " . count($results) . " results\n";
        } else {
            echo "     ✗ No results found\n";
        }
    }
}

// Test 5: Simple API Selector
echo "\n5. Testing Simple API Selector...\n";
$apiSelector = new DB\Simple_API_Selector();

if (!empty($pois)) {
    $poi = $pois[0];
    echo "   Testing selector for: {$poi->post_title}\n";
    
    $result = $apiSelector->enrichPOIData($poi->ID, 'search');
    if ($result) {
        echo "   ✓ Enriched using {$result['service']} API\n";
        echo "   Region: " . ($result['region'] ?? 'unknown') . "\n";
        echo "   Cache TTL: " . round($result['cache_ttl'] / DAY_IN_SECONDS, 1) . " days\n";
    } else {
        echo "   ✗ Failed to enrich data\n";
    }
}

echo "\n=== Test completed ===\n";
