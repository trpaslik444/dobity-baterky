<?php
/**
 * Jednoduchý test Wikidata Provider s konkrétními souřadnicemi
 * 
 * Použití: php scripts/test-wikidata-simple.php
 */

// Testovací souřadnice (Praha, Brno, Ostrava)
$test_locations = array(
    array('name' => 'Praha - centrum', 'lat' => 50.0755, 'lng' => 14.4378),
    array('name' => 'Brno - centrum', 'lat' => 49.1951, 'lng' => 16.6068),
    array('name' => 'Ostrava - centrum', 'lat' => 49.8209, 'lng' => 18.2625),
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

if ($wp_loaded) {
    // Načíst Provider třídy
    require_once __DIR__ . '/../includes/Providers/Wikidata_Provider.php';
    $wikidata = new \DB\Providers\Wikidata_Provider();
} else {
    // Fallback: testovat přímo Wikidata API bez WordPress
    echo "⚠️  WordPress není dostupný, testuji přímo Wikidata API\n\n";
    
    // Jednoduchý test Wikidata SPARQL query
    $test_lat = 50.0755;
    $test_lng = 14.4378;
    $radius_km = 2;
    
    $query = "
    SELECT ?item ?itemLabel ?lat ?lon WHERE {
      SERVICE wikibase:around {
        ?item wdt:P625 ?location .
        bd:serviceParam wikibase:center \"Point($test_lng $test_lat)\"^^geo:wktLiteral .
        bd:serviceParam wikibase:radius \"$radius_km\" .
      }
      {
        ?item wdt:P31/wdt:P279* ?type .
        VALUES ?type {
          wd:Q33506    # Museum
          wd:Q190598   # Art gallery
          wd:Q570116   # Tourist attraction
          wd:Q1075788  # Viewpoint
          wd:Q22698    # Park
        }
      }
      BIND(SUBSTR(STR(?location), 32) AS ?coordStr)
      BIND(REPLACE(?coordStr, ' ', '') AS ?cleanCoord)
      BIND(SUBSTR(?cleanCoord, 1, STRLEN(?cleanCoord)-1) AS ?coordWithoutParen)
      BIND(STRBEFORE(?coordWithoutParen, ',') AS ?lonStr)
      BIND(STRAFTER(?coordWithoutParen, ',') AS ?latStr)
      BIND(xsd:float(?latStr) AS ?lat)
      BIND(xsd:float(?lonStr) AS ?lon)
      SERVICE wikibase:label { 
        bd:serviceParam wikibase:language \"cs,en\" . 
      }
    }
    LIMIT 10
    ";
    
    $url = 'https://query.wikidata.org/sparql';
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query(array('query' => $query, 'format' => 'json')));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        'Accept: application/sparql-results+json',
        'Content-Type: application/x-www-form-urlencoded',
        'User-Agent: DobityBaterky-Test/1.0',
    ));
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    
    echo "🔍 Testování Wikidata API pro: Praha ({$test_lat}, {$test_lng})\n";
    echo "Radius: {$radius_km} km\n\n";
    
    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    
    if ($http_code === 200 && $response) {
        $data = json_decode($response, true);
        if (isset($data['results']['bindings'])) {
            echo "✅ Nalezeno " . count($data['results']['bindings']) . " POIs:\n\n";
            foreach ($data['results']['bindings'] as $index => $binding) {
                $name = $binding['itemLabel']['value'] ?? 'N/A';
                $lat = $binding['lat']['value'] ?? 'N/A';
                $lon = $binding['lon']['value'] ?? 'N/A';
                $item_uri = $binding['item']['value'] ?? '';
                $item_id = preg_match('/Q\d+/', $item_uri, $matches) ? $matches[0] : 'N/A';
                
                echo sprintf(
                    "%d. %s\n   GPS: %s, %s | Wikidata ID: %s\n\n",
                    $index + 1,
                    $name,
                    $lat,
                    $lon,
                    $item_id
                );
            }
        } else {
            echo "❌ Neplatná struktura odpovědi\n";
            echo "Response: " . substr($response, 0, 500) . "\n";
        }
    } else {
        echo "❌ Chyba HTTP {$http_code}\n";
        if ($response) {
            echo "Response: " . substr($response, 0, 500) . "\n";
        }
    }
    exit(0);
}

// Testovat s WordPress Provider třídou
$radius = 2000; // 2 km

echo "🔍 Testování Wikidata Provider\n";
echo "Radius: {$radius} metrů\n\n";

$total_pois = 0;
$categories_found = array();
$all_pois = array();

foreach ($test_locations as $location) {
    echo str_repeat("━", 60) . "\n";
    echo "📍 {$location['name']}\n";
    echo "   GPS: {$location['lat']}, {$location['lng']}\n\n";
    
    $allowed_categories = array('museum', 'gallery', 'tourist_attraction', 'viewpoint', 'park');
    
    echo "   🔄 Stahování POIs z Wikidata...\n";
    $start_time = microtime(true);
    
    try {
        $pois = $wikidata->search_around($location['lat'], $location['lng'], $radius, $allowed_categories);
        $duration = round((microtime(true) - $start_time) * 1000, 2);
        
        echo "   ✅ Nalezeno " . count($pois) . " POIs (trvalo {$duration}ms)\n\n";
        
        if (!empty($pois)) {
            echo "   📋 Seznam POIs:\n";
            foreach ($pois as $index => $poi) {
                $category = isset($poi['category']) ? $poi['category'] : 'N/A';
                $source_id = isset($poi['source_id']) ? $poi['source_id'] : 'N/A';
                
                echo sprintf(
                    "   %d. %s\n      📍 GPS: %.6f, %.6f | Kategorie: %s | Wikidata ID: %s\n",
                    $index + 1,
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
    }
    
    $total_pois += isset($pois) ? count($pois) : 0;
    
    // Pauza mezi requesty
    if (count($test_locations) > 1) {
        sleep(1);
    }
}

// Shrnutí
echo str_repeat("━", 60) . "\n";
echo "📊 SHRNUTÍ\n";
echo str_repeat("━", 60) . "\n";
echo "Celkem testovaných lokací: " . count($test_locations) . "\n";
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

// Ukázka dat
if (!empty($all_pois)) {
    $sample_poi = reset($all_pois);
    echo "📝 Ukázka dat POI:\n";
    echo "   Název: " . $sample_poi['name'] . "\n";
    echo "   GPS: " . $sample_poi['lat'] . ", " . $sample_poi['lon'] . "\n";
    echo "   Kategorie: " . ($sample_poi['category'] ?? 'N/A') . "\n";
    echo "   Source: " . ($sample_poi['source'] ?? 'N/A') . "\n";
    echo "   Source ID: " . ($sample_poi['source_id'] ?? 'N/A') . "\n\n";
    
    echo "📋 Kompletní struktura POI:\n";
    echo json_encode($sample_poi, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) . "\n";
}

echo "\n✅ Test dokončen!\n";

