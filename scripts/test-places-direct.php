<?php
// Run: php scripts/test-places-direct.php <lat> <lng> [radius_m]
// Calls Places API v1 searchNearby directly with different FieldMasks and type sets

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
    // Try standard Local path
    $maybe = dirname(__DIR__, 4) . '/wp-load.php';
    if (file_exists($maybe)) { $wpLoad = $maybe; }
}
if ($wpLoad === null) {
    fwrite(STDERR, "Cannot locate wp-load.php. Run from WordPress site tree.\n");
    exit(1);
}
require_once $wpLoad;

if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }

$lat = isset($argv[1]) ? (float)$argv[1] : 0;
$lng = isset($argv[2]) ? (float)$argv[2] : 0;
$radius = isset($argv[3]) ? (int)$argv[3] : 2000;
if (!$lat || !$lng) { fwrite(STDERR, "Usage: php scripts/test-places-direct.php <lat> <lng> [radius_m]\n"); exit(2); }

$apiKey = (string) get_option('db_google_api_key');
if ($apiKey === '') { fwrite(STDERR, "db_google_api_key is not set in WP options.\n"); }

function call_nearby(string $key, float $lat, float $lng, int $radius, string $mask, array $types, string $rank = 'POPULARITY'): array {
    $body = [
        'includedTypes' => $types,
        'maxResultCount' => 10,
        'languageCode' => get_locale() ?: 'cs',
        'rankPreference' => $rank,
        'locationRestriction' => [
            'circle' => [
                'center' => [ 'latitude' => $lat, 'longitude' => $lng ],
                'radius' => $radius,
            ],
        ],
    ];
    $args = [
        'headers' => [
            'X-Goog-Api-Key' => $key,
            'X-Goog-FieldMask' => $mask,
            'Content-Type' => 'application/json; charset=utf-8',
        ],
        'body' => wp_json_encode($body),
        'timeout' => 12,
    ];
    $res = wp_remote_post('https://places.googleapis.com/v1/places:searchNearby', $args);
    if (is_wp_error($res)) {
        return ['wp_error' => $res->get_error_message()];
    }
    $code = (int) wp_remote_retrieve_response_code($res);
    return [
        'http' => $code,
        'mask' => $mask,
        'types' => $types,
        'body_excerpt' => substr((string) wp_remote_retrieve_body($res), 0, 500),
    ];
}

$maskFull = 'places.id,places.displayName,places.location,places.formattedAddress,places.primaryType,places.types,places.rating,places.userRatingCount,places.priceLevel,places.regularOpeningHours,places.shortFormattedAddress,places.iconMaskBaseUri,places.iconBackgroundColor,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.editorialSummary';
$maskSafe = 'places.id,places.displayName,places.location,places.formattedAddress,places.primaryType,places.types,places.rating,places.userRatingCount,places.priceLevel,places.shortFormattedAddress,places.iconMaskBaseUri,places.iconBackgroundColor';
$typesFull = ['cafe','restaurant','bar','bakery','supermarket','shopping_mall','store','tourist_attraction'];
$typesMin  = ['cafe','restaurant','tourist_attraction','lodging'];

$results = [];
$results[] = call_nearby($apiKey, $lat, $lng, $radius, $maskFull, $typesFull, 'POPULARITY');
$results[] = call_nearby($apiKey, $lat, $lng, $radius, $maskSafe, $typesFull, 'POPULARITY');
$results[] = call_nearby($apiKey, $lat, $lng, $radius, $maskSafe, $typesMin, 'POPULARITY');
$results[] = call_nearby($apiKey, $lat, $lng, $radius, $maskSafe, $typesMin, 'DISTANCE');

echo json_encode([
    'input' => ['lat'=>$lat,'lng'=>$lng,'radius_m'=>$radius],
    'results' => $results,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";


