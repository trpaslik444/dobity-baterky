<?php
// Lokální runner pro POI discovery

// Pokusně dohledat wp-load.php výše v adresářové struktuře
$candidates = [
    dirname(__DIR__, 4) . '/wp-load.php', // .../app/public/wp-load.php
    dirname(__DIR__, 3) . '/wp-load.php', // .../app/public/wp-content/wp-load.php (většinou neexistuje)
    dirname(__DIR__, 5) . '/wp-load.php', // o úroveň výš, kdyby byla jiná struktura
];
$wpLoad = null;
foreach ($candidates as $cand) {
    if (file_exists($cand)) { $wpLoad = $cand; break; }
}
if ($wpLoad === null) {
    fwrite(STDERR, "wp-load.php not found\n");
    exit(1);
}
require_once $wpLoad;

// Parametry
$limit = 5;
$save = true;
$withTa = true;
foreach ($argv as $arg) {
    if (preg_match('/^--limit=(\d+)$/', $arg, $m)) { $limit = (int)$m[1]; }
    if ($arg === '--no-save') { $save = false; }
    if ($arg === '--no-ta') { $withTa = false; }
}

try {
    $svc = new \DB\POI_Discovery();
    $res = $svc->discoverBatch($limit, $save, $withTa);
    echo json_encode($res, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE), PHP_EOL;
} catch (Throwable $e) {
    fwrite(STDERR, 'Error: ' . $e->getMessage() . "\n");
    exit(2);
}


