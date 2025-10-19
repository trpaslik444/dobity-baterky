<?php
/**
 * Test přesnosti Mapy.com API - kontrola GPS a adres
 * Spustit: php test-mapy-accuracy.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Test přesnosti Mapy.com API ===\n\n";

// Inicializace
$chargingService = new DB\Services\Db_Charging_Service();

/**
 * Výpočet vzdálenosti mezi dvěma body v metrech
 */
function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earthRadius = 6371000; // metry
    
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    
    $a = sin($dLat/2) * sin($dLat/2) +
         cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
         sin($dLon/2) * sin($dLon/2);
    
    $c = 2 * atan2(sqrt($a), sqrt(1-$a));
    
    return $earthRadius * $c;
}

/**
 * Porovnání adres - jednoduchá kontrola shody
 */
function compareAddresses(string $address1, string $address2): array {
    $addr1 = strtolower(trim($address1));
    $addr2 = strtolower(trim($address2));
    
    // Odstranění běžných slov
    $removeWords = ['česko', 'czech republic', 'cz-', 'psč'];
    foreach ($removeWords as $word) {
        $addr1 = str_replace($word, '', $addr1);
        $addr2 = str_replace($word, '', $addr2);
    }
    
    // Porovnání podobnosti
    $similarity = 0;
    similar_text($addr1, $addr2, $similarity);
    
    return [
        'similarity' => $similarity,
        'is_match' => $similarity > 70, // 70% shoda
        'addr1_clean' => $addr1,
        'addr2_clean' => $addr2
    ];
}

// Test 1: Načtení nabíjecích stanic z databáze
echo "1. NAČTENÍ NABÍJECÍCH STANIC Z DATABÁZE\n";
echo "=======================================\n";

$chargingStations = get_posts([
    'post_type' => 'charging_location',
    'posts_per_page' => 10, // Více stanic pro lepší test
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

echo "✓ Nalezeno " . count($chargingStations) . " nabíjecích stanic\n\n";

// Test 2: Detailní analýza přesnosti
echo "2. ANALÝZA PŘESNOSTI MAPY.COM API\n";
echo "=================================\n";

$accurateMatches = 0;
$totalTested = 0;
$results = [];

foreach ($chargingStations as $i => $station) {
    $stationId = $station->ID;
    $title = $station->post_title;
    $dbLat = (float) get_post_meta($stationId, '_db_lat', true);
    $dbLng = (float) get_post_meta($stationId, '_db_lng', true);
    $dbAddress = get_post_meta($stationId, '_db_address', true);
    
    if ($dbLat == 0 && $dbLng == 0) continue;
    
    $totalTested++;
    
    echo "--- Stanice " . ($i + 1) . ": {$title} ---\n";
    echo "DB GPS: {$dbLat}, {$dbLng}\n";
    echo "DB Adresa: {$dbAddress}\n";
    
    // Test Mapy.com API
    $mapyStations = $chargingService->findChargingStations($dbLat, $dbLng, 1000, 5);
    
    if (empty($mapyStations)) {
        echo "❌ Mapy.com nenalezla žádné stanice\n\n";
        continue;
    }
    
    $bestMatch = null;
    $bestScore = 0;
    
    foreach ($mapyStations as $mapyStation) {
        $mapyLat = $mapyStation['coords']['lat'] ?? 0;
        $mapyLng = $mapyStation['coords']['lon'] ?? 0; // Mapy.com používá 'lon', ne 'lng'
        $mapyAddress = $mapyStation['address'];
        $mapyName = $mapyStation['name'];
        
        // Výpočet vzdálenosti
        $distance = calculateDistance($dbLat, $dbLng, $mapyLat, $mapyLng);
        
        // Porovnání adres
        $addressComparison = compareAddresses($dbAddress, $mapyAddress);
        
        // Skóre shody (0-100)
        $score = 0;
        
        // GPS přesnost (max 50 bodů)
        if ($distance <= 50) {
            $score += 50; // Perfektní shoda
        } elseif ($distance <= 100) {
            $score += 40; // Velmi dobrá shoda
        } elseif ($distance <= 200) {
            $score += 25; // Dobrá shoda
        } elseif ($distance <= 500) {
            $score += 10; // Slabá shoda
        }
        
        // Shoda adres (max 30 bodů)
        if ($addressComparison['is_match']) {
            $score += 30;
        } else {
            $score += ($addressComparison['similarity'] / 100) * 15; // Částečná shoda
        }
        
        // Shoda názvu (max 20 bodů)
        $nameSimilarity = 0;
        similar_text(strtolower($title), strtolower($mapyName), $nameSimilarity);
        $score += ($nameSimilarity / 100) * 20;
        
        if ($score > $bestScore) {
            $bestScore = $score;
            $bestMatch = [
                'mapy_station' => $mapyStation,
                'distance' => $distance,
                'address_comparison' => $addressComparison,
                'name_similarity' => $nameSimilarity,
                'score' => $score
            ];
        }
    }
    
    if ($bestMatch) {
        $match = $bestMatch['mapy_station'];
        $distance = $bestMatch['distance'];
        $score = $bestMatch['score'];
        
        echo "🗺️  Nejlepší shoda:\n";
        echo "   Název: {$match['name']}\n";
        echo "   Adresa: {$match['address']}\n";
        echo "   GPS: {$match['coords']['lat']}, {$match['coords']['lon']}\n";
        echo "   Vzdálenost: " . round($distance) . "m\n";
        echo "   Skóre shody: " . round($score, 1) . "/100\n";
        
        // Kontrola odkazu
        if (isset($match['deep_links']['mapy'])) {
            echo "   Mapy.cz odkaz: {$match['deep_links']['mapy']}\n";
        }
        
        // Kontrola raw dat pro URL
        if (isset($match['raw']['url']) && !empty($match['raw']['url'])) {
            echo "   🔗 URL odkaz: {$match['raw']['url']}\n";
        }
        
        // Rozhodnutí o použitelnosti
        $isUsable = $distance <= 200 && $score >= 60;
        
        if ($isUsable) {
            echo "   ✅ POUŽITELNÉ (vzdálenost ≤200m, skóre ≥60)\n";
            $accurateMatches++;
        } else {
            echo "   ❌ NEPOUŽITELNÉ (vzdálenost >200m nebo skóre <60)\n";
        }
        
        $results[] = [
            'db_title' => $title,
            'db_lat' => $dbLat,
            'db_lng' => $dbLng,
            'db_address' => $dbAddress,
            'mapy_title' => $match['name'],
            'mapy_lat' => $match['coords']['lat'],
            'mapy_lng' => $match['coords']['lon'],
            'mapy_address' => $match['address'],
            'distance' => $distance,
            'score' => $score,
            'is_usable' => $isUsable
        ];
    }
    
    echo "\n";
}

echo "\n\n";

// Test 3: Shrnutí výsledků
echo "3. SHRNUTÍ VÝSLEDKŮ\n";
echo "===================\n";

echo "📊 Celkové výsledky:\n";
echo "   Celkem otestováno: {$totalTested}\n";
echo "   Použitelné shody: {$accurateMatches}\n";
echo "   Úspěšnost: " . ($totalTested > 0 ? round(($accurateMatches / $totalTested) * 100, 1) : 0) . "%\n\n";

if (!empty($results)) {
    echo "📋 Detailní výsledky:\n";
    foreach ($results as $result) {
        $status = $result['is_usable'] ? '✅' : '❌';
        echo "   {$status} {$result['db_title']}\n";
        echo "      Vzdálenost: " . round($result['distance']) . "m, Skóre: " . round($result['score'], 1) . "\n";
        echo "      DB: {$result['db_address']}\n";
        echo "      Mapy: {$result['mapy_address']}\n";
    }
}

echo "\n\n";

// Test 4: Kontrola URL odkazů v Mapy.com odpovědi
echo "4. KONTROLA URL ODKAZŮ V MAPY.COM\n";
echo "==================================\n";

$testStation = $chargingStations[0];
$testLat = (float) get_post_meta($testStation->ID, '_db_lat', true);
$testLng = (float) get_post_meta($testStation->ID, '_db_lng', true);

echo "Testování na stanici: {$testStation->post_title}\n";
echo "GPS: {$testLat}, {$testLng}\n\n";

// Přímé volání Mapy.com API pro kontrolu raw odpovědi
$apiKey = get_option('db_mapy_api_key');
if (!empty($apiKey)) {
    $params = [
        'query' => 'nabíjecí stanice',
        'type' => 'poi',
        'lang' => 'cs',
        'limit' => 3,
        'preferNear' => $testLng . ',' . $testLat,
        'preferNearPrecision' => 1000,
        'apikey' => $apiKey
    ];
    
    $url = 'https://api.mapy.com/v1/geocode?' . http_build_query($params);
    
    echo "🔍 API URL: " . $url . "\n\n";
    
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'DobityBaterky/Accuracy-Test'
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        echo "📋 Raw odpověď Mapy.com:\n";
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        
        echo "\n\n🔍 Analýza URL odkazů:\n";
        if (isset($data['items'])) {
            foreach ($data['items'] as $i => $item) {
                echo "--- Item " . ($i + 1) . " ---\n";
                echo "Název: " . ($item['name'] ?? 'N/A') . "\n";
                
                // Kontrola všech možných URL polí
                $urlFields = ['url', 'website', 'link', 'href', 'web'];
                foreach ($urlFields as $field) {
                    if (isset($item[$field]) && !empty($item[$field])) {
                        echo "🔗 {$field}: " . $item[$field] . "\n";
                    }
                }
                
                // Kontrola vnořených objektů
                if (isset($item['details']) && is_array($item['details'])) {
                    foreach ($urlFields as $field) {
                        if (isset($item['details'][$field]) && !empty($item['details'][$field])) {
                            echo "🔗 details.{$field}: " . $item['details'][$field] . "\n";
                        }
                    }
                }
                
                echo "\n";
            }
        }
    } else {
        echo "❌ Chyba API: " . $response->get_error_message() . "\n";
    }
} else {
    echo "❌ API Key není nastaven\n";
}

echo "\n\n";

// Test 5: Doporučení
echo "5. DOPORUČENÍ PRO POUŽITÍ\n";
echo "=========================\n";

$successRate = $totalTested > 0 ? ($accurateMatches / $totalTested) * 100 : 0;

if ($successRate >= 80) {
    echo "✅ VYSOKÁ ÚSPĚŠNOST ({$successRate}%)\n";
    echo "   Mapy.com API je vhodné pro automatické přiřazování\n";
    echo "   Doporučené prahové hodnoty: vzdálenost ≤200m, skóre ≥60\n";
} elseif ($successRate >= 60) {
    echo "⚠️  STŘEDNÍ ÚSPĚŠNOST ({$successRate}%)\n";
    echo "   Mapy.com API je použitelné s manuální kontrolou\n";
    echo "   Doporučené prahové hodnoty: vzdálenost ≤100m, skóre ≥70\n";
} else {
    echo "❌ NÍZKÁ ÚSPĚŠNOST ({$successRate}%)\n";
    echo "   Mapy.com API není vhodné pro automatické přiřazování\n";
    echo "   Doporučeno použít pouze pro vyhledávání v okolí\n";
}

echo "\n✅ Test dokončen!\n";
