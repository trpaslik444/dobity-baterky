<?php
// Run: php scripts/test-google-nearby.php <charging_id> [radius_m] [max_results] [min_rating]
// Bootstraps WP and invokes Google_Nearby_Importer::maybe_import_for_charging

declare(strict_types=1);

// Locate wp-load.php by walking up the tree
$dir = __DIR__;
$wpLoad = null;
for ($i=0; $i<8; $i++) {
    $candidate = $dir . '/wp-load.php';
    if (file_exists($candidate)) { $wpLoad = $candidate; break; }
    $candidate = dirname($dir) . '/wp-load.php';
    if (file_exists($candidate)) { $wpLoad = $candidate; break; }
    $dir = dirname($dir);
}
if ($wpLoad === null) {
    $maybe = dirname(__DIR__, 4) . '/wp-load.php';
    if (file_exists($maybe)) { $wpLoad = $maybe; }
}
if ($wpLoad === null) {
    fwrite(STDERR, "Cannot locate wp-load.php. Run from WordPress site tree.\n");
    exit(1);
}
require_once $wpLoad;

use DB\Google_Nearby_Importer;

if (php_sapi_name() !== 'cli') {
    echo "CLI only\n";
    exit(1);
}

$chargingId  = isset($argv[1]) ? (int)$argv[1] : 0;
$radiusM     = isset($argv[2]) ? (int)$argv[2] : null;
$maxResults  = isset($argv[3]) ? (int)$argv[3] : null;
$minRating   = isset($argv[4]) ? (float)$argv[4] : null;

if ($chargingId <= 0) {
    fwrite(STDERR, "Usage: php scripts/test-google-nearby.php <charging_id> [radius_m] [max_results] [min_rating]\n");
    exit(2);
}

$apiKey = (string) get_option('db_google_api_key');
if ($apiKey === '') {
    fwrite(STDERR, "db_google_api_key is not set in WP options.\n");
}

// Optionally override filters for this run
$optKey = 'db_google_nearby_filters';
$current = get_option($optKey, []);
$override = $current;
if ($radiusM !== null) { $override['radius_m'] = $radiusM; }
if ($maxResults !== null) { $override['max_results'] = $maxResults; }
if ($minRating !== null) { $override['min_rating'] = $minRating; }

if ($override !== $current) {
    update_option($optKey, $override);
}

$svc = new Google_Nearby_Importer();
$result = $svc->maybe_import_for_charging($chargingId);

echo json_encode([
    'input' => [
        'charging_id' => $chargingId,
        'radius_m' => $override['radius_m'] ?? null,
        'max_results' => $override['max_results'] ?? null,
        'min_rating' => $override['min_rating'] ?? null,
    ],
    'result' => $result,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";


