<?php
/**
 * Kontrola struktury databáze - jaké post typy a meta klíče máme
 * Spustit: php check-database-structure.php
 */

// Načtení WordPress
require_once '/Users/ondraplas/Local Sites/dobity-baterky-dev/app/public/wp-load.php';

if (!defined('WP_CLI')) {
    define('WP_CLI', true);
}

echo "=== Kontrola struktury databáze ===\n\n";

// Test 1: Kontrola všech post typů
echo "1. VŠECHNY POST TYPY V DATABÁZI\n";
echo "===============================\n";

global $wpdb;

$postTypes = $wpdb->get_results("
    SELECT post_type, COUNT(*) as count 
    FROM {$wpdb->posts} 
    WHERE post_status = 'publish' 
    GROUP BY post_type 
    ORDER BY count DESC
");

foreach ($postTypes as $type) {
    echo "📄 {$type->post_type}: {$type->count} příspěvků\n";
}

echo "\n\n";

// Test 2: Kontrola nabíjecích stanic
echo "2. KONTROLA NABÍJECÍCH STANIC\n";
echo "=============================\n";

$chargingTypes = ['charging_location', 'charging_station', 'charger', 'poi'];
$foundStations = [];

foreach ($chargingTypes as $type) {
    $stations = get_posts([
        'post_type' => $type,
        'posts_per_page' => 5,
        'post_status' => 'publish'
    ]);
    
    if (!empty($stations)) {
        echo "✅ {$type}: " . count($stations) . " příspěvků\n";
        $foundStations[$type] = $stations;
        
        foreach ($stations as $station) {
            echo "   - {$station->post_title} (ID: {$station->ID})\n";
        }
    } else {
        echo "❌ {$type}: 0 příspěvků\n";
    }
}

echo "\n\n";

// Test 3: Kontrola meta klíčů
echo "3. KONTROLA META KLÍČŮ\n";
echo "======================\n";

$metaKeys = $wpdb->get_results("
    SELECT meta_key, COUNT(*) as count 
    FROM {$wpdb->postmeta} 
    WHERE meta_key LIKE '%lat%' OR meta_key LIKE '%lng%' OR meta_key LIKE '%charging%' OR meta_key LIKE '%cl_%'
    GROUP BY meta_key 
    ORDER BY count DESC
");

echo "📍 Meta klíče se souřadnicemi nebo nabíjením:\n";
foreach ($metaKeys as $meta) {
    echo "   {$meta->meta_key}: {$meta->count} hodnot\n";
}

echo "\n\n";

// Test 4: Kontrola POI s nabíjením
echo "4. KONTROLA POI S NABÍJENÍM\n";
echo "===========================\n";

$pois = get_posts([
    'post_type' => 'poi',
    'posts_per_page' => 10,
    'post_status' => 'publish',
    'meta_query' => [
        [
            'key' => '_poi_lat',
            'compare' => 'EXISTS'
        ],
        [
            'key' => '_poi_lng',
            'compare' => 'EXISTS'
        ]
    ]
]);

echo "📍 POI se souřadnicemi: " . count($pois) . "\n";

if (!empty($pois)) {
    foreach ($pois as $poi) {
        $lat = get_post_meta($poi->ID, '_poi_lat', true);
        $lng = get_post_meta($poi->ID, '_poi_lng', true);
        echo "   - {$poi->post_title} ({$lat}, {$lng})\n";
    }
}

echo "\n\n";

// Test 5: Kontrola všech postů s lat/lng
echo "5. VŠECHNY POSTY SE SOUŘADNICEMI\n";
echo "================================\n";

$allWithCoords = $wpdb->get_results("
    SELECT p.ID, p.post_title, p.post_type, pm1.meta_value as lat, pm2.meta_value as lng
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm1 ON p.ID = pm1.post_id AND pm1.meta_key = '_poi_lat'
    INNER JOIN {$wpdb->postmeta} pm2 ON p.ID = pm2.post_id AND pm2.meta_key = '_poi_lng'
    WHERE p.post_status = 'publish'
    ORDER BY p.post_type, p.post_title
    LIMIT 20
");

echo "📍 Všechny posty se souřadnicemi:\n";
foreach ($allWithCoords as $post) {
    echo "   {$post->post_type}: {$post->post_title} ({$post->lat}, {$post->lng})\n";
}

echo "\n\n";

// Test 6: Kontrola meta klíčů pro nabíjení
echo "6. META KLÍČE PRO NABÍJENÍ\n";
echo "==========================\n";

$chargingMetaKeys = $wpdb->get_results("
    SELECT DISTINCT meta_key
    FROM {$wpdb->postmeta}
    WHERE meta_key LIKE '%charging%' OR meta_key LIKE '%cl_%' OR meta_key LIKE '%power%' OR meta_key LIKE '%connector%'
    ORDER BY meta_key
");

echo "🔌 Meta klíče související s nabíjením:\n";
foreach ($chargingMetaKeys as $meta) {
    $count = $wpdb->get_var($wpdb->prepare("
        SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s
    ", $meta->meta_key));
    echo "   {$meta->meta_key}: {$count} hodnot\n";
}

echo "\n\n";

// Test 7: Vyhledání postů s nabíjením
echo "7. VYHLEDÁNÍ POSTŮ S NABÍJENÍM\n";
echo "==============================\n";

$postsWithCharging = $wpdb->get_results("
    SELECT DISTINCT p.ID, p.post_title, p.post_type
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id
    WHERE p.post_status = 'publish'
    AND (pm.meta_key LIKE '%charging%' OR pm.meta_key LIKE '%cl_%' OR pm.meta_key LIKE '%power%' OR pm.meta_key LIKE '%connector%')
    ORDER BY p.post_type, p.post_title
    LIMIT 20
");

echo "🔌 Posty s nabíjením:\n";
foreach ($postsWithCharging as $post) {
    echo "   {$post->post_type}: {$post->post_title} (ID: {$post->ID})\n";
}

echo "\n\n";

// Test 8: Kontrola custom post typů
echo "8. CUSTOM POST TYPY\n";
echo "===================\n";

$customPostTypes = get_post_types(['public' => true], 'objects');

echo "📄 Registrované post typy:\n";
foreach ($customPostTypes as $postType) {
    $count = wp_count_posts($postType->name);
    echo "   {$postType->name}: {$count->publish} publikovaných\n";
    echo "      Label: {$postType->label}\n";
    echo "      Description: {$postType->description}\n";
}

echo "\n\n";

echo "=== Kontrola dokončena ===\n";
