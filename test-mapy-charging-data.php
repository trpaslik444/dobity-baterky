<?php
/**
 * Test Mapy.com API - Co vše můžeme získat o nabíječkách
 * Spustit: php test-mapy-charging-data.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Mapy.com API - Co získáváme o nabíječkách ===\n\n";

// Inicializace
$chargingService = new DB\Services\Db_Charging_Service();

// Test 1: Vyhledání nabíjecích stanic
echo "1. VYHLEDÁNÍ NABÍJECÍCH STANIC\n";
echo "===============================\n";

$lat = 50.0755; // Praha
$lon = 14.4378;

echo "Hledám nabíjecí stanice kolem: {$lat}, {$lon}\n\n";

$stations = $chargingService->findChargingStations($lat, $lon, 3000, 10);

if (!empty($stations)) {
    echo "✓ Nalezeno " . count($stations) . " nabíjecích stanic:\n\n";
    
    foreach ($stations as $i => $station) {
        echo "--- Nabíjecí stanice " . ($i + 1) . " ---\n";
        echo "Název: " . $station['name'] . "\n";
        echo "Typ: " . $station['label'] . "\n";
        echo "Adresa: " . $station['address'] . "\n";
        echo "Souřadnice: " . $station['coords']['lat'] . ", " . $station['coords']['lon'] . "\n";
        echo "Vzdálenost: " . round($station['distance_m']) . "m\n";
        echo "PSČ: " . $station['zip'] . "\n";
        
        // Analýza typu nabíjení
        echo "Typ nabíjení: ";
        if (!empty($station['charging_type']['detected_types'])) {
            echo implode(', ', $station['charging_type']['detected_types']) . "\n";
        } else {
            echo "Neurčeno\n";
        }
        
        echo "Rychlé nabíjení: " . ($station['charging_type']['is_fast_charging'] ? 'Ano' : 'Ne') . "\n";
        echo "Veřejné: " . ($station['charging_type']['is_public'] ? 'Ano' : 'Ne') . "\n";
        echo "Tesla: " . ($station['charging_type']['is_tesla'] ? 'Ano' : 'Ne') . "\n";
        
        // Odkazy
        echo "Mapy.cz: " . $station['deep_links']['mapy'] . "\n";
        echo "Geo: " . $station['deep_links']['geo'] . "\n";
        
        // Regionální struktura
        if (!empty($station['regional_structure'])) {
            echo "Regionální struktura:\n";
            foreach ($station['regional_structure'] as $region) {
                echo "  - " . $region['name'] . " (" . $region['type'] . ")\n";
            }
        }
        
        echo "\n";
    }
} else {
    echo "✗ Žádné nabíjecí stanice nenalezeny\n";
}

echo "\n\n";

// Test 2: Analýza dostupnosti nabíjení
echo "2. ANALÝZA DOSTUPNOSTI NABÍJENÍ\n";
echo "===============================\n";

$analysis = $chargingService->analyzeChargingAvailability($lat, $lon, 5000);

echo "Analýza v okruhu 5km:\n";
echo "- Celkový počet stanic: " . $analysis['total_stations'] . "\n";
echo "- Rychlé nabíjení: " . $analysis['fast_charging_count'] . "\n";
echo "- Veřejné stanice: " . $analysis['public_count'] . "\n";
echo "- Tesla stanice: " . $analysis['tesla_count'] . "\n";
echo "- Průměrná vzdálenost: " . $analysis['average_distance'] . "m\n";
echo "- Nejbližší stanice: " . $analysis['closest_distance'] . "m\n";

echo "\nDistribuce vzdáleností:\n";
foreach ($analysis['distribution'] as $range => $count) {
    echo "- {$range}: {$count} stanic\n";
}

echo "\n\n";

// Test 3: Nejbližší stanice
echo "3. NEJBLIŽŠÍ NABÍJECÍ STANICE\n";
echo "=============================\n";

$nearest = $chargingService->findNearestChargingStations($lat, $lon, 2000, 5);

if (!empty($nearest)) {
    echo "Nejbližší stanice v okruhu 2km:\n\n";
    foreach ($nearest as $i => $station) {
        echo ($i + 1) . ". " . $station['name'] . " - " . round($station['distance_m']) . "m\n";
        echo "   " . $station['address'] . "\n";
        echo "   Typ: " . $station['label'] . "\n";
        if ($station['charging_type']['is_fast_charging']) {
            echo "   ⚡ Rychlé nabíjení\n";
        }
        echo "\n";
    }
} else {
    echo "✗ Žádné stanice v okruhu 2km\n";
}

echo "\n\n";

// Test 4: Různé typy dotazů
echo "4. RŮZNÉ TYPY DOTAZŮ PRO NABÍJEČKY\n";
echo "==================================\n";

$queries = [
    'nabíjecí stanice',
    'charging station', 
    'EV charger',
    'elektrické nabíjení',
    'rychlodobíjecí stanice',
    'Tesla Supercharger',
    'Ionity',
    'PRE nabíjení'
];

foreach ($queries as $query) {
    echo "Dotaz: '{$query}'\n";
    
    $results = $chargingService->findChargingStations($lat, $lon, 5000, 3);
    $filtered = array_filter($results, function($station) use ($query) {
        $name = strtolower($station['name']);
        $queryLower = strtolower($query);
        return strpos($name, $queryLower) !== false || 
               strpos($station['label'], $queryLower) !== false;
    });
    
    if (!empty($filtered)) {
        echo "  ✓ Nalezeno " . count($filtered) . " výsledků\n";
        foreach ($filtered as $station) {
            echo "    - " . $station['name'] . " (" . round($station['distance_m']) . "m)\n";
        }
    } else {
        echo "  ✗ Žádné výsledky\n";
    }
    echo "\n";
}

echo "\n\n";

// Test 5: Raw data z Mapy.com
echo "5. RAW DATA Z MAPY.COM API\n";
echo "==========================\n";

$apiKey = get_option('db_mapy_api_key');
if (!empty($apiKey)) {
    $params = [
        'query' => 'nabíjecí stanice',
        'type' => 'poi',
        'lang' => 'cs',
        'limit' => 2,
        'preferNear' => $lon . ',' . $lat,
        'preferNearPrecision' => 2000,
        'apikey' => $apiKey
    ];
    
    $url = 'https://api.mapy.com/v1/geocode?' . http_build_query($params);
    
    echo "API URL: " . $url . "\n\n";
    
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'DobityBaterky/Charging-Service'
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        echo "HTTP Status: " . $code . "\n";
        echo "Raw Response (první 2 výsledky):\n";
        $data = json_decode($body, true);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    } else {
        echo "✗ API Error: " . $response->get_error_message() . "\n";
    }
} else {
    echo "✗ API Key not configured\n";
}

echo "\n\n";

// Shrnutí
echo "6. SHRNUTÍ - CO ZÍSKÁVÁME Z MAPY.COM\n";
echo "====================================\n";

echo "✓ Základní informace:\n";
echo "  - Název nabíjecí stanice\n";
echo "  - Typ/label (Kavárna, Autoservis, Rychlodobíjecí stanice, atd.)\n";
echo "  - Přesné souřadnice (lat, lon)\n";
echo "  - Plná adresa včetně PSČ\n";
echo "  - Regionální hierarchie (ulice → čtvrť → město → kraj → země)\n\n";

echo "✓ Geografické informace:\n";
echo "  - Bounding box pro mapové zobrazení\n";
echo "  - Vzdálenost od vyhledávaného bodu\n";
echo "  - GPS souřadnice pro navigaci\n\n";

echo "✓ Odkazy a integrace:\n";
echo "  - Mapy.cz deep link (otevře v aplikaci/webu)\n";
echo "  - Univerzální geo: odkaz (mobilní zařízení)\n";
echo "  - URL pro zobrazení na mapě\n\n";

echo "✓ Analýza typu nabíjení:\n";
echo "  - Detekce rychlého vs. pomalého nabíjení\n";
echo "  - Identifikace veřejných vs. soukromých stanic\n";
echo "  - Rozpoznání Tesla Superchargerů\n";
echo "  - Analýza na základě názvu a typu\n\n";

echo "✓ Statistiky a analýzy:\n";
echo "  - Počet stanic v oblasti\n";
echo "  - Distribuce vzdáleností\n";
echo "  - Průměrná a nejbližší vzdálenost\n";
echo "  - Rozdělení podle typů nabíjení\n\n";

echo "✓ Cache a výkon:\n";
echo "  - 24h cache pro optimalizaci\n";
echo "  - Kombinace více dotazů pro lepší pokrytí\n";
echo "  - Odstranění duplicit\n";
echo "  - Seřazení podle relevance a vzdálenosti\n\n";

echo "=== Test dokončen ===\n";
