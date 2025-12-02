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
        
        // Wikidata vyžaduje POST request s query v body
        $response = wp_remote_post($this->api_url, array(
            'timeout' => 15,
            'headers' => array(
                'Accept' => 'application/sparql-results+json',
                'Content-Type' => 'application/x-www-form-urlencoded',
                'User-Agent' => 'DobityBaterky/1.0 (https://dobitybaterky.cz)',
            ),
            'body' => http_build_query(array(
                'query' => $query,
                'format' => 'json',
            )),
        ));
        
        if (is_wp_error($response)) {
            error_log('[Wikidata Provider] Error: ' . $response->get_error_message());
            return array();
        }
        
        $status_code = wp_remote_retrieve_response_code($response);
        if ($status_code !== 200) {
            error_log('[Wikidata Provider] HTTP ' . $status_code);
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
        // Validace vstupů
        $lat = (float) $lat;
        $lng = (float) $lng;
        $radius = (int) $radius;
        
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            throw new \InvalidArgumentException('Invalid GPS coordinates');
        }
        
        if ($radius < 100 || $radius > 50000) {
            throw new \InvalidArgumentException('Invalid radius (100-50000 meters)');
        }
        
        // Převést radius z metrů na kilometry pro Wikidata
        $radius_km = $radius / 1000;
        
        // Wikidata SPARQL query s geografickým filtrem
        // Používáme SERVICE wikibase:around pro geografické vyhledávání
        // Pro extrakci souřadnic používáme geof:latitude a geof:longitude
        $query = "
        SELECT ?item ?itemLabel ?location ?lat ?lon WHERE {
          SERVICE wikibase:around {
            ?item wdt:P625 ?location .
            bd:serviceParam wikibase:center \"Point($lng $lat)\"^^geo:wktLiteral .
            bd:serviceParam wikibase:radius \"$radius_km\" .
          }
          # Filtrovat jen relevantní typy míst (muzea, galerie, památky, výhledy, parky)
          {
            ?item wdt:P31/wdt:P279* ?type .
            VALUES ?type {
              wd:Q33506    # Museum
              wd:Q190598   # Art gallery
              wd:Q570116   # Tourist attraction
              wd:Q1075788  # Viewpoint
              wd:Q22698    # Park
              wd:Q12280    # Monument
              wd:Q47513    # Castle
              wd:Q16970    # Church
              wd:Q483551   # Cultural heritage
            }
          }
          # Extrahovat souřadnice pomocí geof:latitude a geof:longitude
          BIND(geof:latitude(?location) AS ?lat)
          BIND(geof:longitude(?location) AS ?lon)
          SERVICE wikibase:label { 
            bd:serviceParam wikibase:language \"cs,en\" . 
          }
        }
        LIMIT 100
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

