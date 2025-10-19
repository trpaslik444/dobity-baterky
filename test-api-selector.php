<?php
/**
 * Test script pro API Selector
 * Spustit: php test-api-selector.php
 */

require_once __DIR__ . '/dobity-baterky.php';

use DB\API_Service_Selector;
use DB\Mapy_Discovery;
use DB\POI_Discovery;
use DB\API_Reliability_Tester;

echo "=== API Selector Test Script ===\n\n";

// Test 1: Základní test dostupnosti služeb
echo "1. Testing service availability...\n";

$serviceSelector = new API_Service_Selector();
$reliabilityTester = new API_Reliability_Tester();

$quickTest = $reliabilityTester->runQuickAvailabilityTest();

foreach ($quickTest as $service => $result) {
    if ($service === 'timestamp') continue;
    
    echo "   {$service}: " . ($result['available'] ? '✓ Available' : '✗ Not available');
    if ($result['available']) {
        echo " ({$result['response_time']}ms)";
    } else {
        echo " - {$result['error']}";
    }
    echo "\n";
}

echo "\n";

// Test 2: Test výběru služby
echo "2. Testing service selection...\n";

$testCases = [
    ['operation' => 'search', 'context' => ['lat' => 50.0755, 'lng' => 14.4378]], // Praha
    ['operation' => 'search', 'context' => ['lat' => 48.8584, 'lng' => 2.2945]], // Paříž
    ['operation' => 'geocoding', 'context' => ['address' => 'Václavské náměstí, Praha']],
    ['operation' => 'geocoding', 'context' => ['address' => 'Times Square, New York']],
];

foreach ($testCases as $testCase) {
    $selectedService = $serviceSelector->selectService($testCase['operation'], $testCase['context']);
    echo "   {$testCase['operation']} in " . 
         (isset($testCase['context']['lat']) ? "({$testCase['context']['lat']}, {$testCase['context']['lng']})" : 
          $testCase['context']['address']) . 
         ": {$selectedService}\n";
}

echo "\n";

// Test 3: Test Mapy.com API (pokud je nakonfigurován)
echo "3. Testing Mapy.com API...\n";

$mapyApiKey = get_option('db_mapy_api_key');
if ($mapyApiKey) {
    $mapyDiscovery = new Mapy_Discovery();
    
    // Test vyhledávání
    $searchResults = $mapyDiscovery->searchPlace('Pražský hrad', 50.0900, 14.4000, 3);
    echo "   Search results: " . (empty($searchResults) ? 'No results' : count($searchResults) . ' results') . "\n";
    
    // Test geokódování
    $geocodeResult = $mapyDiscovery->geocodeAddress('Václavské náměstí 1, Praha 1');
    echo "   Geocoding: " . (empty($geocodeResult) ? 'Failed' : 'Success') . "\n";
    
    // Test reverzního geokódování
    $reverseResult = $mapyDiscovery->reverseGeocode(50.0755, 14.4378);
    echo "   Reverse geocoding: " . (empty($reverseResult) ? 'Failed' : 'Success') . "\n";
} else {
    echo "   Mapy.com API key not configured\n";
}

echo "\n";

// Test 4: Test Google API (pokud je nakonfigurován)
echo "4. Testing Google API...\n";

$googleApiKey = get_option('db_google_api_key');
if ($googleApiKey) {
    $googleDiscovery = new POI_Discovery();
    
    // Test vyhledávání
    $placeId = $googleDiscovery->discoverGooglePlaceId('Pražský hrad', 50.0900, 14.4000);
    echo "   Search results: " . (empty($placeId) ? 'No results' : 'Found place ID: ' . $placeId) . "\n";
} else {
    echo "   Google API key not configured\n";
}

echo "\n";

// Test 5: Statistiky služeb
echo "5. Service statistics...\n";

$stats = $serviceSelector->getServiceStats();
if (!empty($stats)) {
    foreach ($stats as $service => $operations) {
        echo "   {$service}:\n";
        foreach ($operations as $operation => $data) {
            echo "     {$operation}: {$data['total_requests']} requests, " . 
                 round($data['success_rate'] * 100, 1) . "% success, " .
                 round($data['average_response_time'], 0) . "ms avg\n";
        }
    }
} else {
    echo "   No statistics available\n";
}

echo "\n";

// Test 6: Doporučení pro spuštění kompletních testů
echo "6. Recommendations...\n";
echo "   To run comprehensive tests, use the admin interface at:\n";
echo "   WordPress Admin > Tools > API Selector\n";
echo "   Or run: wp-cli eval 'new DB\\API_Reliability_Tester(); \$tester->runComprehensiveTests();'\n";

echo "\n=== Test completed ===\n";
