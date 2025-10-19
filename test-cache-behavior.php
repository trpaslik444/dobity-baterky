<?php
/**
 * Test cache chování - kontrola, zda se data skutečně ukládají na 30 dní
 * Spustit: php test-cache-behavior.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Test cache chování ===\n\n";

// Test 1: Kontrola cache klíčů pro Google API
echo "1. KONTROLA CACHE KLÍČŮ PRO GOOGLE API\n";
echo "=======================================\n";

global $wpdb;

// Najdeme všechny cache klíče související s Google API
$cacheKeys = $wpdb->get_results("
    SELECT option_name, option_value, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(option_value) as age_seconds
    FROM {$wpdb->options} 
    WHERE option_name LIKE '%google%' 
    OR option_name LIKE '%_cache%'
    OR option_name LIKE '%transient%'
    ORDER BY option_name
");

echo "📋 Cache klíče související s Google API:\n";
foreach ($cacheKeys as $key) {
    $ageHours = round($key->age_seconds / 3600, 2);
    $ageDays = round($ageHours / 24, 2);
    echo "  - {$key->option_name}: {$ageHours}h ({$ageDays}d)\n";
}

echo "\n\n";

// Test 2: Kontrola transient cache
echo "2. KONTROLA TRANSIENT CACHE\n";
echo "============================\n";

// Najdeme všechny transient klíče
$transientKeys = $wpdb->get_results("
    SELECT option_name, option_value, UNIX_TIMESTAMP() - UNIX_TIMESTAMP(option_value) as age_seconds
    FROM {$wpdb->options} 
    WHERE option_name LIKE '_transient_%' 
    OR option_name LIKE '_transient_timeout_%'
    ORDER BY option_name
");

echo "📋 Transient cache klíče:\n";
$transientData = [];

foreach ($transientKeys as $key) {
    if (strpos($key->option_name, '_transient_timeout_') === 0) {
        $transientName = str_replace('_transient_timeout_', '', $key->option_name);
        $expiration = (int) $key->option_value;
        $now = time();
        $remaining = $expiration - $now;
        
        echo "  - {$transientName}: expires in " . round($remaining / 3600, 2) . "h\n";
        
        $transientData[$transientName] = [
            'expires' => $expiration,
            'remaining_hours' => round($remaining / 3600, 2)
        ];
    }
}

echo "\n\n";

// Test 3: Test cache pro konkrétní POI
echo "3. TEST CACHE PRO KONKRÉTNÍ POI\n";
echo "=================================\n";

// Najdeme nějaký POI z databáze
$poi = get_posts([
    'post_type' => 'poi',
    'posts_per_page' => 1,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => '_poi_lat',
            'compare' => 'EXISTS'
        ]
    ]
]);

if (!empty($poi)) {
    $testPoi = $poi[0];
    $poiId = $testPoi->ID;
    $poiTitle = $testPoi->post_title;
    
    echo "Testovací POI: {$poiTitle} (ID: {$poiId})\n";
    
    // Zkontrolujeme cache pro tento POI
    $poiCacheKeys = [
        "google_place_details_{$poiId}",
        "google_place_search_{$poiId}",
        "google_cache_{$poiId}",
        "poi_google_data_{$poiId}"
    ];
    
    foreach ($poiCacheKeys as $cacheKey) {
        $cached = get_transient($cacheKey);
        if ($cached !== false) {
            echo "  ✅ Cache klíč '{$cacheKey}' existuje\n";
        } else {
            echo "  ❌ Cache klíč '{$cacheKey}' neexistuje\n";
        }
    }
    
    // Zkontrolujeme post meta pro Google data
    $googleMetaKeys = [
        '_google_place_id',
        '_google_place_details',
        '_google_cache',
        '_google_cache_expires'
    ];
    
    echo "\nGoogle meta data:\n";
    foreach ($googleMetaKeys as $metaKey) {
        $value = get_post_meta($poiId, $metaKey, true);
        if (!empty($value)) {
            echo "  ✅ {$metaKey}: " . (is_array($value) ? 'Array' : substr($value, 0, 50)) . "\n";
        } else {
            echo "  ❌ {$metaKey}: prázdné\n";
        }
    }
} else {
    echo "❌ Žádné POI nenalezeny\n";
}

echo "\n\n";

// Test 4: Simulace cache testu
echo "4. SIMULACE CACHE TESTU\n";
echo "========================\n";

$testCacheKey = 'test_google_cache_' . time();
$testData = [
    'place_id' => 'test_place_123',
    'name' => 'Test Place',
    'fetched_at' => current_time('mysql')
];

// Uložíme test data na 30 dní
$cacheDuration = 30 * DAY_IN_SECONDS;
set_transient($testCacheKey, $testData, $cacheDuration);

echo "✅ Uloženo test data s klíčem: {$testCacheKey}\n";
echo "   Doba cache: " . round($cacheDuration / DAY_IN_SECONDS) . " dní\n";

// Zkontrolujeme, zda se data uložila
$retrieved = get_transient($testCacheKey);
if ($retrieved !== false) {
    echo "✅ Data se úspěšně uložila a načetla\n";
    echo "   Obsah: " . json_encode($retrieved) . "\n";
} else {
    echo "❌ Data se neuložila nebo nenačetla\n";
}

// Zkontrolujeme timeout
$timeoutKey = '_transient_timeout_' . $testCacheKey;
$timeout = get_option($timeoutKey);
if ($timeout) {
    $remaining = $timeout - time();
    echo "   Zbývající čas: " . round($remaining / 3600, 2) . " hodin\n";
}

echo "\n\n";

// Test 5: Kontrola cache v produkci
echo "5. KONTROLA CACHE V PRODUKCI\n";
echo "=============================\n";

// Zkontrolujeme, zda je cache vůbec aktivní
if (wp_using_ext_object_cache()) {
    echo "✅ Externí object cache je aktivní\n";
} else {
    echo "⚠️  Externí object cache není aktivní - používá se databázová cache\n";
}

// Zkontrolujeme cache konfiguraci
$cacheConfig = [
    'WP_CACHE' => defined('WP_CACHE') ? WP_CACHE : false,
    'WP_CACHE_KEY_SALT' => defined('WP_CACHE_KEY_SALT') ? WP_CACHE_KEY_SALT : 'default',
    'CACHE_EXPIRATION_TIME' => defined('CACHE_EXPIRATION_TIME') ? CACHE_EXPIRATION_TIME : 'not defined'
];

echo "Cache konfigurace:\n";
foreach ($cacheConfig as $key => $value) {
    echo "  - {$key}: " . (is_bool($value) ? ($value ? 'true' : 'false') : $value) . "\n";
}

echo "\n\n";

// Test 6: Test rychlého vypršení cache
echo "6. TEST RYCHLÉHO VYPRŠENÍ CACHE\n";
echo "=================================\n";

$quickTestKey = 'quick_cache_test_' . time();
$quickTestData = ['test' => 'data'];

// Uložíme na 5 sekund
set_transient($quickTestKey, $quickTestData, 5);

echo "✅ Uloženo test data na 5 sekund\n";

// Počkáme 6 sekund
echo "⏳ Čekání 6 sekund...\n";
sleep(6);

// Zkontrolujeme, zda cache vypršela
$retrieved = get_transient($quickTestKey);
if ($retrieved === false) {
    echo "✅ Cache správně vypršela po 5 sekundách\n";
} else {
    echo "❌ Cache nevypršela - problém s cache systémem!\n";
}

echo "\n\n";

// Test 7: Kontrola cache pro nabíjecí stanice
echo "7. KONTROLA CACHE PRO NABÍJECÍ STANICE\n";
echo "=======================================\n";

// Najdeme nabíjecí stanici
$chargingStation = get_posts([
    'post_type' => 'charging_location',
    'posts_per_page' => 1,
    'post_status' => 'publish'
]);

if (!empty($chargingStation)) {
    $station = $chargingStation[0];
    $stationId = $station->ID;
    
    echo "Testovací stanice: {$station->post_title} (ID: {$stationId})\n";
    
    // Zkontrolujeme cache pro nabíjecí stanice
    $chargingCacheKeys = [
        "_charging_google_cache",
        "_charging_google_cache_expires",
        "_charging_live_status",
        "_charging_live_status_expires"
    ];
    
    foreach ($chargingCacheKeys as $metaKey) {
        $value = get_post_meta($stationId, $metaKey, true);
        if (!empty($value)) {
            echo "  ✅ {$metaKey}: " . (is_array($value) ? 'Array' : substr($value, 0, 50)) . "\n";
            
            // Pokud je to timestamp, vypočítáme zbývající čas
            if (is_numeric($value) && $value > time()) {
                $remaining = $value - time();
                echo "    Zbývající čas: " . round($remaining / 3600, 2) . " hodin\n";
            }
        } else {
            echo "  ❌ {$metaKey}: prázdné\n";
        }
    }
}

echo "\n\n";

// Shrnutí
echo "8. SHRNUTÍ CACHE TESTU\n";
echo "======================\n";

echo "📊 Zjištění:\n";
echo "  - Cache systém: " . (wp_using_ext_object_cache() ? 'Externí' : 'Databázová') . "\n";
echo "  - Test cache: " . ($retrieved === false ? 'Funguje' : 'Nefunguje') . "\n";
echo "  - Google cache klíče: " . count($cacheKeys) . " nalezeno\n";
echo "  - Transient klíče: " . count($transientKeys) . " nalezeno\n";

echo "\n💡 Doporučení:\n";
echo "  - Zkontrolujte, zda se cache skutečně ukládá na 30 dní\n";
echo "  - Možná je problém s cache vypršením\n";
echo "  - Zkontrolujte produkční cache konfiguraci\n";

echo "\n✅ Test dokončen!\n";
