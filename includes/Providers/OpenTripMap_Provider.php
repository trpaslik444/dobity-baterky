<?php
/**
 * OpenTripMap Provider
 * 
 * Stahuje POIs z OpenTripMap API (free zdroj)
 */

namespace DB\Providers;

if (!defined('ABSPATH')) {
    exit;
}

class OpenTripMap_Provider {
    
    private $api_key;
    private $api_url = 'https://api.opentripmap.io/0.1/en/places/';
    
    public function __construct() {
        $this->api_key = get_option('opentripmap_api_key', '');
    }
    
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
        if (empty($this->api_key)) {
            return array();
        }
        
        $pois = array();
        
        // OpenTripMap používá "kinds" místo kategorií
        $kinds = $this->map_categories_to_kinds($categories);
        
        foreach ($kinds as $kind) {
            $url = $this->api_url . 'radius';
            $args = array(
                'apikey' => $this->api_key,
                'radius' => $radius,
                'lon' => $lng,
                'lat' => $lat,
                'kinds' => $kind,
                'limit' => 50,
                'format' => 'json',
            );
            
            $url = add_query_arg($args, $url);
            
            $response = wp_remote_get($url, array(
                'timeout' => 10,
            ));
            
            if (is_wp_error($response)) {
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (!is_array($data)) {
                continue;
            }
            
            foreach ($data as $item) {
                $poi = $this->normalize_poi($item);
                if ($poi) {
                    $pois[] = $poi;
                }
            }
        }
        
        return $pois;
    }
    
    /**
     * Mapovat kategorie na OpenTripMap "kinds"
     */
    private function map_categories_to_kinds($categories) {
        $mapping = array(
            'restaurant' => 'restaurants',
            'cafe' => 'cafes',
            'bar' => 'bars',
            'pub' => 'pubs',
            'fast_food' => 'fast_food',
            'bakery' => 'bakeries',
            'park' => 'parks',
            'playground' => 'playgrounds',
            'garden' => 'gardens',
            'sports_centre' => 'sport',
            'swimming_pool' => 'sport',
            'beach' => 'beaches',
            'tourist_attraction' => 'interesting_places',
            'viewpoint' => 'viewpoints',
            'museum' => 'museums',
            'gallery' => 'galleries',
            'zoo' => 'zoos',
            'aquarium' => 'aquariums',
            'shopping_mall' => 'shops',
            'supermarket' => 'shops',
            'marketplace' => 'markets',
        );
        
        $kinds = array();
        foreach ($categories as $category) {
            if (isset($mapping[$category])) {
                $kinds[] = $mapping[$category];
            }
        }
        
        return array_unique($kinds);
    }
    
    /**
     * Normalizovat POI z OpenTripMap formátu
     */
    private function normalize_poi($item) {
        if (!isset($item['name']) || !isset($item['point']['lat']) || !isset($item['point']['lon'])) {
            return null;
        }
        
        $rating = null;
        if (isset($item['rate']) && is_numeric($item['rate'])) {
            $rating = (float) $item['rate'];
            // OpenTripMap rating je 1-10, převést na 1-5
            if ($rating > 5) {
                $rating = $rating / 2;
            }
        }
        
        // Filtrovat podle ratingu (min 4.0)
        $min_rating = 4.0;
        if ($rating !== null && $rating < $min_rating) {
            return null;
        }
        
        $category = $this->pick_category($item);
        if (empty($category)) {
            return null;
        }
        
        return array(
            'name' => sanitize_text_field($item['name']),
            'lat' => (float) $item['point']['lat'],
            'lon' => (float) $item['point']['lon'],
            'category' => $category,
            'rating' => $rating,
            'rating_source' => 'opentripmap',
            'address' => isset($item['address']) ? sanitize_text_field($item['address']) : null,
            'source' => 'opentripmap',
            'source_id' => isset($item['xid']) ? $item['xid'] : null,
            'raw' => $item,
        );
    }
    
    /**
     * Vybrat kategorii z OpenTripMap dat
     */
    private function pick_category($item) {
        $allowed = array('restaurant', 'cafe', 'bar', 'pub', 'fast_food', 'bakery', 'park', 'playground', 'garden', 
                        'sports_centre', 'swimming_pool', 'beach', 'tourist_attraction', 'viewpoint', 'museum', 
                        'gallery', 'zoo', 'aquarium', 'shopping_mall', 'supermarket', 'marketplace');
        
        if (isset($item['kinds'])) {
            $kinds = explode(',', $item['kinds']);
            $mapping = array(
                'restaurants' => 'restaurant',
                'cafes' => 'cafe',
                'bars' => 'bar',
                'pubs' => 'pub',
                'fast_food' => 'fast_food',
                'bakeries' => 'bakery',
                'parks' => 'park',
                'playgrounds' => 'playground',
                'gardens' => 'garden',
                'sport' => 'sports_centre',
                'beaches' => 'beach',
                'interesting_places' => 'tourist_attraction',
                'viewpoints' => 'viewpoint',
                'museums' => 'museum',
                'galleries' => 'gallery',
                'zoos' => 'zoo',
                'aquariums' => 'aquarium',
                'shops' => 'shopping_mall',
                'markets' => 'marketplace',
            );
            
            foreach ($kinds as $kind) {
                $kind = trim($kind);
                if (isset($mapping[$kind])) {
                    $category = $mapping[$kind];
                    if (in_array($category, $allowed)) {
                        return $category;
                    }
                }
            }
        }
        
        return 'tourist_attraction'; // fallback
    }
}

