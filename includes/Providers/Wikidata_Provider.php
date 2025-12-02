<?php
/**
 * Wikidata Provider
 * 
 * Stahuje POIs z Wikidata (free zdroj)
 */

namespace DB\Providers;

if (!defined('ABSPATH')) {
    exit;
}

class Wikidata_Provider {
    
    private $api_url = 'https://query.wikidata.org/sparql';
    
    /**
     * Vyhledat POIs v okolí
     * 
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $radius Radius v metrech
     * @param array $categories Kategorie (whitelist)
     * @return array Array s POIs
     */
    public function search_around($lat, $lng, $radius, $categories = array()) {
        $pois = array();
        
        // Wikidata SPARQL query
        $query = $this->build_query($lat, $lng, $radius);
        
        $response = wp_remote_get($this->api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/sparql-results+json',
                'User-Agent' => 'DobityBaterky/1.0 (https://dobitybaterky.cz)',
            ),
            'body' => array(
                'query' => $query,
                'format' => 'json',
            ),
        ));
        
        if (is_wp_error($response)) {
            return array();
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['results']['bindings']) || !is_array($data['results']['bindings'])) {
            return array();
        }
        
        foreach ($data['results']['bindings'] as $binding) {
            $poi = $this->normalize_poi($binding);
            if ($poi) {
                $pois[] = $poi;
            }
        }
        
        return $pois;
    }
    
    /**
     * Vytvořit SPARQL query
     */
    private function build_query($lat, $lng, $radius) {
        // Převést radius z metrů na stupně (přibližně)
        $radius_deg = $radius / 111000; // 1 stupeň ≈ 111 km
        
        $lat_min = $lat - $radius_deg;
        $lat_max = $lat + $radius_deg;
        $lng_min = $lng - $radius_deg;
        $lng_max = $lng + $radius_deg;
        
        // Filtrovat pro relevantní typy míst
        $query = "
        SELECT ?item ?itemLabel ?lat ?lon ?type WHERE {
          ?item wdt:P31/wdt:P279* ?type .
          VALUES ?type {
            wd:Q33506  # Museum
            wd:Q22698  # Gallery
            wd:Q570116 # Tourist attraction
            wd:Q107420  # Viewpoint
            wd:Q47521   # Park
            wd:Q22698   # Art gallery
            wd:Q41176   # Building
            wd:Q570116  # Tourist attraction
          }
          ?item wdt:P625 ?coord .
          ?item wdt:P17 wd:Q213 .  # Czech Republic
          BIND(SUBSTR(STR(?coord), 32) AS ?coordStr)
          BIND(REPLACE(?coordStr, ' ', '') AS ?cleanCoord)
          BIND(SUBSTR(?cleanCoord, 1, STRLEN(?cleanCoord)-1) AS ?coordWithoutParen)
          BIND(STRBEFORE(?coordWithoutParen, ',') AS ?lonStr)
          BIND(STRAFTER(?coordWithoutParen, ',') AS ?latStr)
          BIND(xsd:float(?latStr) AS ?lat)
          BIND(xsd:float(?lonStr) AS ?lon)
          FILTER(?lat >= $lat_min && ?lat <= $lat_max)
          FILTER(?lon >= $lng_min && ?lon <= $lng_max)
          SERVICE wikibase:label { bd:serviceParam wikibase:language \"cs,en\" . }
        }
        LIMIT 50
        ";
        
        return $query;
    }
    
    /**
     * Normalizovat POI z Wikidata formátu
     */
    private function normalize_poi($binding) {
        if (!isset($binding['itemLabel']['value']) || 
            !isset($binding['lat']['value']) || 
            !isset($binding['lon']['value'])) {
            return null;
        }
        
        $name = $binding['itemLabel']['value'];
        $lat = (float) $binding['lat']['value'];
        $lng = (float) $binding['lon']['value'];
        
        // Wikidata nemá rating, takže použijeme null
        // Ale můžeme použít, pokud máme rating z jiného zdroje
        
        $category = $this->pick_category($binding);
        if (empty($category)) {
            return null;
        }
        
        // Extrahovat Wikidata ID
        $item_id = null;
        if (isset($binding['item']['value'])) {
            $item_uri = $binding['item']['value'];
            if (preg_match('/Q\d+/', $item_uri, $matches)) {
                $item_id = $matches[0];
            }
        }
        
        return array(
            'name' => sanitize_text_field($name),
            'lat' => $lat,
            'lon' => $lng,
            'category' => $category,
            'rating' => null, // Wikidata nemá rating
            'rating_source' => null,
            'source' => 'wikidata',
            'source_id' => $item_id,
            'raw' => $binding,
        );
    }
    
    /**
     * Vybrat kategorii z Wikidata dat
     */
    private function pick_category($binding) {
        $allowed = array('restaurant', 'cafe', 'bar', 'pub', 'fast_food', 'bakery', 'park', 'playground', 'garden', 
                        'sports_centre', 'swimming_pool', 'beach', 'tourist_attraction', 'viewpoint', 'museum', 
                        'gallery', 'zoo', 'aquarium', 'shopping_mall', 'supermarket', 'marketplace');
        
        if (isset($binding['type']['value'])) {
            $type_uri = $binding['type']['value'];
            
            // Mapovat Wikidata typy na kategorie
            if (strpos($type_uri, 'Q33506') !== false) { // Museum
                return 'museum';
            } elseif (strpos($type_uri, 'Q22698') !== false) { // Gallery
                return 'gallery';
            } elseif (strpos($type_uri, 'Q570116') !== false) { // Tourist attraction
                return 'tourist_attraction';
            } elseif (strpos($type_uri, 'Q107420') !== false) { // Viewpoint
                return 'viewpoint';
            } elseif (strpos($type_uri, 'Q47521') !== false) { // Park
                return 'park';
            }
        }
        
        return 'tourist_attraction'; // fallback
    }
}

