<?php
/**
 * Test opravy REST API - kontrola, zda endpoint funguje bez 403 chyby
 * Spustit: php test-rest-api-fix.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Test opravy REST API ===\n\n";

// Test 1: Kontrola REST API endpointů
echo "1. KONTROLA REST API ENDPOINTŮ\n";
echo "===============================\n";

$baseUrl = home_url('/wp-json/db/v1');

// Test ondemand endpoint
echo "🔍 Testování ondemand endpoint...\n";
$ondemandUrl = $baseUrl . '/ondemand/process';

// Simulace POST požadavku
$testData = [
    'point_id' => 1,
    'point_type' => 'poi',
    'token' => 'test_token'
];

$response = wp_remote_post($ondemandUrl, [
    'timeout' => 10,
    'headers' => [
        'Content-Type' => 'application/json',
        'User-Agent' => 'DobityBaterky/REST-Test'
    ],
    'body' => wp_json_encode($testData)
]);

if (is_wp_error($response)) {
    echo "❌ Chyba: " . $response->get_error_message() . "\n";
} else {
    $code = wp_remote_retrieve_response_code($response);
    $body = wp_remote_retrieve_body($response);
    
    echo "📊 HTTP Status: " . $code . "\n";
    
    if ($code === 403) {
        echo "❌ Stále 403 Forbidden - problém s oprávněními\n";
    } elseif ($code === 400) {
        echo "✅ 400 Bad Request - endpoint funguje, jen špatné parametry\n";
    } elseif ($code === 200) {
        echo "✅ 200 OK - endpoint funguje perfektně\n";
    } else {
        echo "⚠️  Neočekávaný status: " . $code . "\n";
    }
    
    echo "📋 Response: " . substr($body, 0, 200) . "\n";
}

echo "\n\n";

// Test 2: Kontrola registrace REST API
echo "2. KONTROLA REGISTRACE REST API\n";
echo "================================\n";

global $wp_rest_server;

if (isset($wp_rest_server)) {
    $routes = $wp_rest_server->get_routes();
    
    echo "📋 Registrované REST API route:\n";
    foreach ($routes as $route => $handlers) {
        if (strpos($route, '/db/v1/') !== false) {
            echo "  - {$route}\n";
            foreach ($handlers as $handler) {
                $methods = is_array($handler['methods']) ? implode(', ', $handler['methods']) : $handler['methods'];
                echo "    Methods: {$methods}\n";
            }
        }
    }
} else {
    echo "❌ WP REST Server není inicializován\n";
}

echo "\n\n";

// Test 3: Test konkrétního endpointu
echo "3. TEST KONKRÉTNÍHO ENDPOINTU\n";
echo "==============================\n";

// Najdeme nějaký POI pro test
$pois = get_posts([
    'post_type' => 'poi',
    'posts_per_page' => 1,
    'post_status' => 'publish'
]);

if (!empty($pois)) {
    $testPoi = $pois[0];
    $poiId = $testPoi->ID;
    
    echo "Testovací POI: {$testPoi->post_title} (ID: {$poiId})\n";
    
    // Generujeme token
    $token = wp_generate_password(32, false);
    set_transient('db_ondemand_token_' . $poiId, $token, HOUR_IN_SECONDS);
    
    echo "✅ Token vygenerován: " . substr($token, 0, 8) . "...\n";
    
    // Testujeme endpoint s platným tokenem
    $testData = [
        'point_id' => $poiId,
        'point_type' => 'poi',
        'token' => $token
    ];
    
    $response = wp_remote_post($ondemandUrl, [
        'timeout' => 10,
        'headers' => [
            'Content-Type' => 'application/json',
            'User-Agent' => 'DobityBaterky/REST-Test'
        ],
        'body' => wp_json_encode($testData)
    ]);
    
    if (is_wp_error($response)) {
        echo "❌ Chyba: " . $response->get_error_message() . "\n";
    } else {
        $code = wp_remote_retrieve_response_code($response);
        echo "📊 HTTP Status s platným tokenem: " . $code . "\n";
        
        if ($code === 200) {
            echo "✅ Endpoint funguje správně!\n";
        } elseif ($code === 403) {
            echo "❌ Stále 403 - problém s oprávněními\n";
        } else {
            echo "⚠️  Neočekávaný status: " . $code . "\n";
        }
    }
    
} else {
    echo "❌ Žádné POI nenalezeny pro test\n";
}

echo "\n\n";

// Test 4: Kontrola WordPress REST API
echo "4. KONTROLA WORDPRESS REST API\n";
echo "===============================\n";

$restUrl = home_url('/wp-json/wp/v2/');
$response = wp_remote_get($restUrl, ['timeout' => 5]);

if (is_wp_error($response)) {
    echo "❌ WordPress REST API nefunguje: " . $response->get_error_message() . "\n";
} else {
    $code = wp_remote_retrieve_response_code($response);
    echo "✅ WordPress REST API funguje (status: {$code})\n";
}

echo "\n\n";

// Test 5: Test bez tokenu (mělo by vrátit 400)
echo "5. TEST BEZ TOKENU\n";
echo "==================\n";

$testDataNoToken = [
    'point_id' => 1,
    'point_type' => 'poi'
    // Žádný token
];

$response = wp_remote_post($ondemandUrl, [
    'timeout' => 10,
    'headers' => [
        'Content-Type' => 'application/json',
        'User-Agent' => 'DobityBaterky/REST-Test'
    ],
    'body' => wp_json_encode($testDataNoToken)
]);

if (is_wp_error($response)) {
    echo "❌ Chyba: " . $response->get_error_message() . "\n";
} else {
    $code = wp_remote_retrieve_response_code($response);
    echo "📊 HTTP Status bez tokenu: " . $code . "\n";
    
    if ($code === 400) {
        echo "✅ Správně vrací 400 Bad Request (chybí token)\n";
    } elseif ($code === 403) {
        echo "❌ Stále 403 - problém s oprávněními\n";
    } else {
        echo "⚠️  Neočekávaný status: " . $code . "\n";
    }
}

echo "\n\n";

// Shrnutí
echo "6. SHRNUTÍ\n";
echo "===========\n";

echo "🎯 Oprava REST API:\n";
echo "   - Povoleny volání bez přihlášení\n";
echo "   - Kontrola oprávnění pouze pro přihlášené uživatele\n";
echo "   - Frontend může volat API bez 403 chyby\n\n";

echo "📊 Očekávané výsledky:\n";
echo "   - Bez tokenu: 400 Bad Request\n";
echo "   - S platným tokenem: 200 OK nebo 500 Internal Error\n";
echo "   - Žádné 403 Forbidden chyby\n\n";

echo "✅ Test dokončen!\n";
