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

if (!class_exists('DB\\REST_Map')) { fwrite(STDERR, "REST_Map not loaded\n"); exit(2); }

$lat = isset($argv[1]) ? (float)$argv[1] : 50.0721829;
$lng = isset($argv[2]) ? (float)$argv[2] : 14.4063037;
$postTypes = isset($argv[3]) ? (string)$argv[3] : 'poi';

$rest = \DB\REST_Map::get_instance();
$req = new \WP_REST_Request('GET', '/db/v1/map');
$req->set_param('lat', $lat);
$req->set_param('lng', $lng);
$req->set_param('radius', 0.1);
$req->set_param('post_types', $postTypes);
$req->set_param('limit', 10);
$res = $rest->handle_map($req);

if ($res instanceof \WP_Error) {
    echo json_encode(['error' => $res->get_error_message()], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
    exit(3);
}

echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;


