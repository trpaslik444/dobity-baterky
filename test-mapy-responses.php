<?php
/**
 * Test Mapy.com API responses - ukázka kompletních odpovědí
 * Spustit: php test-mapy-responses.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Mapy.com API Response Examples ===\n\n";

// Inicializace
$mapyDiscovery = new DB\Mapy_Discovery();

// Test 1: POI vyhledávání
echo "1. POI SEARCH - 'Praha hlavní nádraží'\n";
echo "=====================================\n";

$poiResults = $mapyDiscovery->searchPlace('Praha hlavní nádraží', 50.0830, 14.4354, 3);
if (!empty($poiResults)) {
    echo "✓ Found " . count($poiResults) . " POI results:\n\n";
    foreach ($poiResults as $i => $result) {
        echo "Result " . ($i + 1) . ":\n";
        echo json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\n" . str_repeat("-", 50) . "\n";
    }
} else {
    echo "✗ No POI results found\n";
}

echo "\n\n";

// Test 2: Nabíjecí stanice
echo "2. CHARGING STATIONS - 'charging station' near Prague\n";
echo "====================================================\n";

$chargingResults = $mapyDiscovery->findChargingStationsNear(50.0755, 14.4378, 5000, 5);
if (!empty($chargingResults)) {
    echo "✓ Found " . count($chargingResults) . " charging stations:\n\n";
    foreach ($chargingResults as $i => $station) {
        echo "Charging Station " . ($i + 1) . ":\n";
        echo json_encode($station, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        echo "\n" . str_repeat("-", 50) . "\n";
    }
} else {
    echo "✗ No charging stations found\n";
}

echo "\n\n";

// Test 3: Geocoding
echo "3. GEOCODING - 'Václavské náměstí, Praha'\n";
echo "=========================================\n";

$geocodeResult = $mapyDiscovery->geocodeAddress('Václavské náměstí, Praha');
if ($geocodeResult) {
    echo "✓ Geocoding result:\n";
    echo json_encode($geocodeResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "✗ No geocoding result found\n";
}

echo "\n\n";

// Test 4: Reverzní geocoding
echo "4. REVERSE GEOCODING - Souřadnice Václavského náměstí\n";
echo "====================================================\n";

$reverseResult = $mapyDiscovery->reverseGeocode(50.0818, 14.4271);
if ($reverseResult) {
    echo "✓ Reverse geocoding result:\n";
    echo json_encode($reverseResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
} else {
    echo "✗ No reverse geocoding result found\n";
}

echo "\n\n";

// Test 5: Raw API response pro POI
echo "5. RAW API RESPONSE - Direct API call for POI\n";
echo "=============================================\n";

$apiKey = get_option('db_mapy_api_key');
if (!empty($apiKey)) {
    $params = [
        'query' => 'Starbucks Praha',
        'type' => 'poi',
        'lang' => 'cs',
        'limit' => 2,
        'preferNear' => '14.4378,50.0755', // lon,lat
        'preferNearPrecision' => 2000,
        'apikey' => $apiKey
    ];
    
    $url = 'https://api.mapy.com/v1/geocode?' . http_build_query($params);
    
    echo "API URL: " . $url . "\n\n";
    
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'DobityBaterky/Mapy-Discovery'
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        echo "HTTP Status: " . $code . "\n";
        echo "Raw Response:\n";
        echo json_encode(json_decode($body, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "✗ API Error: " . $response->get_error_message() . "\n";
    }
} else {
    echo "✗ API Key not configured\n";
}

echo "\n\n";

// Test 6: Raw API response pro nabíjecí stanice
echo "6. RAW API RESPONSE - Direct API call for Charging Stations\n";
echo "===========================================================\n";

if (!empty($apiKey)) {
    $params = [
        'query' => 'charging station',
        'type' => 'poi',
        'lang' => 'cs',
        'limit' => 3,
        'preferNear' => '14.4378,50.0755', // lon,lat
        'preferNearPrecision' => 3000,
        'apikey' => $apiKey
    ];
    
    $url = 'https://api.mapy.com/v1/geocode?' . http_build_query($params);
    
    echo "API URL: " . $url . "\n\n";
    
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'DobityBaterky/Mapy-Discovery'
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        echo "HTTP Status: " . $code . "\n";
        echo "Raw Response:\n";
        echo json_encode(json_decode($body, true), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "✗ API Error: " . $response->get_error_message() . "\n";
    }
} else {
    echo "✗ API Key not configured\n";
}

echo "\n\n";

// Test 7: Matrix routing (pokud máme nabíjecí stanice)
echo "7. MATRIX ROUTING - Vzdálenosti k nabíjecím stanicím\n";
echo "====================================================\n";

if (!empty($chargingResults) && count($chargingResults) >= 2) {
    $originLat = 50.0755;
    $originLng = 14.4378;
    
    $targets = array_slice($chargingResults, 0, 3);
    $targetCoords = [];
    
    foreach ($targets as $target) {
        $targetCoords[] = [$target['lat'], $target['lng']];
    }
    
    echo "Origin: {$originLat}, {$originLng}\n";
    echo "Targets: " . count($targetCoords) . " charging stations\n\n";
    
    $matrixResult = $mapyDiscovery->getMatrixDistance($originLat, $originLng, $targetCoords);
    
    if ($matrixResult) {
        echo "✓ Matrix routing result:\n";
        echo json_encode($matrixResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "✗ No matrix routing result found\n";
    }
} else {
    echo "✗ Not enough charging stations for matrix routing test\n";
}

echo "\n=== Test completed ===\n";
