<?php
/**
 * Mapy.com API Discovery - Vyhledávání a obohacování dat pomocí Mapy.com API
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

class Mapy_Discovery {
    
    private const MAPY_GEOCODE_URL = 'https://api.mapy.com/v1/geocode';
    private const MAPY_ROUTING_MATRIX_URL = 'https://api.mapy.com/v1/routing/matrix';
    private const MAPY_ROUTING_URL = 'https://api.mapy.com/v1/routing';
    
    /**
     * Vyhledat místo pomocí textového dotazu (Geocoding API)
     */
    public function searchPlace(string $query, ?float $lat = null, ?float $lng = null, int $limit = 5): ?array {
        $apiKey = (string) get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        $params = [
            'query' => $query,
            'type' => 'poi',
            'lang' => 'cs',
            'limit' => $limit,
            'apikey' => $apiKey
        ];
        
        // Přidat preferNear pokud jsou souřadnice (POZOR: lon,lat pořadí!)
        if ($lat !== null && $lng !== null) {
            $params['preferNear'] = $lng . ',' . $lat;
            $params['preferNearPrecision'] = 3000; // 3km radius
        }
        
        $url = self::MAPY_GEOCODE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/Mapy-Discovery'
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Mapy Discovery] Request error: ' . $response->get_error_message());
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        
        if ($code !== 200) {
            error_log('[Mapy Discovery] HTTP error: ' . $code . ' - ' . $body);
            return null;
        }
        
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['items'])) {
            return null;
        }
        
        return $this->normalizeSearchResults($data['items'], $query, $lat, $lng);
    }
    
    /**
     * Získat detaily místa podle ID
     */
    public function getPlaceDetails(string $placeId): ?array {
        $apiKey = (string) get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        // Pro Mapy.com API detaily místa se získávají přes geocoding s konkrétním ID
        $params = [
            'query' => $placeId,
            'type' => 'poi',
            'lang' => 'cs',
            'limit' => 1,
            'apikey' => $apiKey
        ];
        
        $url = self::MAPY_GEOCODE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/Mapy-Discovery'
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Mapy Discovery] Place details error: ' . $response->get_error_message());
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[Mapy Discovery] Place details HTTP error: ' . $code);
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data) || empty($data['items'])) {
            return null;
        }
        
        return $this->normalizePlaceDetails($data['items'][0]);
    }
    
    /**
     * Geokódování adresy
     */
    public function geocodeAddress(string $address): ?array {
        $apiKey = (string) get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        $params = [
            'query' => $address,
            'lang' => 'cs',
            'apikey' => $apiKey
        ];
        
        $url = self::MAPY_GEOCODE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/Mapy-Discovery'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data) || empty($data['items'])) {
            return null;
        }
        
        return $this->normalizeGeocodeResult($data['items'][0]);
    }
    
    /**
     * Reverzní geokódování (souřadnice -> adresa)
     */
    public function reverseGeocode(float $lat, float $lng): ?array {
        $apiKey = (string) get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        $params = [
            'query' => $lng . ',' . $lat, // POZOR: lon,lat pořadí!
            'lang' => 'cs',
            'apikey' => $apiKey
        ];
        
        $url = self::MAPY_GEOCODE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/Mapy-Discovery'
            ]
        ]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data) || empty($data['items'])) {
            return null;
        }
        
        return $this->normalizeGeocodeResult($data['items'][0]);
    }
    
    /**
     * Matrix routing - vzdálenost z jednoho bodu k více cílům
     */
    public function getMatrixDistance(float $originLat, float $originLng, array $targets): ?array {
        $apiKey = (string) get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        $payload = [
            'starts' => [[(float)$originLng, (float)$originLat]], // POZOR: lon,lat pořadí!
            'ends' => array_map(function($target) {
                return [(float)$target[1], (float)$target[0]]; // [lat,lng] -> [lon,lat]
            }, $targets),
            'routeType' => 'foot-fast'
        ];
        
        $url = self::MAPY_ROUTING_MATRIX_URL . '?apikey=' . $apiKey;
        
        $response = wp_remote_post($url, [
            'headers' => [
                'Content-Type' => 'application/json',
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/Mapy-Discovery'
            ],
            'body' => wp_json_encode($payload),
            'timeout' => 15
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Mapy Discovery] Matrix error: ' . $response->get_error_message());
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[Mapy Discovery] Matrix HTTP error: ' . $code);
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!is_array($data)) {
            return null;
        }
        
        return $data;
    }
    
    /**
     * Vyhledat nabíjecí stanice v okolí
     */
    public function findChargingStationsNear(float $lat, float $lng, int $radiusM = 3000, int $limit = 20): ?array {
        $apiKey = (string) get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        $params = [
            'query' => 'charging station', // zkus i 'nabíjecí stanice'
            'type' => 'poi',
            'lang' => 'cs',
            'limit' => $limit,
            'preferNear' => $lng . ',' . $lat, // POZOR: lon,lat pořadí!
            'preferNearPrecision' => $radiusM,
            'apikey' => $apiKey
        ];
        
        $url = self::MAPY_GEOCODE_URL . '?' . http_build_query($params);
        
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'headers' => [
                'Accept' => 'application/json',
                'User-Agent' => 'DobityBaterky/Mapy-Discovery'
            ]
        ]);
        
        if (is_wp_error($response)) {
            error_log('[Mapy Discovery] Charging stations error: ' . $response->get_error_message());
            return null;
        }
        
        $code = wp_remote_retrieve_response_code($response);
        if ($code !== 200) {
            error_log('[Mapy Discovery] Charging stations HTTP error: ' . $code);
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!$data || !isset($data['items'])) {
            return null;
        }
        
        return $this->normalizeChargingStations($data['items'], $lat, $lng);
    }
    
    /**
     * Normalizace výsledků vyhledávání
     */
    private function normalizeSearchResults(array $items, string $query, ?float $lat, ?float $lng): array {
        $normalized = [];
        
        foreach ($items as $item) {
            $position = $item['position'] ?? [];
            $normalized[] = [
                'id' => $item['id'] ?? '',
                'name' => $item['name'] ?? '',
                'address' => $item['location'] ?? '',
                'lat' => (float) ($position['lat'] ?? 0),
                'lng' => (float) ($position['lon'] ?? 0),
                'types' => [$item['type'] ?? '', $item['label'] ?? ''],
                'rating' => (float) ($item['rating'] ?? 0),
                'user_ratings_total' => (int) ($item['user_ratings_total'] ?? 0),
                'zip' => $item['zip'] ?? '',
                'bbox' => $item['bbox'] ?? [],
                'regional_structure' => $item['regionalStructure'] ?? [],
                'score' => $this->calculateRelevanceScore($item, $query, $lat, $lng)
            ];
        }
        
        // Seřadit podle relevance
        usort($normalized, function($a, $b) {
            return $b['score'] <=> $a['score'];
        });
        
        return $normalized;
    }
    
    /**
     * Normalizace detailů místa
     */
    private function normalizePlaceDetails(array $item): array {
        return [
            'id' => $item['id'] ?? '',
            'name' => $item['name'] ?? '',
            'formatted_address' => $item['address'] ?? '',
            'lat' => (float) ($item['lat'] ?? 0),
            'lng' => (float) ($item['lng'] ?? 0),
            'types' => $item['types'] ?? [],
            'rating' => (float) ($item['rating'] ?? 0),
            'user_ratings_total' => (int) ($item['user_ratings_total'] ?? 0),
            'phone' => $item['phone'] ?? '',
            'website' => $item['website'] ?? '',
            'opening_hours' => $item['opening_hours'] ?? null,
            'photos' => $this->normalizePhotos($item['photos'] ?? []),
            'reviews' => $this->normalizeReviews($item['reviews'] ?? [])
        ];
    }
    
    /**
     * Normalizace geokódování výsledku
     */
    private function normalizeGeocodeResult(array $item): array {
        return [
            'formatted_address' => $item['address'] ?? '',
            'lat' => (float) ($item['lat'] ?? 0),
            'lng' => (float) ($item['lng'] ?? 0),
            'components' => $item['components'] ?? [],
            'place_id' => $item['id'] ?? ''
        ];
    }
    
    /**
     * Normalizace nabíjecích stanic
     */
    private function normalizeChargingStations(array $items, float $originLat, float $originLng): array {
        $normalized = [];
        
        foreach ($items as $item) {
            $position = $item['position'] ?? [];
            $itemLat = (float) ($position['lat'] ?? 0);
            $itemLng = (float) ($position['lon'] ?? 0);
            
            $normalized[] = [
                'id' => $item['id'] ?? '',
                'name' => $item['name'] ?? '',
                'address' => $item['location'] ?? '',
                'lat' => $itemLat,
                'lng' => $itemLng,
                'types' => [$item['type'] ?? '', $item['label'] ?? ''],
                'rating' => (float) ($item['rating'] ?? 0),
                'user_ratings_total' => (int) ($item['user_ratings_total'] ?? 0),
                'zip' => $item['zip'] ?? '',
                'bbox' => $item['bbox'] ?? [],
                'regional_structure' => $item['regionalStructure'] ?? [],
                'distance_m' => $this->haversineM($originLat, $originLng, $itemLat, $itemLng),
                'mapy_url' => $this->generateMapyUrl($itemLat, $itemLng, $item['name'])
            ];
        }
        
        // Seřadit podle vzdálenosti
        usort($normalized, function($a, $b) {
            return $a['distance_m'] <=> $b['distance_m'];
        });
        
        return $normalized;
    }
    
    /**
     * Normalizace fotek
     */
    private function normalizePhotos(array $photos): array {
        $normalized = [];
        
        foreach ($photos as $photo) {
            $normalized[] = [
                'url' => $photo['url'] ?? '',
                'width' => (int) ($photo['width'] ?? 0),
                'height' => (int) ($photo['height'] ?? 0),
                'attribution' => $photo['attribution'] ?? ''
            ];
        }
        
        return $normalized;
    }
    
    /**
     * Normalizace recenzí
     */
    private function normalizeReviews(array $reviews): array {
        $normalized = [];
        
        foreach ($reviews as $review) {
            $normalized[] = [
                'author_name' => $review['author_name'] ?? '',
                'rating' => (int) ($review['rating'] ?? 0),
                'text' => $review['text'] ?? '',
                'time' => (int) ($review['time'] ?? 0)
            ];
        }
        
        return $normalized;
    }
    
    /**
     * Výpočet relevance skóre
     */
    private function calculateRelevanceScore(array $item, string $query, ?float $lat, ?float $lng): float {
        $score = 0.0;
        
        // Název podobnost
        $name = (string) ($item['name'] ?? '');
        if ($query !== '' && $name !== '') {
            $score += similar_text(mb_strtolower($query), mb_strtolower($name));
        }
        
        // Hodnocení
        $rating = (float) ($item['rating'] ?? 0);
        $score += $rating * 10.0;
        
        // Vzdálenost (pokud máme souřadnice)
        if ($lat !== null && $lng !== null) {
            $position = $item['position'] ?? [];
            if (isset($position['lat']) && isset($position['lon'])) {
                $itemLat = (float) $position['lat'];
                $itemLng = (float) $position['lon'];
                $distance = $this->haversineM($lat, $lng, $itemLat, $itemLng);
                $score += max(0.0, 2000.0 - min(2000.0, $distance)) / 100.0;
            }
        }
        
        return $score;
    }
    
    /**
     * Generování Mapy.cz URL pro otevření v aplikaci/webu
     */
    private function generateMapyUrl(float $lat, float $lng, string $name = ''): string {
        $params = [
            'source' => 'coor',
            'id' => $lat . ',' . $lng
        ];
        
        if (!empty($name)) {
            $params['query'] = urlencode($name);
        }
        
        return 'https://mapy.cz/zakladni?' . http_build_query($params);
    }
    
    /**
     * Haversine vzdálenost v metrech
     */
    private function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): float {
        $earthRadius = 6371000; // metry
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLng/2) * sin($dLng/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }
}