<?php
/**
 * Standalone testovací skript pro Wikidata Provider
 * 
 * Použití: php scripts/test-wikidata-standalone.php
 * 
 * Nebo přes webový prohlížeč: /wp-content/plugins/dobity-baterky/scripts/test-wikidata-standalone.php
 */

// Načíst WordPress
$wp_load_paths = array(
    __DIR__ . '/../../../../wp-load.php',  // Z plugins/dobity-baterky/scripts/
    __DIR__ . '/../../../wp-load.php',     // Alternativní cesta
    __DIR__ . '/../../wp-load.php',        // Další alternativa
);

$wp_loaded = false;
foreach ($wp_load_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $wp_loaded = true;
        break;
    }
}

if (!$wp_loaded) {
    die("❌ Nelze najít wp-load.php. Spusťte skript z WordPress root adresáře nebo zadejte správnou cestu.\n");
}

// Načíst Provider třídy
require_once __DIR__ . '/../includes/Providers/Wikidata_Provider.php';

// Parametry
$limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 5;
$radius = isset($_GET['radius']) ? (int) $_GET['radius'] : 2000;

// HTML výstup pro webový prohlížeč
$is_cli = php_sapi_name() === 'cli';

if (!$is_cli) {
    header('Content-Type: text/html; charset=utf-8');
    echo "<!DOCTYPE html><html><head><meta charset='utf-8'><title>Wikidata POI Test</title>";
    echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}";
    echo ".section{background:white;padding:15px;margin:10px 0;border-radius:5px;box-shadow:0 2px 4px rgba(0,0,0,0.1);}";
    echo ".poi-item{padding:10px;margin:5px 0;background:#f9f9f9;border-left:3px solid #0073aa;}";
    echo ".success{color:#46b450;} .error{color:#dc3232;} .info{color:#0073aa;}";
    echo "h2{color:#23282d;border-bottom:2px solid #0073aa;padding-bottom:5px;}";
    echo "pre{background:#f0f0f0;padding:10px;border-radius:3px;overflow-x:auto;}</style></head><body>";
}

function log_output($message, $type = 'info') {
    global $is_cli;
    if ($is_cli) {
        echo $message . "\n";
    } else {
        $class = $type === 'success' ? 'success' : ($type === 'error' ? 'error' : 'info');
        echo "<div class='{$class}'>" . htmlspecialchars($message) . "</div>";
    }
}

function log_section($title) {
    global $is_cli;
    if ($is_cli) {
        echo "\n" . str_repeat("━", 60) . "\n";
        echo $title . "\n";
        echo str_repeat("━", 60) . "\n\n";
    } else {
        echo "<div class='section'><h2>" . htmlspecialchars($title) . "</h2>";
    }
}

function log_section_end() {
    global $is_cli;
    if (!$is_cli) {
        echo "</div>";
    }
}

log_section("🔍 Testování Wikidata Provider");
log_output("Limit nabíječek: {$limit}");
log_output("Radius: {$radius} metrů");
log_section_end();

// Najít nabíječky s GPS souřadnicemi
global $wpdb;

$charging_locations = $wpdb->get_results($wpdb->prepare("
    SELECT p.ID, p.post_title,
           pm_lat.meta_value AS lat,
           pm_lng.meta_value AS lng
    FROM {$wpdb->posts} p
    INNER JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_db_lat'
    INNER JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_db_lng'
    WHERE p.post_type = 'charging_location'
    AND p.post_status = 'publish'
    AND pm_lat.meta_value != ''
    AND pm_lng.meta_value != ''
    ORDER BY p.ID DESC
    LIMIT %d
", $limit));

if (empty($charging_locations)) {
    log_output("❌ Nenalezeny žádné nabíječky s GPS souřadnicemi", 'error');
    if (!$is_cli) {
        echo "</body></html>";
    }
    exit(1);
}

log_output("✅ Nalezeno " . count($charging_locations) . " nabíječek", 'success');

// Inicializovat Wikidata Provider
$wikidata = new \DB\Providers\Wikidata_Provider();

$total_pois = 0;
$total_unique = 0;
$categories_found = array();
$all_pois = array();
$errors = array();

foreach ($charging_locations as $location) {
    $lat = (float) $location->lat;
    $lng = (float) $location->lng;
    
    log_section("📍 Nabíječka: #{$location->ID} - {$location->post_title}");
    log_output("GPS: {$lat}, {$lng}");
    
    // Zavolat Wikidata Provider
    $allowed_categories = array('museum', 'gallery', 'tourist_attraction', 'viewpoint', 'park');
    
    log_output("🔄 Stahování POIs z Wikidata...");
    $start_time = microtime(true);
    
    try {
        $pois = $wikidata->search_around($lat, $lng, $radius, $allowed_categories);
        $duration = round((microtime(true) - $start_time) * 1000, 2);
        
        log_output("✅ Nalezeno " . count($pois) . " POIs (trvalo {$duration}ms)", 'success');
        
        if (!empty($pois)) {
            if (!$is_cli) {
                echo "<div class='poi-item'><strong>📋 Seznam POIs:</strong><ul>";
            } else {
                log_output("📋 Seznam POIs:");
            }
            
            foreach ($pois as $index => $poi) {
                $category = isset($poi['category']) ? $poi['category'] : 'N/A';
                $source_id = isset($poi['source_id']) ? $poi['source_id'] : 'N/A';
                
                $poi_info = sprintf(
                    "%d. %s | GPS: %.6f, %.6f | Kategorie: %s | Wikidata ID: %s",
                    $index + 1,
                    $poi['name'],
                    $poi['lat'],
                    $poi['lon'],
                    $category,
                    $source_id
                );
                
                if ($is_cli) {
                    log_output("   " . $poi_info);
                } else {
                    echo "<li>" . htmlspecialchars($poi_info) . "</li>";
                }
                
                // Statistiky
                if (!isset($categories_found[$category])) {
                    $categories_found[$category] = 0;
                }
                $categories_found[$category]++;
                
                // Unikátní POIs (podle source_id)
                $poi_key = $poi['source'] . ':' . ($poi['source_id'] ?? 'unknown');
                if (!isset($all_pois[$poi_key])) {
                    $all_pois[$poi_key] = $poi;
                    $total_unique++;
                }
            }
            
            if (!$is_cli) {
                echo "</ul></div>";
            }
        } else {
            log_output("⚠️  Žádné POIs nenalezeny");
        }
    } catch (\Exception $e) {
        $error_msg = "❌ Chyba při stahování POIs: " . $e->getMessage();
        log_output($error_msg, 'error');
        $errors[] = array(
            'location' => $location->post_title,
            'error' => $e->getMessage()
        );
    }
    
    log_section_end();
    
    $total_pois += isset($pois) ? count($pois) : 0;
    
    // Malá pauza mezi requesty (rate limiting)
    if (count($charging_locations) > 1) {
        sleep(1);
    }
}

// Shrnutí
log_section("📊 SHRNUTÍ");
log_output("Celkem testovaných nabíječek: " . count($charging_locations));
log_output("Celkem nalezených POIs: {$total_pois}");
log_output("Unikátních POIs: {$total_unique}");

if (!empty($categories_found)) {
    log_output("");
    log_output("Kategorie POIs:");
    arsort($categories_found);
    foreach ($categories_found as $category => $count) {
        log_output("  - {$category}: {$count}");
    }
}

if (!empty($errors)) {
    log_output("");
    log_output("❌ Chyby:", 'error');
    foreach ($errors as $error) {
        log_output("  - {$error['location']}: {$error['error']}", 'error');
    }
}

// Ukázka dat jednoho POI
if (!empty($all_pois)) {
    log_section("📝 Ukázka dat POI");
    $sample_poi = reset($all_pois);
    log_output("Název: " . $sample_poi['name']);
    log_output("GPS: " . $sample_poi['lat'] . ", " . $sample_poi['lon']);
    log_output("Kategorie: " . ($sample_poi['category'] ?? 'N/A'));
    log_output("Rating: " . ($sample_poi['rating'] ?? 'N/A'));
    log_output("Rating source: " . ($sample_poi['rating_source'] ?? 'N/A'));
    log_output("Source: " . ($sample_poi['source'] ?? 'N/A'));
    log_output("Source ID: " . ($sample_poi['source_id'] ?? 'N/A'));
    
    log_output("");
    log_output("📋 Kompletní struktura POI:");
    if ($is_cli) {
        echo json_encode($sample_poi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
    } else {
        echo "<pre>" . htmlspecialchars(json_encode($sample_poi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE)) . "</pre>";
    }
    log_section_end();
}

log_section("✅ Test dokončen!");
log_section_end();

if (!$is_cli) {
    echo "<p><a href='?limit=" . ($limit + 5) . "&radius={$radius}'>Testovat více nabíječek (+5)</a> | ";
    echo "<a href='?limit={$limit}&radius=" . ($radius + 1000) . "'>Zvětšit radius (+1km)</a></p>";
    echo "</body></html>";
}

