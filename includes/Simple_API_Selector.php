<?php
/**
 * Simple API Selector - Jednoduchý výběr mezi Mapy.com, Google a Tripadvisor API
 * Workflow: Mapy.com → Google → Tripadvisor (pouze pro Českou republiku)
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

class Simple_API_Selector {
    
    // Cache TTL podle API podmínek
    private const MAPY_CACHE_TTL = 0; // Mapy.com zakazuje cachování podle jejich ToS
    private const GOOGLE_CACHE_TTL = 30 * DAY_IN_SECONDS; // 30 dní
    private const TRIPADVISOR_CACHE_TTL = 24 * HOUR_IN_SECONDS; // 24 hodin
    
    private $mapyDiscovery;
    private $googleDiscovery;
    
    public function __construct() {
        $this->mapyDiscovery = new Mapy_Discovery();
        $this->googleDiscovery = new POI_Discovery();
    }
    
    /**
     * Získat obohacená data pro POI s automatickým výběrem služby
     * Workflow: Mapy.com (primární pro ČR) → Google → Tripadvisor (globálně)
     */
    public function enrichPOIData(int $postId, string $operation = 'search', array $context = []): ?array {
        // 1. Zkusit Mapy.com API (primární pro ČR)
        $mapyData = $this->tryMapyAPI($postId, $operation, $context);
        if ($mapyData) {
            $this->cacheData($postId, 'mapy', $mapyData, self::MAPY_CACHE_TTL);
            return $mapyData;
        }
        
        // 2. Zkusit Google API (sekundární)
        $googleData = $this->tryGoogleAPI($postId, $operation, $context);
        if ($googleData) {
            $this->cacheData($postId, 'google', $googleData, self::GOOGLE_CACHE_TTL);
            return $googleData;
        }
        
        // 3. Zkusit Tripadvisor API (terciární)
        $tripadvisorData = $this->tryTripadvisorAPI($postId, $operation, $context);
        if ($tripadvisorData) {
            $this->cacheData($postId, 'tripadvisor', $tripadvisorData, self::TRIPADVISOR_CACHE_TTL);
            return $tripadvisorData;
        }
        
        return null;
    }
    
    /**
     * Zkusit Mapy.com API (primární pro ČR, ale dostupné globálně)
     */
    private function tryMapyAPI(int $postId, string $operation, array $context): ?array {
        $apiKey = get_option('db_mapy_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        // Mapy.com zakazuje cachování - vždy voláme API
        // $cached = $this->getCachedData($postId, 'mapy');
        // if ($cached) {
        //     return $cached;
        // }
        
        try {
            $post = get_post($postId);
            if (!$post) {
                return null;
            }
            
            $title = $post->post_title;
            $lat = get_post_meta($postId, '_poi_lat', true);
            $lng = get_post_meta($postId, '_poi_lng', true);
            
            // Kontrola, zda je lokalita v ČR (preference pro Mapy.com)
            $isCzechRepublic = $this->isInCzechRepublic((float)$lat, (float)$lng);
            
            if ($operation === 'search') {
                $results = $this->mapyDiscovery->searchPlace($title, (float)$lat, (float)$lng, 5);
                if (!empty($results)) {
                    return [
                        'service' => 'mapy',
                        'operation' => $operation,
                        'data' => $results,
                        'fetched_at' => current_time('mysql'),
                        'cache_ttl' => self::MAPY_CACHE_TTL,
                        'region' => $isCzechRepublic ? 'czech_republic' : 'international'
                    ];
                }
            } elseif ($operation === 'place_details' && !empty($context['place_id'])) {
                $details = $this->mapyDiscovery->getPlaceDetails($context['place_id']);
                if ($details) {
                    return [
                        'service' => 'mapy',
                        'operation' => $operation,
                        'data' => $details,
                        'fetched_at' => current_time('mysql'),
                        'cache_ttl' => self::MAPY_CACHE_TTL,
                        'region' => $isCzechRepublic ? 'czech_republic' : 'international'
                    ];
                }
            } elseif ($operation === 'geocoding' && !empty($context['address'])) {
                $geocode = $this->mapyDiscovery->geocodeAddress($context['address']);
                if ($geocode) {
                    return [
                        'service' => 'mapy',
                        'operation' => $operation,
                        'data' => $geocode,
                        'fetched_at' => current_time('mysql'),
                        'cache_ttl' => self::MAPY_CACHE_TTL,
                        'region' => $isCzechRepublic ? 'czech_republic' : 'international'
                    ];
                }
            }
            
        } catch (\Exception $e) {
            error_log('[Simple API Selector] Mapy.com API error: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Zkusit Google API
     */
    private function tryGoogleAPI(int $postId, string $operation, array $context): ?array {
        $apiKey = get_option('db_google_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        // Zkontrolovat cache
        $cached = $this->getCachedData($postId, 'google');
        if ($cached) {
            return $cached;
        }
        
        try {
            $post = get_post($postId);
            if (!$post) {
                return null;
            }
            
            $title = $post->post_title;
            $lat = get_post_meta($postId, '_poi_lat', true);
            $lng = get_post_meta($postId, '_poi_lng', true);
            
            if ($operation === 'search') {
                $placeId = $this->googleDiscovery->discoverGooglePlaceId($title, (float)$lat, (float)$lng);
                if ($placeId) {
                    return [
                        'service' => 'google',
                        'operation' => $operation,
                        'data' => [['id' => $placeId, 'name' => $title, 'service' => 'google']],
                        'fetched_at' => current_time('mysql'),
                        'cache_ttl' => self::GOOGLE_CACHE_TTL
                    ];
                }
            } elseif ($operation === 'place_details' && !empty($context['place_id'])) {
                // Implementace Google Place Details
                $details = $this->fetchGooglePlaceDetails($context['place_id']);
                if ($details) {
                    return [
                        'service' => 'google',
                        'operation' => $operation,
                        'data' => $details,
                        'fetched_at' => current_time('mysql'),
                        'cache_ttl' => self::GOOGLE_CACHE_TTL
                    ];
                }
            } elseif ($operation === 'geocoding' && !empty($context['address'])) {
                $geocode = $this->geocodeGoogleAddress($context['address']);
                if ($geocode) {
                    return [
                        'service' => 'google',
                        'operation' => $operation,
                        'data' => $geocode,
                        'fetched_at' => current_time('mysql'),
                        'cache_ttl' => self::GOOGLE_CACHE_TTL
                    ];
                }
            }
            
        } catch (\Exception $e) {
            error_log('[Simple API Selector] Google API error: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Zkusit Tripadvisor API
     */
    private function tryTripadvisorAPI(int $postId, string $operation, array $context): ?array {
        $apiKey = get_option('db_tripadvisor_api_key');
        if (empty($apiKey)) {
            return null;
        }
        
        // Zkontrolovat cache
        $cached = $this->getCachedData($postId, 'tripadvisor');
        if ($cached) {
            return $cached;
        }
        
        try {
            // Implementace Tripadvisor API volání
            $data = $this->fetchTripadvisorData($operation, $context);
            if ($data) {
                return [
                    'service' => 'tripadvisor',
                    'operation' => $operation,
                    'data' => $data,
                    'fetched_at' => current_time('mysql'),
                    'cache_ttl' => self::TRIPADVISOR_CACHE_TTL
                ];
            }
            
        } catch (\Exception $e) {
            error_log('[Simple API Selector] Tripadvisor API error: ' . $e->getMessage());
        }
        
        return null;
    }
    
    /**
     * Získat Google Place Details
     */
    private function fetchGooglePlaceDetails(string $placeId): ?array {
        $apiKey = get_option('db_google_api_key');
        if (!$apiKey) {
            return null;
        }
        
        $url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $args = [
            'place_id' => $placeId,
            'fields' => 'name,formatted_address,geometry,types,rating,user_ratings_total,photos',
            'key' => $apiKey,
            'language' => 'cs'
        ];
        
        $url = add_query_arg($args, $url);
        $response = wp_remote_get($url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['result'])) {
            return null;
        }
        
        return $this->normalizeGooglePlaceDetails($data['result']);
    }
    
    /**
     * Geokódování Google adresy
     */
    private function geocodeGoogleAddress(string $address): ?array {
        $apiKey = get_option('db_google_api_key');
        if (!$apiKey) {
            return null;
        }
        
        $url = 'https://maps.googleapis.com/maps/api/geocode/json';
        $args = [
            'address' => $address,
            'key' => $apiKey,
            'language' => 'cs'
        ];
        
        $url = add_query_arg($args, $url);
        $response = wp_remote_get($url, ['timeout' => 30]);
        
        if (is_wp_error($response)) {
            return null;
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (!isset($data['results'][0])) {
            return null;
        }
        
        return $this->normalizeGoogleGeocodeResult($data['results'][0]);
    }
    
    /**
     * Získat Tripadvisor data
     */
    private function fetchTripadvisorData(string $operation, array $context): ?array {
        $apiKey = get_option('db_tripadvisor_api_key');
        if (!$apiKey) {
            return null;
        }
        
        // Implementace Tripadvisor API volání podle potřeby
        // Zde by byla implementace konkrétních volání Tripadvisor API
        
        return null;
    }
    
    /**
     * Normalizace Google Place Details
     */
    private function normalizeGooglePlaceDetails(array $data): array {
        return [
            'id' => $data['place_id'] ?? '',
            'name' => $data['name'] ?? '',
            'formatted_address' => $data['formatted_address'] ?? '',
            'lat' => (float) ($data['geometry']['location']['lat'] ?? 0),
            'lng' => (float) ($data['geometry']['location']['lng'] ?? 0),
            'types' => $data['types'] ?? [],
            'rating' => (float) ($data['rating'] ?? 0),
            'user_ratings_total' => (int) ($data['user_ratings_total'] ?? 0),
            'photos' => $this->normalizeGooglePhotos($data['photos'] ?? [])
        ];
    }
    
    /**
     * Normalizace Google Geocoding výsledku
     */
    private function normalizeGoogleGeocodeResult(array $data): array {
        return [
            'formatted_address' => $data['formatted_address'] ?? '',
            'lat' => (float) ($data['geometry']['location']['lat'] ?? 0),
            'lng' => (float) ($data['geometry']['location']['lng'] ?? 0),
            'components' => $data['address_components'] ?? [],
            'place_id' => $data['place_id'] ?? ''
        ];
    }
    
    /**
     * Normalizace Google fotek
     */
    private function normalizeGooglePhotos(array $photos): array {
        $normalized = [];
        
        foreach ($photos as $photo) {
            $normalized[] = [
                'photo_reference' => $photo['photo_reference'] ?? '',
                'width' => (int) ($photo['width'] ?? 0),
                'height' => (int) ($photo['height'] ?? 0)
            ];
        }
        
        return $normalized;
    }
    
    /**
     * Cache data
     */
    private function cacheData(int $postId, string $service, array $data, int $ttl): void {
        $cacheKey = '_poi_' . $service . '_cache';
        $cacheExpKey = '_poi_' . $service . '_cache_expires';
        
        update_post_meta($postId, $cacheKey, wp_json_encode($data));
        update_post_meta($postId, $cacheExpKey, time() + $ttl);
    }
    
    /**
     * Získat cached data
     */
    private function getCachedData(int $postId, string $service): ?array {
        $cacheKey = '_poi_' . $service . '_cache';
        $cacheExpKey = '_poi_' . $service . '_cache_expires';
        
        $cachedData = get_post_meta($postId, $cacheKey, true);
        $expires = (int) get_post_meta($postId, $cacheExpKey, true);
        
        if ($cachedData && $expires > time()) {
            $data = json_decode($cachedData, true);
            if (is_array($data)) {
                return $data;
            }
        }
        
        return null;
    }
    
    /**
     * Získat statistiky použití služeb
     */
    public function getServiceStats(): array {
        global $wpdb;
        
        $stats = [];
        $services = ['mapy', 'google', 'tripadvisor'];
        
        foreach ($services as $service) {
            $cacheKey = '_poi_' . $service . '_cache';
            $count = $wpdb->get_var($wpdb->prepare(
                "SELECT COUNT(*) FROM {$wpdb->postmeta} WHERE meta_key = %s AND meta_value != ''",
                $cacheKey
            ));
            
            $stats[$service] = [
                'cached_items' => (int) $count,
                'api_key_configured' => !empty(get_option('db_' . $service . '_api_key')),
                'cache_ttl' => $service === 'tripadvisor' ? '24 hours' : '30 days'
            ];
        }
        
        return $stats;
    }
    
    /**
     * Vyčistit cache pro službu
     */
    public function clearServiceCache(string $service): int {
        global $wpdb;
        
        $cacheKey = '_poi_' . $service . '_cache';
        $cacheExpKey = '_poi_' . $service . '_cache_expires';
        
        $deleted1 = $wpdb->delete($wpdb->postmeta, ['meta_key' => $cacheKey]);
        $deleted2 = $wpdb->delete($wpdb->postmeta, ['meta_key' => $cacheExpKey]);
        
        return ($deleted1 ?: 0) + ($deleted2 ?: 0);
    }
    
    /**
     * Kontrola, zda jsou souřadnice v České republice
     */
    private function isInCzechRepublic(float $lat, float $lng): bool {
        // Přibližné hranice ČR
        return $lat >= 48.5 && $lat <= 51.1 && $lng >= 12.0 && $lng <= 18.9;
    }
    
    /**
     * Test dostupnosti služby
     */
    public function testServiceAvailability(string $service): array {
        $apiKey = get_option('db_' . $service . '_api_key');
        
        if (empty($apiKey)) {
            return [
                'available' => false,
                'error' => 'API key not configured'
            ];
        }
        
        $startTime = microtime(true);
        
        try {
            if ($service === 'mapy') {
                // Test Mapy.com API
                $testResult = $this->mapyDiscovery->searchPlace('Praha', 50.0755, 14.4378, 1);
                $success = !empty($testResult);
            } elseif ($service === 'google') {
                // Test Google API
                $testResult = $this->googleDiscovery->discoverGooglePlaceId('Praha', 50.0755, 14.4378);
                $success = !empty($testResult);
            } elseif ($service === 'tripadvisor') {
                // Test Tripadvisor API
                $success = false; // Implementace podle potřeby
            } else {
                $success = false;
            }
            
            $responseTime = (microtime(true) - $startTime) * 1000;
            
            return [
                'available' => $success,
                'response_time' => round($responseTime, 2),
                'error' => $success ? null : 'API call failed'
            ];
            
        } catch (\Exception $e) {
            return [
                'available' => false,
                'error' => $e->getMessage(),
                'response_time' => (microtime(true) - $startTime) * 1000
            ];
        }
    }
}
