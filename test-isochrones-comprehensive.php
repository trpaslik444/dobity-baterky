<?php
require_once 'wp-load.php';

echo "=== COMPREHENSIVE ISOCHRONES TESTING ===\n\n";

// Test 1: Zkontrolovat, jestli se vůbec volá loadAndRenderNearby
echo "=== TEST 1: Frontend Function Calls ===\n";
echo "Checking if loadAndRenderNearby is called...\n";
echo "This test requires browser console logs.\n";
echo "Expected: [DB Map] loadAndRenderNearby called for feature: [ID]\n\n";

// Test 2: Backend data availability
echo "=== TEST 2: Backend Data Availability ===\n";

// Najít několik různých bodů k testování
global $wpdb;
$test_points = $wpdb->get_results("
    SELECT p.ID, p.post_title, p.post_type, 
           pm_lat.meta_value as lat, pm_lng.meta_value as lng
    FROM {$wpdb->posts} p
    LEFT JOIN {$wpdb->postmeta} pm_lat ON p.ID = pm_lat.post_id AND pm_lat.meta_key = '_db_lat'
    LEFT JOIN {$wpdb->postmeta} pm_lng ON p.ID = pm_lng.post_id AND pm_lng.meta_key = '_db_lng'
    WHERE p.post_type IN ('charging_location', 'poi') 
    AND p.post_status = 'publish'
    AND pm_lat.meta_value IS NOT NULL 
    AND pm_lng.meta_value IS NOT NULL
    ORDER BY RAND()
    LIMIT 5
");

foreach ($test_points as $point) {
    echo "Testing point {$point->ID} ({$point->post_title})...\n";
    
    // Test on-demand status
    $rest_ondemand = new \DB\REST_On_Demand();
    $request = new \WP_REST_Request('GET', '/db/v1/ondemand/status/' . $point->ID);
    $request->set_param('point_id', $point->ID);
    $request->set_param('type', $point->post_type);
    $response = $rest_ondemand->check_status($request);
    
    if (is_wp_error($response)) {
        echo "  ERROR: " . $response->get_error_message() . "\n";
    } else {
        $data = $response->get_data();
        echo "  Status: " . ($data['status'] ?? 'N/A') . "\n";
        echo "  Items: " . count($data['items'] ?? []) . "\n";
        echo "  Has isochrones: " . (isset($data['isochrones']) ? 'Yes' : 'No') . "\n";
        
        if (isset($data['isochrones'])) {
            echo "  Isochrones geojson: " . (isset($data['isochrones']['geojson']['features']) ? count($data['isochrones']['geojson']['features']) : 0) . " features\n";
            echo "  User settings: " . (isset($data['isochrones']['user_settings']) ? 'Yes' : 'No') . "\n";
        }
    }
    echo "\n";
}

// Test 3: Meta keys analysis
echo "=== TEST 3: Meta Keys Analysis ===\n";
$meta_analysis = $wpdb->get_results("
    SELECT meta_key, COUNT(*) as count, 
           AVG(LENGTH(meta_value)) as avg_length
    FROM {$wpdb->postmeta} 
    WHERE meta_key LIKE '%nearby%' OR meta_key LIKE '%isochrone%'
    GROUP BY meta_key
    ORDER BY count DESC
");

foreach ($meta_analysis as $meta) {
    echo "Key: {$meta->meta_key} (count: {$meta->count}, avg_length: " . round($meta->avg_length) . ")\n";
}
echo "\n";

// Test 4: Frontend simulation
echo "=== TEST 4: Frontend Simulation ===\n";
echo "Simulating frontend fetchNearby calls...\n";

foreach (array_slice($test_points, 0, 3) as $point) {
    echo "Simulating fetchNearby for point {$point->ID}...\n";
    
    // Simulate fetchNearby logic
    $url = "/wp-json/db/v1/ondemand/status/{$point->ID}?type={$point->post_type}";
    echo "  URL: $url\n";
    
    // Test the actual endpoint
    $response = wp_remote_get(home_url($url));
    if (is_wp_error($response)) {
        echo "  ERROR: " . $response->get_error_message() . "\n";
    } else {
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        echo "  Response status: " . wp_remote_retrieve_response_code($response) . "\n";
        echo "  Data status: " . ($data['status'] ?? 'N/A') . "\n";
        echo "  Has isochrones: " . (isset($data['isochrones']) ? 'Yes' : 'No') . "\n";
        
        if (isset($data['isochrones'])) {
            echo "  Isochrones structure:\n";
            echo "    Has geojson: " . (isset($data['isochrones']['geojson']) ? 'Yes' : 'No') . "\n";
            echo "    Has user_settings: " . (isset($data['isochrones']['user_settings']) ? 'Yes' : 'No') . "\n";
            if (isset($data['isochrones']['user_settings'])) {
                echo "    User settings: " . json_encode($data['isochrones']['user_settings']) . "\n";
            }
        }
    }
    echo "\n";
}

// Test 5: Process endpoint test
echo "=== TEST 5: Process Endpoint Test ===\n";
$test_point = $test_points[0];
echo "Testing process endpoint for point {$test_point->ID}...\n";

$request = new \WP_REST_Request('POST', '/db/v1/ondemand/process');
$request->set_param('point_id', $test_point->ID);
$request->set_param('point_type', $test_point->post_type);
$request->set_param('token', 'frontend-trigger');
$response = $rest_ondemand->process_point($request);

if (is_wp_error($response)) {
    echo "ERROR: " . $response->get_error_message() . "\n";
} else {
    $data = $response->get_data();
    echo "Process Status: " . ($data['status'] ?? 'N/A') . "\n";
    echo "Items count: " . count($data['items'] ?? []) . "\n";
    echo "Has isochrones: " . (isset($data['isochrones']) ? 'Yes' : 'No') . "\n";
    
    if (isset($data['isochrones'])) {
        echo "Isochrones structure:\n";
        echo "  Has geojson: " . (isset($data['isochrones']['geojson']) ? 'Yes' : 'No') . "\n";
        echo "  Has user_settings: " . (isset($data['isochrones']['user_settings']) ? 'Yes' : 'No') . "\n";
        if (isset($data['isochrones']['user_settings'])) {
            echo "  User settings: " . json_encode($data['isochrones']['user_settings']) . "\n";
        }
    }
}

echo "\n=== TEST 6: Frontend Settings Simulation ===\n";
$frontend_settings = array("enabled" => true, "walking_speed" => 4.5);
echo "Frontend settings: " . json_encode($frontend_settings) . "\n";

// Test with different points
foreach (array_slice($test_points, 0, 2) as $point) {
    echo "Testing frontend logic for point {$point->ID}...\n";
    
    $request = new \WP_REST_Request('GET', '/db/v1/ondemand/status/' . $point->ID);
    $request->set_param('point_id', $point->ID);
    $request->set_param('type', $point->post_type);
    $response = $rest_ondemand->check_status($request);
    
    if (!is_wp_error($response)) {
        $data = $response->get_data();
        if (isset($data['isochrones'])) {
            $backend_enabled = $data['isochrones']['user_settings']['enabled'] ?? false;
            $frontend_enabled = $frontend_settings['enabled'];
            $has_geojson = isset($data['isochrones']['geojson']['features']) && count($data['isochrones']['geojson']['features']) > 0;
            
            echo "  Backend enabled: " . ($backend_enabled ? 'Yes' : 'No') . "\n";
            echo "  Frontend enabled: " . ($frontend_enabled ? 'Yes' : 'No') . "\n";
            echo "  Has geojson features: " . ($has_geojson ? 'Yes' : 'No') . "\n";
            echo "  Would render isochrones: " . ($backend_enabled && $frontend_enabled && $has_geojson ? 'Yes' : 'No') . "\n";
        }
    }
    echo "\n";
}

echo "=== TEST COMPLETE ===\n";
echo "Next steps:\n";
echo "1. Check browser console for loadAndRenderNearby calls\n";
echo "2. Verify that isochrones are being fetched from backend\n";
echo "3. Check if frontend rendering logic is working\n";
echo "4. Test with different point types (POI vs charging_location)\n";
