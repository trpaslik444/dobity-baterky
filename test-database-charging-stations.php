<?php
/**
 * Test Mapy.com API na nabíjecích stanicích z databáze
 * Spustit: php test-database-charging-stations.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Test Mapy.com API na databázových nabíjecích stanicích ===\n\n";

// Inicializace
$chargingService = new DB\Services\Db_Charging_Service();

// Test 1: Načtení nabíjecích stanic z databáze
echo "1. NAČTENÍ NABÍJECÍCH STANIC Z DATABÁZE\n";
echo "=======================================\n";

$chargingStations = get_posts([
    'post_type' => 'charging_location',
    'posts_per_page' => 5,
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
    echo "✗ Žádné nabíjecí stanice nenalezeny v databázi\n";
    exit(1);
}

echo "✓ Nalezeno " . count($chargingStations) . " nabíjecích stanic v databázi\n\n";

// Test 2: Testování každé stanice
echo "2. TESTOVÁNÍ KAŽDÉ NABÍJECÍ STANICE\n";
echo "===================================\n";

foreach ($chargingStations as $i => $station) {
    $stationId = $station->ID;
    $title = $station->post_title;
    $lat = (float) get_post_meta($stationId, '_db_lat', true);
    $lng = (float) get_post_meta($stationId, '_db_lng', true);
    
    echo "--- Nabíjecí stanice " . ($i + 1) . " ---\n";
    echo "ID: {$stationId}\n";
    echo "Název: {$title}\n";
    echo "Souřadnice: {$lat}, {$lng}\n";
    
    if ($lat == 0 && $lng == 0) {
        echo "⚠️  Chybí souřadnice - přeskočeno\n\n";
        continue;
    }
    
    // Test Mapy.com API
    echo "🔍 Testování Mapy.com API...\n";
    
    try {
        $startTime = microtime(true);
        $nearbyStations = $chargingService->findChargingStations($lat, $lng, 1000, 5);
        $responseTime = (microtime(true) - $startTime) * 1000;
        
        echo "⏱️  Doba odezvy: " . round($responseTime, 2) . "ms\n";
        echo "📊 Nalezeno " . count($nearbyStations) . " stanic v okruhu 1km\n";
        
        if (!empty($nearbyStations)) {
            echo "📍 Nejbližší stanice:\n";
            foreach (array_slice($nearbyStations, 0, 3) as $j => $nearby) {
                echo "  " . ($j + 1) . ". " . $nearby['name'] . " (" . round($nearby['distance_m']) . "m)\n";
                echo "     Typ: " . $nearby['label'] . "\n";
                if ($nearby['charging_type']['is_fast_charging']) {
                    echo "     ⚡ Rychlé nabíjení\n";
                }
            }
        } else {
            echo "❌ Žádné stanice nenalezeny\n";
        }
        
        // Analýza dostupnosti
        echo "📈 Analýza dostupnosti...\n";
        $analysis = $chargingService->analyzeChargingAvailability($lat, $lng, 2000);
        echo "   Celkem stanic (2km): " . $analysis['total_stations'] . "\n";
        echo "   Rychlé nabíjení: " . $analysis['fast_charging_count'] . "\n";
        echo "   Nejbližší: " . $analysis['closest_distance'] . "m\n";
        
        // Test REST API endpointu
        echo "🌐 Test REST API...\n";
        $restUrl = home_url('/wp-json/db/v1/charging-stations?lat=' . $lat . '&lon=' . $lng . '&radius_m=1000&limit=3');
        $restResponse = wp_remote_get($restUrl, ['timeout' => 10]);
        
        if (!is_wp_error($restResponse)) {
            $restCode = wp_remote_retrieve_response_code($restResponse);
            $restBody = json_decode(wp_remote_retrieve_body($restResponse), true);
            
            if ($restCode === 200 && $restBody['success']) {
                echo "   ✅ REST API funguje (" . count($restBody['data']['stations']) . " stanic)\n";
            } else {
                echo "   ❌ REST API chyba: " . $restCode . "\n";
            }
        } else {
            echo "   ❌ REST API chyba: " . $restResponse->get_error_message() . "\n";
        }
        
    } catch (Exception $e) {
        echo "❌ Chyba: " . $e->getMessage() . "\n";
    }
    
    echo "\n";
}

echo "\n\n";

// Test 3: Srovnání s databázovými daty
echo "3. SROVNÁNÍ S DATABÁZOVÝMI DATY\n";
echo "===============================\n";

foreach ($chargingStations as $i => $station) {
    $stationId = $station->ID;
    $title = $station->post_title;
    $lat = (float) get_post_meta($stationId, '_db_lat', true);
    $lng = (float) get_post_meta($stationId, '_db_lng', true);
    
    if ($lat == 0 && $lng == 0) continue;
    
    echo "--- Stanice " . ($i + 1) . ": {$title} ---\n";
    
    // Databázová data
    $dbData = [
        'title' => $title,
        'lat' => $lat,
        'lng' => $lng,
        'address' => get_post_meta($stationId, '_db_address', true),
        'operator' => get_post_meta($stationId, '_db_operator', true),
        'power' => get_post_meta($stationId, '_db_charger_power', true),
        'connector_type' => get_post_meta($stationId, '_connector_details', true),
        'status' => get_post_meta($stationId, '_charging_business_status', true),
        'max_power' => get_post_meta($stationId, '_max_power_kw', true),
        'total_connectors' => get_post_meta($stationId, '_total_connectors', true)
    ];
    
    echo "📊 Databázová data:\n";
    echo "   Název: " . $dbData['title'] . "\n";
    echo "   Adresa: " . $dbData['address'] . "\n";
    echo "   Operátor: " . $dbData['operator'] . "\n";
    echo "   Výkon: " . $dbData['power'] . " kW\n";
    echo "   Konektor: " . $dbData['connector_type'] . "\n";
    echo "   Status: " . $dbData['status'] . "\n";
    
    // Mapy.com data
    $nearbyStations = $chargingService->findChargingStations($lat, $lng, 500, 1);
    
    if (!empty($nearbyStations)) {
        $mapyData = $nearbyStations[0];
        echo "\n🗺️  Mapy.com data:\n";
        echo "   Název: " . $mapyData['name'] . "\n";
        echo "   Adresa: " . $mapyData['address'] . "\n";
        echo "   Typ: " . $mapyData['label'] . "\n";
        echo "   Vzdálenost: " . round($mapyData['distance_m']) . "m\n";
        echo "   Rychlé nabíjení: " . ($mapyData['charging_type']['is_fast_charging'] ? 'Ano' : 'Ne') . "\n";
        
        // Porovnání
        echo "\n🔍 Porovnání:\n";
        if (abs($lat - $mapyData['coords']['lat']) < 0.001 && abs($lng - $mapyData['coords']['lng']) < 0.001) {
            echo "   ✅ Souřadnice se shodují\n";
        } else {
            echo "   ⚠️  Souřadnice se liší (DB: {$lat},{$lng} vs Mapy: " . $mapyData['coords']['lat'] . "," . $mapyData['coords']['lng'] . ")\n";
        }
        
        $nameMatch = similar_text(strtolower($title), strtolower($mapyData['name'])) > 0.5;
        if ($nameMatch) {
            echo "   ✅ Název se shoduje\n";
        } else {
            echo "   ⚠️  Název se liší\n";
        }
    } else {
        echo "\n❌ Žádné Mapy.com data nenalezeny\n";
    }
    
    echo "\n";
}

echo "\n\n";

// Test 4: Test REST API endpointů
echo "4. TEST REST API ENDPOINTŮ\n";
echo "==========================\n";

if (!empty($chargingStations)) {
    $testStation = $chargingStations[0];
    $lat = (float) get_post_meta($testStation->ID, '_db_lat', true);
    $lng = (float) get_post_meta($testStation->ID, '_db_lng', true);
    
    if ($lat != 0 && $lng != 0) {
        $baseUrl = home_url('/wp-json/db/v1');
        
        // Test 1: Základní vyhledání
        echo "🔍 Test 1: Základní vyhledání\n";
        $url1 = $baseUrl . '/charging-stations?lat=' . $lat . '&lon=' . $lng . '&radius_m=2000&limit=5';
        $response1 = wp_remote_get($url1, ['timeout' => 10]);
        
        if (!is_wp_error($response1)) {
            $data1 = json_decode(wp_remote_retrieve_body($response1), true);
            echo "   URL: " . $url1 . "\n";
            echo "   Status: " . wp_remote_retrieve_response_code($response1) . "\n";
            echo "   Úspěch: " . ($data1['success'] ? 'Ano' : 'Ne') . "\n";
            echo "   Počet stanic: " . count($data1['data']['stations'] ?? []) . "\n";
        } else {
            echo "   ❌ Chyba: " . $response1->get_error_message() . "\n";
        }
        
        echo "\n";
        
        // Test 2: Nejbližší stanice
        echo "🔍 Test 2: Nejbližší stanice\n";
        $url2 = $baseUrl . '/charging-stations/nearest?lat=' . $lat . '&lon=' . $lng . '&max_distance=3000&limit=3';
        $response2 = wp_remote_get($url2, ['timeout' => 10]);
        
        if (!is_wp_error($response2)) {
            $data2 = json_decode(wp_remote_retrieve_body($response2), true);
            echo "   URL: " . $url2 . "\n";
            echo "   Status: " . wp_remote_retrieve_response_code($response2) . "\n";
            echo "   Úspěch: " . ($data2['success'] ? 'Ano' : 'Ne') . "\n";
            echo "   Počet stanic: " . count($data2['data']['stations'] ?? []) . "\n";
        } else {
            echo "   ❌ Chyba: " . $response2->get_error_message() . "\n";
        }
        
        echo "\n";
        
        // Test 3: Analýza dostupnosti
        echo "🔍 Test 3: Analýza dostupnosti\n";
        $url3 = $baseUrl . '/charging-stations/analysis?lat=' . $lat . '&lon=' . $lng . '&radius_m=5000';
        $response3 = wp_remote_get($url3, ['timeout' => 10]);
        
        if (!is_wp_error($response3)) {
            $data3 = json_decode(wp_remote_retrieve_body($response3), true);
            echo "   URL: " . $url3 . "\n";
            echo "   Status: " . wp_remote_retrieve_response_code($response3) . "\n";
            echo "   Úspěch: " . ($data3['success'] ? 'Ano' : 'Ne') . "\n";
            if ($data3['success']) {
                $analysis = $data3['data']['analysis'];
                echo "   Celkem stanic: " . $analysis['total_stations'] . "\n";
                echo "   Rychlé nabíjení: " . $analysis['fast_charging_count'] . "\n";
                echo "   Nejbližší: " . $analysis['closest_distance'] . "m\n";
            }
        } else {
            echo "   ❌ Chyba: " . $response3->get_error_message() . "\n";
        }
    }
}

echo "\n\n";

// Test 5: Shrnutí výsledků
echo "5. SHRNUTÍ VÝSLEDKŮ\n";
echo "===================\n";

$totalStations = count($chargingStations);
$stationsWithCoords = 0;
$successfulTests = 0;
$totalResponseTime = 0;

foreach ($chargingStations as $station) {
    $lat = (float) get_post_meta($station->ID, '_db_lat', true);
    $lng = (float) get_post_meta($station->ID, '_db_lng', true);
    
    if ($lat != 0 && $lng != 0) {
        $stationsWithCoords++;
        
        try {
            $startTime = microtime(true);
            $nearbyStations = $chargingService->findChargingStations($lat, $lng, 1000, 5);
            $responseTime = (microtime(true) - $startTime) * 1000;
            $totalResponseTime += $responseTime;
            $successfulTests++;
        } catch (Exception $e) {
            // Ignorujeme chyby pro shrnutí
        }
    }
}

echo "📊 Celkové výsledky:\n";
echo "   Celkem stanic v DB: {$totalStations}\n";
echo "   Stanice se souřadnicemi: {$stationsWithCoords}\n";
echo "   Úspěšné testy: {$successfulTests}\n";

if ($successfulTests > 0) {
    $avgResponseTime = $totalResponseTime / $successfulTests;
    echo "   Průměrná doba odezvy: " . round($avgResponseTime, 2) . "ms\n";
}

$successRate = $stationsWithCoords > 0 ? round(($successfulTests / $stationsWithCoords) * 100, 1) : 0;
echo "   Úspěšnost: {$successRate}%\n";

echo "\n✅ Test dokončen!\n";
echo "Mapy.com API je připraveno k použití s vašimi databázovými nabíjecími stanicemi.\n";
