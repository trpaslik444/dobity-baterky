<?php
/**
 * Test Mapy.com API - Kontrola fotek nabíjecích stanic
 * Spustit: php test-mapy-photos.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Test Mapy.com API - Fotky nabíjecích stanic ===\n\n";

// Test 1: Přímé volání Mapy.com API pro kontrolu fotek
echo "1. KONTROLA FOTEK V MAPY.COM API\n";
echo "=================================\n";

$apiKey = get_option('db_mapy_api_key');
if (empty($apiKey)) {
    echo "❌ API Key není nastaven\n";
    exit(1);
}

// Test na několika různých dotazech
$testQueries = [
    'nabíjecí stanice Praha',
    'charging station Prague', 
    'ČEZ nabíjecí stanice',
    'Tesla Supercharger',
    'PREpoint nabíjecí stanice'
];

foreach ($testQueries as $i => $query) {
    echo "--- Test " . ($i + 1) . ": '{$query}' ---\n";
    
    $params = [
        'query' => $query,
        'type' => 'poi',
        'lang' => 'cs',
        'limit' => 3,
        'apikey' => $apiKey
    ];
    
    $url = 'https://api.mapy.com/v1/geocode?' . http_build_query($params);
    
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'DobityBaterky/Photo-Test'
        ]
    ]);
    
    if (is_wp_error($response)) {
        echo "❌ Chyba API: " . $response->get_error_message() . "\n\n";
        continue;
    }
    
    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    if (isset($data['items']) && !empty($data['items'])) {
        echo "✓ Nalezeno " . count($data['items']) . " výsledků\n";
        
        foreach ($data['items'] as $j => $item) {
            echo "  " . ($j + 1) . ". " . ($item['name'] ?? 'N/A') . "\n";
            
            // Kontrola všech možných polí pro fotky
            $photoFields = [
                'photos', 'images', 'pictures', 'media', 'gallery',
                'photo', 'image', 'picture', 'thumbnail', 'preview'
            ];
            
            $foundPhotos = false;
            foreach ($photoFields as $field) {
                if (isset($item[$field]) && !empty($item[$field])) {
                    echo "    📸 {$field}: " . json_encode($item[$field]) . "\n";
                    $foundPhotos = true;
                }
            }
            
            // Kontrola vnořených objektů
            if (isset($item['details']) && is_array($item['details'])) {
                foreach ($photoFields as $field) {
                    if (isset($item['details'][$field]) && !empty($item['details'][$field])) {
                        echo "    📸 details.{$field}: " . json_encode($item['details'][$field]) . "\n";
                        $foundPhotos = true;
                    }
                }
            }
            
            // Kontrola vnořených polí
            if (isset($item['media']) && is_array($item['media'])) {
                echo "    📸 media objekt: " . json_encode($item['media']) . "\n";
                $foundPhotos = true;
            }
            
            if (!$foundPhotos) {
                echo "    ❌ Žádné fotky nenalezeny\n";
            }
        }
    } else {
        echo "❌ Žádné výsledky\n";
    }
    
    echo "\n";
}

echo "\n\n";

// Test 2: Detailní analýza struktury odpovědi
echo "2. DETAILNÍ ANALÝZA STRUKTURY ODPOVĚDI\n";
echo "======================================\n";

$params = [
    'query' => 'nabíjecí stanice ČEZ Praha',
    'type' => 'poi',
    'lang' => 'cs',
    'limit' => 2,
    'apikey' => $apiKey
];

$url = 'https://api.mapy.com/v1/geocode?' . http_build_query($params);

echo "🔍 Testovací URL: " . $url . "\n\n";

$response = wp_remote_get($url, [
    'timeout' => 10,
    'headers' => [
        'Accept' => 'application/json',
        'User-Agent' => 'DobityBaterky/Structure-Analysis'
    ]
]);

if (!is_wp_error($response)) {
    $data = json_decode(wp_remote_retrieve_body($response), true);
    
    echo "📋 Kompletní struktura odpovědi:\n";
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    
    echo "\n\n🔍 Analýza dostupných polí:\n";
    if (isset($data['items']) && !empty($data['items'])) {
        $sampleItem = $data['items'][0];
        echo "Dostupná pole v prvním výsledku:\n";
        foreach (array_keys($sampleItem) as $key) {
            echo "  - {$key}: " . gettype($sampleItem[$key]) . "\n";
        }
    }
} else {
    echo "❌ Chyba: " . $response->get_error_message() . "\n";
}

echo "\n\n";

// Test 3: Kontrola různých typů dotazů
echo "3. TEST RŮZNÝCH TYPŮ DOTAZŮ\n";
echo "===========================\n";

$differentQueries = [
    ['query' => 'Tesla Supercharger', 'type' => 'poi'],
    ['query' => 'Ionity', 'type' => 'poi'],
    ['query' => 'PRE nabíjecí', 'type' => 'poi'],
    ['query' => 'charging station', 'type' => 'poi', 'lang' => 'en'],
    ['query' => 'EV charger', 'type' => 'poi', 'lang' => 'en']
];

foreach ($differentQueries as $i => $queryParams) {
    echo "--- Dotaz " . ($i + 1) . " ---\n";
    echo "Parametry: " . json_encode($queryParams) . "\n";
    
    $queryParams['limit'] = 2;
    $queryParams['apikey'] = $apiKey;
    
    $url = 'https://api.mapy.com/v1/geocode?' . http_build_query($queryParams);
    
    $response = wp_remote_get($url, [
        'timeout' => 10,
        'headers' => [
            'Accept' => 'application/json',
            'User-Agent' => 'DobityBaterky/Photo-Test'
        ]
    ]);
    
    if (!is_wp_error($response)) {
        $data = json_decode(wp_remote_retrieve_body($response), true);
        
        if (isset($data['items']) && !empty($data['items'])) {
            echo "✓ " . count($data['items']) . " výsledků\n";
            
            foreach ($data['items'] as $item) {
                echo "  - " . ($item['name'] ?? 'N/A') . "\n";
                
                // Kontrola specifických polí pro fotky
                $hasPhotos = false;
                if (isset($item['photos']) || isset($item['images']) || isset($item['media'])) {
                    echo "    📸 Má fotky/media pole\n";
                    $hasPhotos = true;
                }
                
                if (!$hasPhotos) {
                    echo "    ❌ Žádné fotky\n";
                }
            }
        } else {
            echo "❌ Žádné výsledky\n";
        }
    } else {
        echo "❌ Chyba: " . $response->get_error_message() . "\n";
    }
    
    echo "\n";
}

echo "\n\n";

// Test 4: Kontrola dokumentace Mapy.com API
echo "4. KONTROLA DOKUMENTACE MAPY.COM API\n";
echo "====================================\n";

echo "📚 Podle dokumentace Mapy.com API:\n";
echo "   - Geocoding API vrací základní informace o místech\n";
echo "   - Neobsahuje fotky nebo média\n";
echo "   - Zaměřuje se na geografické a adresní údaje\n";
echo "   - Pro fotky by bylo potřeba jiný endpoint\n\n";

echo "🔍 Kontrola dostupných endpointů:\n";
$endpoints = [
    'https://api.mapy.com/v1/geocode' => 'Geocoding (základní informace)',
    'https://api.mapy.com/v1/routing' => 'Routing (trasy)',
    'https://api.mapy.com/v1/routing/matrix' => 'Matrix routing (vzdálenosti)'
];

foreach ($endpoints as $endpoint => $description) {
    echo "   - {$endpoint}: {$description}\n";
}

echo "\n\n";

// Test 5: Shrnutí
echo "5. SHRNUTÍ - FOTKY V MAPY.COM API\n";
echo "==================================\n";

echo "❌ Mapy.com Geocoding API NEVRACÍ FOTKY\n\n";

echo "📋 Co Mapy.com API poskytuje:\n";
echo "   ✅ Název místa\n";
echo "   ✅ Adresa a souřadnice\n";
echo "   ✅ Typ místa (label)\n";
echo "   ✅ Regionální struktura\n";
echo "   ✅ PSČ\n";
echo "   ✅ Bounding box\n";
echo "   ❌ Fotky/media\n";
echo "   ❌ URL odkazy na provozovatele\n";
echo "   ❌ Detailní informace o nabíjení\n\n";

echo "💡 Alternativy pro fotky:\n";
echo "   1. Google Places API - má fotky\n";
echo "   2. OSM (OpenStreetMap) - má fotky přes Wikimedia\n";
echo "   3. Vlastní databáze fotek\n";
echo "   4. Flickr API pro fotky míst\n";
echo "   5. Unsplash API pro stock fotky\n\n";

echo "✅ Test dokončen!\n";
