<?php
declare(strict_types=1);

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Charging discovery service responsible for synchronising charging_location posts
 * with Google Places and Open Charge Map metadata. The implementation mirrors the
 * POI discovery system but is tailored for the charging post type and exposes
 * helper methods for queue workers, REST endpoints and on-demand enrichment.
 */
class Charging_Discovery {
    private const GOOGLE_TEXTSEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
    private const GOOGLE_DETAILS_URL    = 'https://maps.googleapis.com/maps/api/place/details/json';

    private const META_GOOGLE_ID          = '_charging_google_place_id';
    private const META_GOOGLE_CACHE       = '_charging_google_cache';
    private const META_GOOGLE_CACHE_EXP   = '_charging_google_cache_expires';
    private const META_LIVE_STATUS        = '_charging_live_status';
    private const META_LIVE_STATUS_EXP    = '_charging_live_status_expires';

    private const METADATA_TTL = 2592000; // 30 days
    private const LIVE_TTL_MIN = 60;      // 1 minute
    private const LIVE_TTL_MAX = 120;     // 2 minutes

    /**
     * Discover external IDs for a charging location.
     *
     * @param int  $postId   charging_location post ID.
     * @param bool $save     When true, discovered IDs and data are persisted.
     * @param bool $force    When true, bypass caches and refresh from providers.
     * @param bool $useGoogle Allow discovering Google Place ID.
     * @param bool $useOcm   Allow discovering Open Charge Map ID.
     * @return array<string,mixed>
     */
    public function discoverForCharging(int $postId, bool $save = false, bool $force = false, bool $useGoogle = true, bool $useOcm = false): array {
        $post = get_post($postId);
        if (!$post || $post->post_type !== 'charging_location') {
            return [
                'google' => null,
                'open_charge_map' => null,
                'error' => 'invalid_post',
            ];
        }

        $title = trim((string) $post->post_title);
        $lat = (float) get_post_meta($postId, '_db_lat', true);
        $lng = (float) get_post_meta($postId, '_db_lng', true);
        $hasCoords = ($lat !== 0.0 || $lng !== 0.0);

        $googlePlaceId = get_post_meta($postId, self::META_GOOGLE_ID, true);

        $discoveredGoogle = $googlePlaceId !== '' ? (string) $googlePlaceId : null;
        $discoveredOcm = null; // OCM disabled

        // Try to discover Google Place ID when missing or forced.
        // DŮLEŽITÉ: Použít Places_Enrichment_Service pro správu kvót a atomické operace
        if ($useGoogle && ($force || !$discoveredGoogle)) {
            $discoveredGoogle = $this->discoverGooglePlaceId($title, $hasCoords ? $lat : null, $hasCoords ? $lng : null);
        }

        // OCM API disabled for testing
        // if ($useOcm && ($force || !$discoveredOcm)) {
        //     $discoveredOcm = $this->discoverOcmStationId($title, $hasCoords ? $lat : null, $hasCoords ? $lng : null);
        // }

        if ($save) {
            // Kontrola vzdálenosti před uložením ID
            if ($useGoogle && $discoveredGoogle) {
                $googleDetails = $this->fetchGooglePlaceDetails($discoveredGoogle, $postId);
                if ($googleDetails && $hasCoords) {
                    $googleLat = $googleDetails['latitude'] ?? null;
                    $googleLng = $googleDetails['longitude'] ?? null;
                    if ($googleLat !== null && $googleLng !== null) {
                        $distance = $this->haversineM($lat, $lng, $googleLat, $googleLng);
                        if ($distance > 100) {
                            // Příliš daleko - neukládat ID, přidat do review fronty
                            $discoveredGoogle = null;
                            error_log("Charging Discovery: Google ID příliš daleko ({$distance}m) pro objekt $postId");
                            $this->addToReviewQueue($postId, "Google ID příliš daleko ({$distance}m)");
                        } elseif ($distance > 50) {
                            // Podezřelé - uložit s varováním a přidat do review
                            error_log("Charging Discovery: Google ID podezřele daleko ({$distance}m) pro objekt $postId");
                            $this->addToReviewQueue($postId, "Google ID podezřele daleko ({$distance}m)");
                        }
                    }
                }
                if ($discoveredGoogle) {
                    update_post_meta($postId, self::META_GOOGLE_ID, $discoveredGoogle);
                    $this->refreshGoogleMetadata($postId, $discoveredGoogle, true);
                } else {
                    // Pokud se ID neuložilo kvůli vzdálenosti, vymaž existující
                    delete_post_meta($postId, self::META_GOOGLE_ID);
                    delete_post_meta($postId, self::META_GOOGLE_CACHE);
                    delete_post_meta($postId, self::META_GOOGLE_CACHE_EXP);
                }
            }
            
            // OCM API disabled for testing
            // if ($useOcm && $discoveredOcm) {
            //     // ... OCM logic disabled
            // }
        }

        // Pokud se nenašlo žádné ID, přidat do review fronty a přidat Street View fallback
        if (!$discoveredGoogle) {
            $this->addToReviewQueue($postId, "Nebylo nalezeno žádné Google ID");
            // Přidat Street View jako fallback pro nabíječky ve frontě
            $this->addStreetViewFallback($postId, $lat, $lng);
        }
        
        return [
            'google' => $discoveredGoogle,
            'open_charge_map' => $discoveredOcm,
            'debug' => [
                'title' => $title,
                'lat' => $hasCoords ? $lat : null,
                'lng' => $hasCoords ? $lng : null,
            ],
        ];
    }

    /**
     * Fetch cached metadata for Google and OCM.
     */
    public function getCachedMetadata(int $postId): array {
        $google = $this->maybeGetPostCache($postId, self::META_GOOGLE_CACHE, self::META_GOOGLE_CACHE_EXP);
        return [
            'google' => $google,
            'open_charge_map' => null, // OCM disabled
        ];
    }

    public function refreshGoogleMetadata(int $postId, string $placeId, bool $force = false): ?array {
        if ($placeId === '') {
            return null;
        }
        
        if (!$force && !$this->shouldRefreshGoogleMetadata($postId)) {
            // Return cached data if no refresh needed
            $cached = $this->maybeGetPostCache($postId, self::META_GOOGLE_CACHE, self::META_GOOGLE_CACHE_EXP);
            if ($cached !== null) {
                return $cached;
            }
        }
        
        // Živá data nejsou momentálně používána - odstraněno
        $details = $this->fetchGooglePlaceDetails($placeId, $postId);
        if ($details) {
            update_post_meta($postId, self::META_GOOGLE_CACHE, $details);
            update_post_meta($postId, self::META_GOOGLE_CACHE_EXP, time() + self::METADATA_TTL);
            
            // Uložit stav a dostupnost do samostatných meta
            if (!empty($details['business_status'])) {
                update_post_meta($postId, '_charging_business_status', $details['business_status']);
            }
            
            // Uložit informace o konektorech a jejich dostupnosti
            if (!empty($details['connectors']) && is_array($details['connectors'])) {
                $totalConnectors = 0;
                $availableConnectors = 0;
                $hasAvailabilityData = false;
                
                foreach ($details['connectors'] as $connector) {
                    $count = (int) ($connector['count'] ?? 0);
                    $availableCount = $connector['availableCount'] ?? null;
                    
                    $totalConnectors += $count;
                    
                    // availableCount může být null (neznámá dostupnost) nebo číslo (známá dostupnost)
                    // Pokud je availableCount přítomné (i když je 0), máme data o dostupnosti
                    if (isset($connector['availableCount'])) {
                        $availableConnectors += (int) $availableCount;
                        $hasAvailabilityData = true;
                    }
                }
                
                if ($totalConnectors > 0) {
                    // Uložit dostupnost pouze pokud máme skutečná data o dostupnosti
                    if ($hasAvailabilityData) {
                        update_post_meta($postId, '_charging_live_available', $availableConnectors);
                        update_post_meta($postId, '_charging_live_total', $totalConnectors);
                        update_post_meta($postId, '_charging_live_source', 'google_places');
                        update_post_meta($postId, '_charging_live_updated', current_time('mysql'));
                        update_post_meta($postId, '_charging_live_data_available', '1');
                    } else {
                        // Pokud nemáme data o dostupnosti, uložit pouze celkový počet
                        delete_post_meta($postId, '_charging_live_available');
                        update_post_meta($postId, '_charging_live_total', $totalConnectors);
                        delete_post_meta($postId, '_charging_live_source');
                        delete_post_meta($postId, '_charging_live_updated');
                        update_post_meta($postId, '_charging_live_data_available', '0');
                    }
                }
            }
        }
        return $details;
    }

    // OCM methods removed - no longer supported

    public function refreshLiveStatus(int $postId, bool $force = false): ?array {
        if (!$force) {
            $cached = $this->maybeGetPostCache($postId, self::META_LIVE_STATUS, self::META_LIVE_STATUS_EXP);
            if ($cached !== null) {
                return $cached;
            }
        }
        $googleId = (string) get_post_meta($postId, self::META_GOOGLE_ID, true);
        $status = $this->fetchLiveStatus($googleId ?: null, null, $postId); // OCM disabled
        if ($status !== null) {
            update_post_meta($postId, self::META_LIVE_STATUS, $status);
            update_post_meta($postId, self::META_LIVE_STATUS_EXP, time() + rand(self::LIVE_TTL_MIN, self::LIVE_TTL_MAX));
        }
        return $status;
    }

    private function maybeGetPostCache(int $postId, string $metaKey, string $expiresKey): ?array {
        $expires = (int) get_post_meta($postId, $expiresKey, true);
        if ($expires > 0 && $expires > time()) {
            $data = get_post_meta($postId, $metaKey, true);
            return is_array($data) ? $data : null;
        }
        return null;
    }
    
    private function shouldRefreshLiveData(int $postId): bool {
        $liveDataAvailable = get_post_meta($postId, '_charging_live_data_available', true);
        
        // Pokud nemáme data o dostupnosti, aktualizujeme při každém kliknutí
        if ($liveDataAvailable !== '1') {
            return true;
        }
        
        // Pokud máme data o dostupnosti, respektujeme cache TTL (30 dní)
        $expires = (int) get_post_meta($postId, self::META_GOOGLE_CACHE_EXP, true);
        return $expires <= time();
    }
    
    /**
     * Check if we should refresh Google metadata based on cache and live data availability
     */
    private function shouldRefreshGoogleMetadata(int $postId): bool {
        // Always refresh if no cache exists
        $expires = (int) get_post_meta($postId, self::META_GOOGLE_CACHE_EXP, true);
        if ($expires <= time()) {
            return true;
        }
        
        // Check if we have live data available and should refresh only live data
        $liveDataAvailable = get_post_meta($postId, '_charging_live_data_available', true);
        if ($liveDataAvailable === '1') {
            // If we have live data, only refresh if live data is stale
            $liveUpdated = get_post_meta($postId, '_charging_live_updated', true);
            if ($liveUpdated) {
                $liveTimestamp = strtotime($liveUpdated);
                // Refresh live data if older than 5 minutes
                return $liveTimestamp < (time() - 300);
            }
            return false; // Don't refresh static metadata if we have live data
        }
        
        return true; // Refresh static metadata if no live data
    }
    
    /**
     * Přidá nabíječku do fronty pro manuální review
     */
    private function addToReviewQueue(int $postId, string $reason = ''): void {
        global $wpdb;
        
        $tableName = $wpdb->prefix . 'db_charging_discovery_queue';
        
        // Zkontrolovat, zda už není ve frontě
        $existing = $wpdb->get_var($wpdb->prepare(
            "SELECT id FROM $tableName WHERE station_id = %d AND status IN ('pending', 'review')",
            $postId
        ));
        
        if ($existing) {
            // Aktualizovat existující záznam na review
            $wpdb->update(
                $tableName,
                [
                    'status' => 'review',
                    'matched_score' => $reason,
                    'updated_at' => current_time('mysql')
                ],
                ['id' => $existing],
                ['%s', '%s', '%s'],
                ['%d']
            );
        } else {
            // Přidat nový záznam
            $wpdb->insert(
                $tableName,
                [
                    'station_id' => $postId,
                    'status' => 'review',
                    'matched_score' => $reason,
                    'created_at' => current_time('mysql'),
                    'updated_at' => current_time('mysql')
                ],
                ['%d', '%s', '%s', '%s', '%s']
            );
        }
    }
    
    /**
     * Přidá Street View jako fallback pro nabíječky ve frontě
     * Pokusí se použít adresu z databáze, pokud GPS nefunguje
     */
    private function addStreetViewFallback(int $postId, ?float $lat, ?float $lng): void {
        // Zkusit nejdříve s adresou z databáze
        $address = get_post_meta($postId, '_db_address', true);
        $streetViewUrl = null;
        
        if (!empty($address)) {
            $streetViewUrl = $this->generateStreetViewUrlFromAddress($address);
        }
        
        // Pokud adresa nefunguje a máme GPS, zkusit GPS
        if (!$streetViewUrl && $lat !== null && $lng !== null) {
            $streetViewUrl = $this->generateStreetViewUrl($lat, $lng);
        }
        
        if ($streetViewUrl) {
            // Uložit Street View jako fallback metadata
            $fallbackData = [
                'photos' => [
                    [
                        'photo_reference' => 'streetview',
                        'street_view_url' => $streetViewUrl,
                        'width' => 640,
                        'height' => 480,
                    ]
                ],
                'source' => 'street_view_fallback',
                'created_at' => current_time('mysql')
            ];
            
            update_post_meta($postId, '_charging_fallback_metadata', $fallbackData);
        }
    }

    private function discoverGooglePlaceId(string $title, ?float $lat, ?float $lng): ?string {
        $enrichment_service = Places_Enrichment_Service::get_instance();
        
        // Use new searchNearby API for GPS-based discovery
        if ($lat !== null && $lng !== null) {
            return $this->discoverGooglePlaceIdNearby($title, $lat, $lng, $enrichment_service);
        }

        // Fallback to text search if no GPS coordinates
        return $this->discoverGooglePlaceIdTextSearch($title, $enrichment_service);
    }

    /**
     * Discover Google Place ID using searchNearby API (GPS-based)
     * Používá Places_Enrichment_Service pro správu kvót
     */
    private function discoverGooglePlaceIdNearby(string $title, float $lat, float $lng, Places_Enrichment_Service $enrichment_service): ?string {
        $result = $enrichment_service->request_place_search_nearby(
            $lat,
            $lng,
            array('electric_vehicle_charging_station'),
            500.0,
            array('endpoint' => 'places_search_nearby')
        );

        if (is_wp_error($result)) {
            return null;
        }

        if (is_array($result) && isset($result['enriched']) && $result['enriched'] === false) {
            // Quota exceeded or disabled
            return null;
        }

        $data = $result['data'] ?? array();
        $places = $data['places'] ?? array();

        if (empty($places) || !is_array($places)) {
            return null;
        }

        return $this->findBestMatch($title, $lat, $lng, $places);
    }

    /**
     * Find best match from nearby search results
     */
    private function findBestMatch(string $title, float $lat, float $lng, array $places): ?string {
        $bestMatch = null;
        $bestScore = -1;

        foreach ($places as $place) {
            $placeId = (string) ($place['id'] ?? '');
            if ($placeId === '') {
                continue;
            }

            $score = 0;
            $name = (string) ($place['displayName']['text'] ?? '');
            $placeLat = $place['location']['latitude'] ?? null;
            $placeLng = $place['location']['longitude'] ?? null;
            $businessStatus = (string) ($place['businessStatus'] ?? '');

            // GPS distance score (primary criterion - highest weight)
            if ($placeLat !== null && $placeLng !== null) {
                $distance = $this->haversineM($lat, $lng, $placeLat, $placeLng);
                
                if ($distance < 50) {
                    $score += 1000; // Perfect match
                } elseif ($distance < 100) {
                    $score += 800; // Very good match
                } elseif ($distance < 200) {
                    $score += 400; // Good match
                } elseif ($distance < 500) {
                    $score += 100; // Acceptable match
                } else {
                    continue; // Too far, skip this place
                }
            }

            // Business status bonus
            if ($businessStatus === 'OPERATIONAL') {
                $score += 50;
            }

            // Text similarity score (secondary criterion)
            if (!empty($name) && !empty($title)) {
                $similarity = similar_text(mb_strtolower($title), mb_strtolower($name));
                $score += $similarity * 2; // Lower weight than GPS
            }

            // Provider name bonus
            $titleParts = explode(' - ', $title);
            if (count($titleParts) >= 2) {
                $provider = trim($titleParts[0]);
                if (stripos($name, $provider) !== false) {
                    $score += 100; // Provider name match bonus
                } else {
                    // Try partial match - remove ", a.s." etc.
                    $providerShort = preg_replace('/,\s*(a\.s\.|s\.r\.o\.|spol\.\s*s\s*r\.o\.|s\.p\.|a\.s\.)$/i', '', $provider);
                    if (stripos($name, $providerShort) !== false) {
                        $score += 50; // Partial provider match
                    }
                }
            }

            if ($score > $bestScore) {
                $bestScore = $score;
                $bestMatch = $placeId;
            }
        }

        return $bestMatch;
    }

    /**
     * Fallback: Discover Google Place ID using text search (legacy method)
     * Používá Places_Enrichment_Service pro správu kvót
     */
    private function discoverGooglePlaceIdTextSearch(string $title, Places_Enrichment_Service $enrichment_service): ?string {
        $result = $enrichment_service->request_place_text_search(
            $title,
            null,
            null,
            array('endpoint' => 'places_textsearch')
        );

        if (is_wp_error($result)) {
            return null;
        }

        if (is_array($result) && isset($result['enriched']) && $result['enriched'] === false) {
            // Quota exceeded or disabled
            return null;
        }

        $data = $result['data'] ?? array();
        $results = $data['results'] ?? array();

        if (empty($results) || !is_array($results)) {
            return null;
        }
        
        // Return first result for text search (no GPS-based scoring)
        return (string) ($results[0]['place_id'] ?? '');
    }

    // OCM discovery method removed - no longer supported

    private function fetchGooglePlaceDetails(string $placeId, int $postId = 0): ?array {
        if ($placeId === '') {
            return null;
        }
        
        // Použít Places_Enrichment_Service pro správu kvót
        $enrichment_service = Places_Enrichment_Service::get_instance();
        $result = $enrichment_service->request_place_details($placeId, array(
            'post_id' => $postId,
            'endpoint' => 'places_details'
        ));

        if (is_wp_error($result)) {
            return null;
        }

        if (is_array($result) && isset($result['enriched']) && $result['enriched'] === false) {
            // Quota exceeded or disabled
            return null;
        }

        $data = $result['data'] ?? null;
        if (!$data) {
            return null;
        }

        // Normalizovat data z Places_Enrichment_Service (staré API formát)
        // Places_Enrichment_Service vrací data ve formátu starého API
        $payload = array(
            'name' => $data['displayName']['text'] ?? '',
            'formatted_address' => $data['formattedAddress'] ?? '',
            'latitude' => $data['location']['latitude'] ?? null,
            'longitude' => $data['location']['longitude'] ?? null,
            'photos' => array(),
            'rating' => $data['rating'] ?? null,
            'user_ratings_total' => $data['userRatingCount'] ?? null,
            'phone' => $data['nationalPhoneNumber'] ?? '',
            'website' => $data['websiteUri'] ?? '',
            'maps_url' => $data['url'] ?? '',
            'business_status' => $data['businessStatus'] ?? '',
            'connectors' => array(),
        );

        // Normalizovat fotky ze starého formátu
        if (!empty($data['photos']) && is_array($data['photos'])) {
            foreach ($data['photos'] as $photo) {
                $payload['photos'][] = array(
                    'photo_reference' => $photo['photoReference'] ?? '',
                    'width' => $photo['width'] ?? 0,
                    'height' => $photo['height'] ?? 0,
                );
            }
        }

        // Pro nové Places API v1 potřebujeme získat data o konektorech zvlášť
        // POZNÁMKA: Toto volání potřebuje kvótu - rezervujeme ji
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey !== '') {
            // Kontrola kvóty před voláním
            $quota = new \DB\Jobs\Google_Quota_Manager();
            $quota_check = $quota->reserve_quota(1);
            if (is_wp_error($quota_check)) {
                // Pokud došla kvóta, vrátit data bez konektorů
                return $payload;
            }
            
            $url = "https://places.googleapis.com/v1/places/$placeId";
            $fields = ['evChargeOptions'];
            $url .= '?fields=' . implode(',', $fields) . '&key=' . $apiKey;
            
            $response = wp_remote_get($url, array(
                'timeout' => 12,
                'user-agent' => 'DobityBaterky/charging-discovery (+https://dobitybaterky.cz)',
                'headers' => array(
                    'Content-Type' => 'application/json',
                ),
            ));
            
            if (!is_wp_error($response)) {
                $code = (int) wp_remote_retrieve_response_code($response);
                if ($code >= 200 && $code < 300) {
                    $evData = json_decode((string) wp_remote_retrieve_body($response), true);
                    if (is_array($evData) && !empty($evData['evChargeOptions']['connectorAggregation'])) {
                        foreach ($evData['evChargeOptions']['connectorAggregation'] as $connector) {
                            $payload['connectors'][] = array(
                                'type' => (string) ($connector['type'] ?? ''),
                                'maxChargeRateKw' => isset($connector['maxChargeRateKw']) ? (int) $connector['maxChargeRateKw'] : null,
                                'count' => isset($connector['count']) ? (int) $connector['count'] : null,
                                'availableCount' => isset($connector['availableCount']) ? (int) $connector['availableCount'] : null,
                                'outOfServiceCount' => isset($connector['outOfServiceCount']) ? (int) $connector['outOfServiceCount'] : null,
                            );
                        }
                        $payload['connectorCount'] = isset($evData['evChargeOptions']['connectorCount']) ? (int) $evData['evChargeOptions']['connectorCount'] : null;
                    }
                }
            }
        }

        // Pokud nejsou fotky, přidat Street View jako fallback
        if (empty($payload['photos']) && $postId > 0) {
            $streetViewUrl = null;
            $address = get_post_meta($postId, '_db_address', true);
            if (!empty($address)) {
                $streetViewUrl = $this->generateStreetViewUrlFromAddress($address);
            }
            if (!$streetViewUrl && $payload['latitude'] !== null && $payload['longitude'] !== null) {
                $streetViewUrl = $this->generateStreetViewUrl($payload['latitude'], $payload['longitude']);
            }
            if ($streetViewUrl) {
                $payload['photos'][] = array(
                    'photo_reference' => 'streetview',
                    'street_view_url' => $streetViewUrl,
                    'width' => 640,
                    'height' => 480,
                );
            }
        }

        return $payload;
    }

    // OCM fetch method removed - no longer supported

    private function fetchLiveStatus(?string $googleId, ?string $ocmId, int $postId = 0): ?array {
        $status = null;
        // OCM removed - only Google supported
        if ($googleId) {
            $details = $this->fetchGooglePlaceDetails($googleId, $postId);
            if ($details) {
                $status = [
                    'available' => null,
                    'total' => null,
                    'source' => 'google_places',
                    'updated_at' => current_time('mysql'),
                ];
            }
        }
        return $status;
    }

    private function haversineM(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371000.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earthRadius * $c;
    }

    /**
     * Generuje URL pro Street View Static API z GPS souřadnic
     */
    private function generateStreetViewUrl(float $lat, float $lng): ?string {
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '') {
            return null;
        }

        // Street View Static API URL s GPS souřadnicemi
        return add_query_arg([
            'location' => $lat . ',' . $lng,
            'size' => '640x480',
            'fov' => '90',
            'heading' => '0',
            'pitch' => '0',
            'key' => $apiKey,
        ], 'https://maps.googleapis.com/maps/api/streetview');
    }

    /**
     * Generuje URL pro Street View Static API z adresy
     * Tato metoda je spolehlivější než GPS souřadnice
     */
    private function generateStreetViewUrlFromAddress(string $address): ?string {
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '') {
            return null;
        }

        // Street View Static API URL s adresou
        return add_query_arg([
            'location' => urlencode($address),
            'size' => '640x480',
            'fov' => '90',
            'heading' => '0',
            'pitch' => '0',
            'key' => $apiKey,
        ], 'https://maps.googleapis.com/maps/api/streetview');
    }
}
