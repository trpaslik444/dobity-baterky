<?php
$candidates = [
    dirname(__DIR__, 4) . '/wp-load.php',
    dirname(__DIR__, 5) . '/wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
];
$wpLoad = null;
foreach ($candidates as $cand) { if (file_exists($cand)) { $wpLoad = $cand; break; } }
if ($wpLoad === null) { fwrite(STDERR, "wp-load.php not found\n"); exit(1); }
require_once $wpLoad;

$poiId = isset($argv[1]) ? (int)$argv[1] : 0;
if (!$poiId) { fwrite(STDERR, "Usage: php scripts/test-poi-external.php <POI_ID>\n"); exit(2); }

// Call REST_Map::handle_poi_external programmatically
if (!class_exists('DB\\REST_Map')) { fwrite(STDERR, "REST_Map not loaded\n"); exit(3); }

$rest = \DB\REST_Map::get_instance();
$req = new \WP_REST_Request('GET', '/db/v1/poi-external/' . $poiId);
$req->set_param('id', $poiId);
$res = $rest->handle_poi_external($req);

if ($res instanceof \WP_Error) {
    echo json_encode(['error' => $res->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(4);
}

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;


