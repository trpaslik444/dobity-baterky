<?php
/**
 * Test Wikidata Provider na konkrétních souřadnicích
 * 
 * Použití: php scripts/test-wikidata-coordinates.php
 */

// Testovací souřadnice
$test_coordinates = array(
    array('name' => 'Location 1', 'lat' => 49.9333900, 'lng' => 14.1843919),
    array('name' => 'Location 2', 'lat' => 49.9433411, 'lng' => 14.6045947),
    array('name' => 'Location 3', 'lat' => 49.9230239, 'lng' => 14.5762439),
    array('name' => 'Location 4', 'lat' => 49.8978919, 'lng' => 14.7136489),
    array('name' => 'Location 5', 'lat' => 49.7138500, 'lng' => 14.9122900),
);

// Načíst WordPress (pokud je dostupný)
$wp_load_paths = array(
    __DIR__ . '/../../../../wp-load.php',
    __DIR__ . '/../../../wp-load.php',
    __DIR__ . '/../../wp-load.php',
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
    die("❌ Nelze najít wp-load.php. Spusťte skript z WordPress root adresáře.\n");
}

// Načíst Provider třídy
require_once __DIR__ . '/../includes/Providers/Wikidata_Provider.php';

$radius = 2000; // 2 km

echo "🔍 Testování Wikidata Provider na konkrétních souřadnicích\n";
echo "Radius: {$radius} metrů\n";
echo str_repeat("=", 70) . "\n\n";

$total_pois = 0;
$categories_found = array();
$all_pois = array();
$errors = array();

foreach ($test_coordinates as $index => $location) {
    $lat = (float) $location['lat'];
    $lng = (float) $location['lng'];
    
    echo str_repeat("━", 70) . "\n";
    echo "📍 " . ($index + 1) . ". {$location['name']}\n";
    echo "   GPS: {$lat}, {$lng}\n\n";
    
    // Inicializovat Wikidata Provider
    $wikidata = new \DB\Providers\Wikidata_Provider();
    $allowed_categories = array('museum', 'gallery', 'tourist_attraction', 'viewpoint', 'park');
    
    echo "   🔄 Stahování POIs z Wikidata...\n";
    $start_time = microtime(true);
    
    try {
        $pois = $wikidata->search_around($lat, $lng, $radius, $allowed_categories);
        $duration = round((microtime(true) - $start_time) * 1000, 2);
        
        echo "   ✅ Nalezeno " . count($pois) . " POIs (trvalo {$duration}ms)\n\n";
        
        if (!empty($pois)) {
            echo "   📋 Seznam POIs:\n";
            foreach ($pois as $poi_index => $poi) {
                $category = isset($poi['category']) ? $poi['category'] : 'N/A';
                $source_id = isset($poi['source_id']) ? $poi['source_id'] : 'N/A';
                
                echo sprintf(
                    "   %d. %s\n      📍 GPS: %.6f, %.6f | Kategorie: %s | Wikidata ID: %s\n",
                    $poi_index + 1,
                    $poi['name'],
                    $poi['lat'],
                    $poi['lon'],
                    $category,
                    $source_id
                );
                
                // Statistiky
                if (!isset($categories_found[$category])) {
                    $categories_found[$category] = 0;
                }
                $categories_found[$category]++;
                
                // Unikátní POIs
                $poi_key = $poi['source'] . ':' . ($poi['source_id'] ?? 'unknown');
                if (!isset($all_pois[$poi_key])) {
                    $all_pois[$poi_key] = $poi;
                }
            }
            echo "\n";
        } else {
            echo "   ⚠️  Žádné POIs nenalezeny\n\n";
        }
    } catch (\Exception $e) {
        echo "   ❌ Chyba: " . $e->getMessage() . "\n\n";
        $errors[] = array(
            'location' => $location['name'],
            'error' => $e->getMessage()
        );
    }
    
    $total_pois += isset($pois) ? count($pois) : 0;
    
    // Pauza mezi requesty (rate limiting)
    if ($index < count($test_coordinates) - 1) {
        sleep(1);
    }
}

// Shrnutí
echo str_repeat("=", 70) . "\n";
echo "📊 SHRNUTÍ\n";
echo str_repeat("=", 70) . "\n";
echo "Celkem testovaných lokací: " . count($test_coordinates) . "\n";
echo "Celkem nalezených POIs: {$total_pois}\n";
echo "Unikátních POIs: " . count($all_pois) . "\n\n";

if (!empty($categories_found)) {
    echo "Kategorie POIs:\n";
    arsort($categories_found);
    foreach ($categories_found as $category => $count) {
        echo "  - {$category}: {$count}\n";
    }
    echo "\n";
}

if (!empty($errors)) {
    echo "❌ Chyby:\n";
    foreach ($errors as $error) {
        echo "  - {$error['location']}: {$error['error']}\n";
    }
    echo "\n";
}

// Ukázka dat
if (!empty($all_pois)) {
    echo str_repeat("━", 70) . "\n";
    echo "📝 Ukázka dat POI:\n";
    $sample_poi = reset($all_pois);
    echo "   Název: " . $sample_poi['name'] . "\n";
    echo "   GPS: " . $sample_poi['lat'] . ", " . $sample_poi['lon'] . "\n";
    echo "   Kategorie: " . ($sample_poi['category'] ?? 'N/A') . "\n";
    echo "   Source: " . ($sample_poi['source'] ?? 'N/A') . "\n";
    echo "   Source ID: " . ($sample_poi['source_id'] ?? 'N/A') . "\n\n";
    
    echo "📋 Kompletní struktura POI:\n";
    echo json_encode($sample_poi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n✅ Test dokončen!\n";

