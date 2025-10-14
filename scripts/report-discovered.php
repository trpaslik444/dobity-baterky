<?php
// Report posledních nalezených externích ID pro POI

$candidates = [
    dirname(__DIR__, 4) . '/wp-load.php',
    dirname(__DIR__, 5) . '/wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
];
$wpLoad = null;
foreach ($candidates as $cand) { if (file_exists($cand)) { $wpLoad = $cand; break; } }
if ($wpLoad === null) { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require_once $wpLoad;

global $wpdb;

$limit = 10;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = (int)$m[1]; }
}

$report = [ 'google' => [], 'tripadvisor' => [] ];

// Google
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT pm.post_id, pm.meta_value AS place_id, pm.meta_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s ORDER BY pm.meta_id DESC LIMIT %d",
    '_poi_google_place_id', $limit
), ARRAY_A);
if (is_array($rows)) {
    foreach ($rows as $r) {
        $pid = (int)$r['post_id'];
        $report['google'][] = [
            'post_id' => $pid,
            'title' => get_the_title($pid),
            'place_id' => (string)$r['place_id'],
            'lat' => get_post_meta($pid, '_poi_lat', true),
            'lng' => get_post_meta($pid, '_poi_lng', true),
            'permalink' => get_permalink($pid),
        ];
    }
}

// Tripadvisor
$rows = $wpdb->get_results($wpdb->prepare(
    "SELECT pm.post_id, pm.meta_value AS location_id, pm.meta_id FROM {$wpdb->postmeta} pm WHERE pm.meta_key = %s ORDER BY pm.meta_id DESC LIMIT %d",
    '_poi_tripadvisor_location_id', $limit
), ARRAY_A);
if (is_array($rows)) {
    foreach ($rows as $r) {
        $pid = (int)$r['post_id'];
        $report['tripadvisor'][] = [
            'post_id' => $pid,
            'title' => get_the_title($pid),
            'location_id' => (string)$r['location_id'],
            'lat' => get_post_meta($pid, '_poi_lat', true),
            'lng' => get_post_meta($pid, '_poi_lng', true),
            'permalink' => get_permalink($pid),
        ];
    }
}

header('Content-Type: application/json; charset=utf-8');
echo json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;


