<?php
/**
 * CLI importer for POIs from a CSV file without calling external APIs.
 * - Idempotent: skips rows that already have the same source hash or a nearby duplicate.
 * - No deletions/updates of existing posts.
 * - Designed for batched runs with offset/batch parameters.
 *
 * Usage examples:
 *   php scripts/import_pois_csv.php --file="/path/to/all_pois_unique-1.csv" --batch=500 --offset=0 --dry-run
 */

// Bootstrap WordPress
$wpLoad = dirname(__DIR__, 4) . '/wp-load.php';
if ( ! file_exists( $wpLoad ) ) {
    fwrite( STDERR, "Cannot locate wp-load.php at $wpLoad\n" );
    exit(1);
}
require $wpLoad;

if ( php_sapi_name() !== 'cli' ) {
    fwrite( STDERR, "This script must be run from CLI.\n" );
    exit(1);
}

// -------------------------------------------------------------------------
// Helpers
// -------------------------------------------------------------------------

function poi_import_haversine_m(float $lat1, float $lon1, float $lat2, float $lon2): float {
    $earth = 6371000.0;
    $dLat = deg2rad($lat2 - $lat1);
    $dLon = deg2rad($lon2 - $lon1);
    $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
    $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
    return $earth * $c;
}

function poi_import_normalize(string $v): string {
    $v = trim($v);
    return function_exists('mb_strtolower') ? mb_strtolower($v, 'UTF-8') : strtolower($v);
}

function poi_import_get_existing_by_hash(string $hash): bool {
    $existing = get_posts(array(
        'post_type'      => 'poi',
        'post_status'    => 'any',
        'meta_key'       => '_poi_source_hash',
        'meta_value'     => $hash,
        'fields'         => 'ids',
        'posts_per_page' => 1,
    ));
    return ! empty($existing);
}

function poi_import_nearby_duplicate(string $nameNorm, float $lat, float $lng, float $radiusM = 50.0): bool {
    $delta = 0.001; // ~111 m; refined by haversine below
    $latMin = $lat - $delta;
    $latMax = $lat + $delta;
    $lngMin = $lng - $delta;
    $lngMax = $lng + $delta;

    $candidates = get_posts(array(
        'post_type'      => 'poi',
        'post_status'    => 'any',
        'fields'         => 'ids',
        'posts_per_page' => 100,
        'meta_query'     => array(
            'relation' => 'AND',
            array(
                'key'     => '_poi_lat',
                'value'   => array($latMin, $latMax),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ),
            array(
                'key'     => '_poi_lng',
                'value'   => array($lngMin, $lngMax),
                'compare' => 'BETWEEN',
                'type'    => 'NUMERIC',
            ),
        ),
    ));

    foreach ( $candidates as $pid ) {
        $n = poi_import_normalize((string) get_the_title($pid));
        if ( $n !== $nameNorm ) {
            continue;
        }
        $clat = (float) get_post_meta($pid, '_poi_lat', true);
        $clng = (float) get_post_meta($pid, '_poi_lng', true);
        $dist = poi_import_haversine_m($lat, $lng, $clat, $clng);
        if ( $dist <= $radiusM ) {
            return true;
        }
    }
    return false;
}

function poi_import_assign_type(int $postId, string $type): void {
    if ( ! taxonomy_exists('poi_type') || $type === '' ) {
        if ( $type !== '' ) {
            update_post_meta($postId, '_poi_type_raw', $type);
        }
        return;
    }
    $slug = sanitize_title($type);
    $term = term_exists($slug, 'poi_type');
    if ( ! $term ) {
        $term = wp_insert_term($type, 'poi_type', array('slug' => $slug));
    }
    if ( ! is_wp_error($term) && isset($term['term_id']) ) {
        wp_set_post_terms($postId, array((int) $term['term_id']), 'poi_type', false);
    }
}

// -------------------------------------------------------------------------
// Arguments
// -------------------------------------------------------------------------

$options = getopt('', array('file:', 'batch::', 'offset::', 'dry-run'));
$file   = $options['file'] ?? null;
$batch  = isset($options['batch']) ? max(1, (int) $options['batch']) : 500;
$offset = isset($options['offset']) ? max(0, (int) $options['offset']) : 0;
$dryRun = array_key_exists('dry-run', $options);

if ( ! $file || ! is_readable($file) ) {
    fwrite( STDERR, "Missing or unreadable --file\n" );
    exit(1);
}

$fh = fopen($file, 'r');
if ( ! $fh ) {
    fwrite( STDERR, "Cannot open file $file\n" );
    exit(1);
}

// Read header (skip blank lines)
$headers = null;
while ( ! feof($fh) ) {
    $headers = fgetcsv($fh, 0, ',', '"', '\\');
    if ( $headers === false ) {
        break;
    }
    if ( $headers === array() || (count($headers) === 1 && $headers[0] === null) ) {
        continue;
    }
    break;
}
if ( ! is_array($headers) || empty($headers) || (count($headers) === 1 && $headers[0] === null) ) {
    fwrite( STDERR, "Empty or invalid CSV header\n" );
    exit(1);
}

$expected = array('Country','City','Name','Address','Latitude','Longitude','Rating','Type','PlaceSource','Website','Phone','PhotoURL','PhotoSuggestedFilename','PhotoLicense');

$headerMap = array();
foreach ( $expected as $col ) {
    $idx = array_search($col, $headers, true);
    if ( $idx === false ) {
        fwrite( STDERR, "Missing required column $col\n" );
        exit(1);
    }
    $headerMap[$col] = $idx;
}

// Skip to offset
if ( $offset > 0 ) {
    for ( $i = 0; $i < $offset; $i++ ) {
        if ( feof($fh) ) { break; }
        $skip = fgetcsv($fh, 0, ',', '"', '\\');
        if ( $skip === false || (count($skip) === 1 && $skip[0] === null) ) {
            $i--;
        }
    }
}

$processed = $created = $skippedHash = $skippedNearby = $errors = 0;
$rowIndex  = $offset;

while ( $processed < $batch && ! feof($fh) ) {
    $row = fgetcsv($fh, 0, ',', '"', '\\');
    if ( $row === false || $row === null || (count($row) === 1 && $row[0] === null) ) {
        break;
    }
    $rowIndex++;
    $processed++;

    $get = function(string $key) use ($row, $headerMap) {
        return $row[ $headerMap[$key] ] ?? '';
    };

    $name    = trim($get('Name'));
    $address = trim($get('Address'));
    $lat     = (float) $get('Latitude');
    $lng     = (float) $get('Longitude');
    $rating  = trim($get('Rating'));
    $city    = trim($get('City'));
    $country = trim($get('Country'));
    $type    = trim($get('Type'));
    $source  = trim($get('PlaceSource'));
    $website = trim($get('Website'));
    $phone   = trim($get('Phone'));

    // Basic validation
    if ( $name === '' || ($lat === 0.0 && $lng === 0.0) ) {
        $errors++;
        continue;
    }

    $nameNorm    = poi_import_normalize($name);
    $addrNorm    = poi_import_normalize($address);
    $latRounded  = number_format($lat, 6, '.', '');
    $lngRounded  = number_format($lng, 6, '.', '');
    $hash        = sha1($nameNorm . '|' . $addrNorm . '|' . $latRounded . '|' . $lngRounded);

    if ( poi_import_get_existing_by_hash($hash) ) {
        $skippedHash++;
        continue;
    }
    if ( poi_import_nearby_duplicate($nameNorm, $lat, $lng, 50.0) ) {
        $skippedNearby++;
        continue;
    }

    if ( $dryRun ) {
        $created++;
        continue;
    }

    $postId = wp_insert_post(array(
        'post_title'   => $name,
        'post_status'  => 'publish',
        'post_type'    => 'poi',
        'post_content' => '',
    ), true);

    if ( is_wp_error($postId) ) {
        $errors++;
        continue;
    }

    update_post_meta($postId, '_poi_lat', $lat);
    update_post_meta($postId, '_poi_lng', $lng);
    if ( $address !== '' ) update_post_meta($postId, '_poi_address', $address);
    if ( $rating !== '' ) update_post_meta($postId, '_poi_rating', $rating);
    if ( $city !== '' ) update_post_meta($postId, '_poi_city', $city);
    if ( $country !== '' ) update_post_meta($postId, '_poi_country', $country);
    if ( $source !== '' ) update_post_meta($postId, '_poi_source', $source);
    if ( $website !== '' ) update_post_meta($postId, '_poi_website', $website);
    if ( $phone !== '' ) update_post_meta($postId, '_poi_phone', $phone);
    update_post_meta($postId, '_poi_source_hash', $hash);
    update_post_meta($postId, '_poi_import_source', basename($file));
    update_post_meta($postId, '_poi_imported_at', current_time('mysql'));

    poi_import_assign_type($postId, $type);

    // Zařadit do nearby fronty (bez spuštění nearby přímo)
    if (file_exists(__DIR__ . '/../includes/Jobs/POI_Nearby_Queue_Manager.php')) {
        require_once __DIR__ . '/../includes/Jobs/POI_Nearby_Queue_Manager.php';
        if (class_exists('DB\Jobs\POI_Nearby_Queue_Manager')) {
            try {
                $queue_manager = new \DB\Jobs\POI_Nearby_Queue_Manager();
                $queue_manager->enqueue((int) $postId, 'poi');
            } catch (\Throwable $e) {
                // Tichá chyba - nechceme přerušit import
                error_log('[POI Import] Chyba při zařazování do nearby fronty: ' . $e->getMessage());
            }
        }
    }

    $created++;
}

fclose($fh);

$summary = array(
    'file'           => $file,
    'offset'         => $offset,
    'batch'          => $batch,
    'dry_run'        => $dryRun,
    'processed_rows' => $processed,
    'created'        => $created,
    'skipped_hash'   => $skippedHash,
    'skipped_nearby' => $skippedNearby,
    'errors'         => $errors,
);

echo json_encode($summary, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . PHP_EOL;
