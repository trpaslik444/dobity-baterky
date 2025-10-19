<?php
/**
 * Test script pro Simple API Selector
 * Spustit: php test-simple-api-selector.php
 */

require_once __DIR__ . '/dobity-baterky.php';

use DB\Simple_API_Selector;

echo "=== Simple API Selector Test Script ===\n\n";

// Test 1: Základní test dostupnosti služeb
echo "1. Testing service availability...\n";

$apiSelector = new Simple_API_Selector();

$services = ['mapy', 'google', 'tripadvisor'];
foreach ($services as $service) {
    $result = $apiSelector->testServiceAvailability($service);
    echo "   {$service}: " . ($result['available'] ? '✓ Available' : '✗ Not available');
    if ($result['available']) {
        echo " ({$result['response_time']}ms)";
    } else {
        echo " - {$result['error']}";
    }
    echo "\n";
}

echo "\n";

// Test 2: Statistiky služeb
echo "2. Service statistics...\n";

$stats = $apiSelector->getServiceStats();
foreach ($stats as $service => $data) {
    echo "   {$service}:\n";
    echo "     API Key: " . ($data['api_key_configured'] ? 'Configured' : 'Not configured') . "\n";
    echo "     Cached Items: {$data['cached_items']}\n";
    echo "     Cache TTL: {$data['cache_ttl']}\n";
}

echo "\n";

// Test 3: Test obohacování dat (pokud existuje test POI)
echo "3. Testing data enrichment...\n";

// Najít první POI pro test
$pois = get_posts([
    'post_type' => 'poi',
    'posts_per_page' => 1,
    'post_status' => 'publish'
]);

if (!empty($pois)) {
    $testPOI = $pois[0];
    echo "   Testing with POI: {$testPOI->post_title} (ID: {$testPOI->ID})\n";
    
    $result = $apiSelector->enrichPOIData($testPOI->ID, 'search');
    if ($result) {
        echo "   ✓ Data enriched successfully using {$result['service']} API\n";
        echo "   Cache TTL: {$result['cache_ttl']} seconds\n";
    } else {
        echo "   ✗ Failed to enrich data\n";
    }
} else {
    echo "   No POI found for testing\n";
}

echo "\n";

// Test 4: Cache management
echo "4. Testing cache management...\n";

foreach ($services as $service) {
    $cleared = $apiSelector->clearServiceCache($service);
    echo "   {$service}: Cleared {$cleared} cache items\n";
}

echo "\n";

// Test 5: Doporučení
echo "5. Recommendations...\n";
echo "   - Configure API keys in WordPress Admin > Tools > Správa ikon > API nastavení\n";
echo "   - Use the API Test page: WordPress Admin > Tools > API Test\n";
echo "   - Monitor cache usage and API limits\n";

echo "\n=== Test completed ===\n";
