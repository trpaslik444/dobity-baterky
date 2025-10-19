<?php
/**
 * Charging Enrichment Service - Google primární, Mapy.com fallback
 * Strategie: Google (fotky + detaily) → Mapy.com (základní info) → Street View (fallback fotky)
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Db_Charging_Enrichment_Service {

    private $googleDiscovery;
    private $mapyService;

    public function __construct() {
        $this->googleDiscovery = new \DB\POI_Discovery();
        $this->mapyService = new Db_Charging_Service();
    }

    /**
     * Obohacení nabíjecí stanice - Google primární, Mapy.com fallback
     */
    public function enrichChargingStation(int $stationId, array $context = []): ?array {
        $station = get_post($stationId);
        if (!$station || $station->post_type !== 'charging_location') {
            return null;
        }

        $lat = (float) get_post_meta($stationId, '_db_lat', true);
        $lng = (float) get_post_meta($stationId, '_db_lng', true);
        $title = $station->post_title;

        if ($lat == 0 && $lng == 0) {
            return null;
        }

        // 1. PRIMÁRNÍ: Google Places API (kvůli fotkám a detailům)
        $googleData = $this->tryGoogleEnrichment($stationId, $lat, $lng, $title, $context);
        if ($googleData) {
            return [
                'service' => 'google',
                'data' => $googleData,
                'fetched_at' => current_time('mysql'),
                'cache_ttl' => 30 * DAY_IN_SECONDS,
                'source' => 'google_places_api'
            ];
        }

        // 2. FALLBACK: Mapy.com API (základní informace)
        $mapyData = $this->tryMapyEnrichment($lat, $lng, $title, $context);
        if ($mapyData) {
            return [
                'service' => 'mapy',
                'data' => $mapyData,
                'fetched_at' => current_time('mysql'),
                'cache_ttl' => 30 * DAY_IN_SECONDS,
                'source' => 'mapy_com_api'
            ];
        }

        return null;
    }

    /**
     * Zkusit Google Places API enrichment
     */
    private function tryGoogleEnrichment(int $stationId, float $lat, float $lng, string $title, array $context): ?array {
        try {
            // Zkusit najít Google Place ID
            $placeId = $this->googleDiscovery->discoverGooglePlaceId($title, $lat, $lng);
            
            if (!$placeId) {
                // Pokud nenajdeme Place ID, zkusit vyhledání v okolí
                $nearbySearch = $this->searchGoogleNearby($lat, $lng, $title);
                if ($nearbySearch) {
                    $placeId = $nearbySearch['place_id'] ?? null;
                }
            }

            if (!$placeId) {
                return null;
            }

            // Získat detaily z Google Places
            $details = $this->getGooglePlaceDetails($placeId);
            if (!$details) {
                return null;
            }

            // Obohacení o fotky
            $photos = $this->enrichWithGooglePhotos($details);
            
            // Obohacení o Street View (fallback pro fotky)
            $streetView = $this->getStreetViewUrl($lat, $lng);

            return [
                'place_id' => $placeId,
                'name' => $details['name'] ?? $title,
                'address' => $details['formatted_address'] ?? '',
                'coords' => ['lat' => $lat, 'lng' => $lng],
                'rating' => $details['rating'] ?? null,
                'user_ratings_total' => $details['user_ratings_total'] ?? null,
                'phone' => $details['formatted_phone_number'] ?? null,
                'website' => $details['website'] ?? null,
                'opening_hours' => $details['opening_hours'] ?? null,
                'photos' => $photos,
                'street_view' => $streetView,
                'types' => $details['types'] ?? [],
                'raw_data' => $details
            ];

        } catch (\Exception $e) {
            error_log('[Charging Enrichment] Google API error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Zkusit Mapy.com API enrichment
     */
    private function tryMapyEnrichment(float $lat, float $lng, string $title, array $context): ?array {
        try {
            // Najít nejbližší stanice v Mapy.com
            $nearbyStations = $this->mapyService->findChargingStations($lat, $lng, 500, 5);
            
            if (empty($nearbyStations)) {
                return null;
            }

            // Najít nejbližší shodu
            $bestMatch = null;
            $bestDistance = PHP_FLOAT_MAX;

            foreach ($nearbyStations as $station) {
                $distance = $station['distance_m'] ?? PHP_FLOAT_MAX;
                if ($distance < $bestDistance && $distance <= 200) { // Max 200m vzdálenost
                    $bestDistance = $distance;
                    $bestMatch = $station;
                }
            }

            if (!$bestMatch) {
                return null;
            }

            // Street View jako fallback pro fotky
            $streetView = $this->getStreetViewUrl($lat, $lng);

            return [
                'name' => $bestMatch['name'],
                'address' => $bestMatch['address'],
                'coords' => $bestMatch['coords'],
                'type' => $bestMatch['label'],
                'distance_m' => $bestMatch['distance_m'],
                'charging_type' => $bestMatch['charging_type'],
                'deep_links' => $bestMatch['deep_links'],
                'street_view' => $streetView,
                'photos' => [], // Mapy.com nemá fotky
                'raw_data' => $bestMatch['raw']
            ];

        } catch (\Exception $e) {
            error_log('[Charging Enrichment] Mapy.com API error: ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Vyhledání v Google Places v okolí
     */
    private function searchGoogleNearby(float $lat, float $lng, string $query): ?array {
        $apiKey = get_option('db_google_api_key');
        if (empty($apiKey)) {
            return null;
        }

        $params = [
            'location' => $lat . ',' . $lng,
            'radius' => 1000,
            'keyword' => $query,
            'type' => 'gas_station', // Nabíjecí stanice jsou často u benzinek
            'key' => $apiKey
        ];

        $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query($params);
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        if (isset($data['results']) && !empty($data['results'])) {
            return $data['results'][0]; // Vrátit první výsledek
        }

        return null;
    }

    /**
     * Získání Google Place Details
     */
    private function getGooglePlaceDetails(string $placeId): ?array {
        $apiKey = get_option('db_google_api_key');
        if (empty($apiKey)) {
            return null;
        }

        $params = [
            'place_id' => $placeId,
            'fields' => 'name,formatted_address,rating,user_ratings_total,formatted_phone_number,website,opening_hours,photos,types',
            'key' => $apiKey
        ];

        $url = 'https://maps.googleapis.com/maps/api/place/details/json?' . http_build_query($params);
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return null;
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        return $data['result'] ?? null;
    }

    /**
     * Obohacení o Google fotky
     */
    private function enrichWithGooglePhotos(array $placeDetails): array {
        $photos = [];
        
        if (isset($placeDetails['photos']) && is_array($placeDetails['photos'])) {
            $apiKey = get_option('db_google_api_key');
            
            foreach (array_slice($placeDetails['photos'], 0, 5) as $photo) { // Max 5 fotek
                $photoRef = $photo['photo_reference'] ?? '';
                if ($photoRef) {
                    $photos[] = [
                        'reference' => $photoRef,
                        'url' => 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query([
                            'maxwidth' => 800,
                            'photoreference' => $photoRef,
                            'key' => $apiKey
                        ]),
                        'thumbnail_url' => 'https://maps.googleapis.com/maps/api/place/photo?' . http_build_query([
                            'maxwidth' => 300,
                            'photoreference' => $photoRef,
                            'key' => $apiKey
                        ])
                    ];
                }
            }
        }

        return $photos;
    }

    /**
     * Generování Street View URL jako fallback pro fotky
     */
    private function getStreetViewUrl(float $lat, float $lng): array {
        $apiKey = get_option('db_google_api_key');
        
        $params = [
            'location' => $lat . ',' . $lng,
            'size' => '800x600',
            'fov' => 90,
            'heading' => 0
        ];

        if ($apiKey) {
            $params['key'] = $apiKey;
        }

        return [
            'url' => 'https://maps.googleapis.com/maps/api/streetview?' . http_build_query($params),
            'thumbnail_url' => 'https://maps.googleapis.com/maps/api/streetview?' . http_build_query(array_merge($params, ['size' => '300x200'])),
            'embed_url' => 'https://www.google.com/maps/embed/v1/streetview?' . http_build_query($params)
        ];
    }

    /**
     * Vyhledání nabíjecích stanic v okolí s obohacením
     */
    public function findEnrichedChargingStations(float $lat, float $lng, int $radius_m = 3000, int $limit = 10): array {
        $results = [];

        // 1. Google Places - rychlé vyhledání
        $googleResults = $this->searchGoogleChargingStations($lat, $lng, $radius_m, $limit);
        foreach ($googleResults as $result) {
            $results[] = [
                'service' => 'google',
                'data' => $result,
                'fetched_at' => current_time('mysql'),
                'cache_ttl' => 30 * DAY_IN_SECONDS
            ];
        }

        // 2. Mapy.com - doplnění pokud máme méně než limit
        if (count($results) < $limit) {
            $mapyResults = $this->mapyService->findChargingStations($lat, $lng, $radius_m, $limit - count($results));
            foreach ($mapyResults as $result) {
                $results[] = [
                    'service' => 'mapy',
                    'data' => $result,
                    'fetched_at' => current_time('mysql'),
                    'cache_ttl' => 30 * DAY_IN_SECONDS
                ];
            }
        }

        return array_slice($results, 0, $limit);
    }

    /**
     * Vyhledání nabíjecích stanic přes Google Places
     */
    private function searchGoogleChargingStations(float $lat, float $lng, int $radius_m, int $limit): array {
        $apiKey = get_option('db_google_api_key');
        if (empty($apiKey)) {
            return [];
        }

        $params = [
            'location' => $lat . ',' . $lng,
            'radius' => $radius_m,
            'type' => 'gas_station',
            'keyword' => 'charging station electric vehicle EV',
            'key' => $apiKey
        ];

        $url = 'https://maps.googleapis.com/maps/api/place/nearbysearch/json?' . http_build_query($params);
        
        $response = wp_remote_get($url, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return [];
        }

        $data = json_decode(wp_remote_retrieve_body($response), true);
        $results = [];

        if (isset($data['results']) && is_array($data['results'])) {
            foreach (array_slice($data['results'], 0, $limit) as $place) {
                $results[] = [
                    'place_id' => $place['place_id'],
                    'name' => $place['name'],
                    'address' => $place['vicinity'],
                    'coords' => [
                        'lat' => $place['geometry']['location']['lat'],
                        'lng' => $place['geometry']['location']['lng']
                    ],
                    'rating' => $place['rating'] ?? null,
                    'user_ratings_total' => $place['user_ratings_total'] ?? null,
                    'types' => $place['types'] ?? [],
                    'photos' => $place['photos'] ?? [],
                    'raw_data' => $place
                ];
            }
        }

        return $results;
    }
}
