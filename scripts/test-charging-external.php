<?php
// Run: php scripts/test-charging-external.php <charging_id>
// Calls REST_Charging_Discovery::handle_external_details and prints payload (without WP HTTP layer)

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

use DB\REST_Charging_Discovery;

if (php_sapi_name() !== 'cli') { echo "CLI only\n"; exit(1); }
$postId = isset($argv[1]) ? (int)$argv[1] : 0;
if ($postId <= 0) { fwrite(STDERR, "Usage: php scripts/test-charging-external.php <charging_id>\n"); exit(2); }

$rest = new REST_Charging_Discovery();
$request = new WP_REST_Request('GET', '/db/v1/charging-external/'.$postId);
$response = $rest->handle_external_details($request);

// Normalize WP_Error / WP_REST_Response
if ($response instanceof WP_Error) {
    $out = [ 'error' => $response->get_error_code(), 'message' => $response->get_error_message(), 'data' => $response->get_error_data() ];
} else {
    $out = rest_ensure_response($response)->get_data();
}

echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";


