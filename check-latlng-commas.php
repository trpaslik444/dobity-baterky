<?php
/**
 * Rychlý diagnostický skript: ověření, zda jsou v lat/lng u charging_location čárky místo teček
 * Spuštění v prohlížeči: /wp-content/plugins/dobity-baterky/check-latlng-commas.php
 */

define('WP_USE_THEMES', false);
require_once __DIR__ . '/../../../wp-load.php';

global $wpdb;
header('Content-Type: text/plain; charset=utf-8');

echo "== Ověření formátu souřadnic u charging_location ==\n\n";

// 1) Souhrnné počty
$sqlCounts = $wpdb->prepare(
    "SELECT pm.meta_key, COUNT(*) AS cnt
     FROM {$wpdb->postmeta} pm
     JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE p.post_type = %s AND p.post_status = 'publish'
       AND pm.meta_key IN ('_db_lat','_db_lng')
       AND pm.meta_value LIKE '%,%'
     GROUP BY pm.meta_key",
    'charging_location'
);
$rowsCounts = $wpdb->get_results($sqlCounts, ARRAY_A);
echo "Počty řádků s čárkou v meta_value:\n";
if ($rowsCounts) {
    foreach ($rowsCounts as $r) {
        echo sprintf("  %s: %d\n", $r['meta_key'], (int)$r['cnt']);
    }
} else {
    echo "  (žádné záznamy s čárkou nenalezeny)\n";
}
echo "\n";

// 2) Ukázky řádků (max 10)
$sqlSamples = $wpdb->prepare(
    "SELECT p.ID, p.post_title, pm.meta_key, pm.meta_value
     FROM {$wpdb->postmeta} pm
     JOIN {$wpdb->posts} p ON p.ID = pm.post_id
     WHERE p.post_type = %s AND p.post_status = 'publish'
       AND pm.meta_key IN ('_db_lat','_db_lng')
       AND pm.meta_value LIKE '%,%'
     ORDER BY p.ID DESC
     LIMIT 10",
    'charging_location'
);
$rowsSamples = $wpdb->get_results($sqlSamples, ARRAY_A);
echo "Ukázky s čárkou (max 10):\n";
if ($rowsSamples) {
    foreach ($rowsSamples as $r) {
        echo sprintf("  ID=%d | %s | %s=%s\n", (int)$r['ID'], $r['post_title'], $r['meta_key'], $r['meta_value']);
    }
} else {
    echo "  (nic)\n";
}
echo "\n";

// 3) Kontrola konkrétní stanice dle uniq_key
$uniq = 'prazska_energetika_a_s|a11d998a7a373f2311893eca19896bfa';
$sqlStation = $wpdb->prepare(
    "SELECT p.ID, p.post_title,
            lat.meta_value AS lat_raw, lng.meta_value AS lng_raw
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key = '_db_lat'
     LEFT JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key = '_db_lng'
     JOIN {$wpdb->postmeta} uniq ON uniq.post_id = p.ID AND uniq.meta_key = '_evh_uniq_key'
     WHERE p.post_type = %s AND p.post_status IN ('publish','draft','pending')
       AND uniq.meta_value = %s
     LIMIT 1",
    'charging_location',
    $uniq
);
$station = $wpdb->get_row($sqlStation, ARRAY_A);
echo "Kontrola stanice (uniq_key={$uniq}):\n";
if ($station) {
    $status = get_post_status((int)$station['ID']);
    echo sprintf(
        "  ID=%d | %s\n  status=%s\n  _db_lat raw='%s' | _db_lng raw='%s'\n",
        (int)$station['ID'],
        $station['post_title'],
        $status ?: 'unknown',
        (string)$station['lat_raw'],
        (string)$station['lng_raw']
    );
} else {
    echo "  (stanice nenalezena)\n";
}
echo "\n";

// 4) Ověření radius dotazu kolem stanice (±0.5° bbox) přes WP_Query
if ($station && is_numeric(str_replace(',', '.', (string)$station['lat_raw'])) && is_numeric(str_replace(',', '.', (string)$station['lng_raw']))) {
    $latF = (float) str_replace(',', '.', (string)$station['lat_raw']);
    $lngF = (float) str_replace(',', '.', (string)$station['lng_raw']);
    $minLa = $latF - 0.5; $maxLa = $latF + 0.5;
    $minLo = $lngF - 0.5; $maxLo = $lngF + 0.5;

    echo "WP_Query radius test (±0.5°):\n";
    $q = new WP_Query([
        'post_type' => 'charging_location',
        'post_status' => 'publish',
        'posts_per_page' => -1,
        'meta_query' => [
            'relation' => 'AND',
            [ 'key' => '_db_lat', 'value' => [$minLa, $maxLa], 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ],
            [ 'key' => '_db_lng', 'value' => [$minLo, $maxLo], 'type' => 'NUMERIC', 'compare' => 'BETWEEN' ],
        ],
    ]);
    $foundIds = wp_list_pluck($q->posts, 'ID');
    $contains = ($station && in_array((int)$station['ID'], array_map('intval', $foundIds), true));
    echo "  Nalezených v bbox: " . (int)$q->post_count . "\n";
    echo "  Obsahuje Senohraby: " . ($contains ? 'ANO' : 'NE') . "\n\n";
}

// 4c) Přímý SQL dotaz s CAST na DECIMAL (kopie REST logiky BBOX filtru)
if (isset($latF, $lngF)) {
    $sqlDirect = $wpdb->prepare(
        "SELECT p.ID
         FROM {$wpdb->posts} p
         JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key = '_db_lat'
         JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key = '_db_lng'
         WHERE p.post_type = %s AND p.post_status = 'publish'
           AND CAST(lat.meta_value AS DECIMAL(10,8)) BETWEEN %f AND %f
           AND CAST(lng.meta_value AS DECIMAL(11,8)) BETWEEN %f AND %f",
        'charging_location', $minLa, $maxLa, $minLo, $maxLo
    );
    $ids = $wpdb->get_col($sqlDirect);
    $containsDirect = in_array((int)$station['ID'], array_map('intval', (array)$ids), true);
    echo "SQL CAST DECIMAL test:\n";
    echo "  Nalezených v bbox: " . count((array)$ids) . "\n";
    echo "  Obsahuje Senohraby: " . ($containsDirect ? 'ANO' : 'NE') . "\n\n";
}

// 4b) Zjistit všechny posty se stejným uniq_key (napříč statusy)
$sqlAllByUniq = $wpdb->prepare(
    "SELECT p.ID, p.post_status, p.post_title,
            lat.meta_value AS lat, lng.meta_value AS lng
     FROM {$wpdb->posts} p
     LEFT JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key = '_db_lat'
     LEFT JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key = '_db_lng'
     JOIN {$wpdb->postmeta} uniq ON uniq.post_id = p.ID AND uniq.meta_key = '_evh_uniq_key'
     WHERE p.post_type = %s AND uniq.meta_value = %s
     ORDER BY FIELD(p.post_status,'publish','pending','draft','trash') ASC, p.ID DESC",
    'charging_location',
    $uniq
);
$rowsAllByUniq = $wpdb->get_results($sqlAllByUniq, ARRAY_A);
echo "Všechny záznamy s daným uniq_key (napříč statusy):\n";
if ($rowsAllByUniq) {
    foreach ($rowsAllByUniq as $r) {
        echo sprintf("  ID=%d | status=%s | %s | lat=%s | lng=%s\n",
            (int)$r['ID'], $r['post_status'], $r['post_title'], $r['lat'], $r['lng']);
    }
} else {
    echo "  (nic)\n";
}
echo "\n";

// 5) Shrnutí
echo "Hotovo. Pokud jsou nenulové počty u _db_lat/_db_lng s čárkami, hypotéza je potvrzena.\n";


