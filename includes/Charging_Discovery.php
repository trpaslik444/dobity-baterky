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
    private const OCM_POI_URL           = 'https://api.openchargemap.io/v3/poi';

    private const META_GOOGLE_ID          = '_charging_google_place_id';
    private const META_GOOGLE_CACHE       = '_charging_google_cache';
    private const META_GOOGLE_CACHE_EXP   = '_charging_google_cache_expires';
    private const META_OCM_ID             = '_openchargemap_id';
    private const META_OCM_CACHE          = '_charging_ocm_cache';
    private const META_OCM_CACHE_EXP      = '_charging_ocm_cache_expires';
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
    public function discoverForCharging(int $postId, bool $save = false, bool $force = false, bool $useGoogle = true, bool $useOcm = true): array {
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
        $ocmId = get_post_meta($postId, self::META_OCM_ID, true);

        $discoveredGoogle = $googlePlaceId !== '' ? (string) $googlePlaceId : null;
        $discoveredOcm = $ocmId !== '' ? (string) $ocmId : null;

        // Try to discover Google Place ID when missing or forced.
        if ($useGoogle && ($force || !$discoveredGoogle)) {
            $discoveredGoogle = $this->discoverGooglePlaceId($title, $hasCoords ? $lat : null, $hasCoords ? $lng : null);
        }

        // Try to discover OCM ID when missing or forced.
        if ($useOcm && ($force || !$discoveredOcm)) {
            $discoveredOcm = $this->discoverOcmStationId($title, $hasCoords ? $lat : null, $hasCoords ? $lng : null);
        }

        if ($save) {
            // Kontrola vzdálenosti před uložením ID
            if ($useGoogle && $discoveredGoogle) {
                $googleDetails = $this->fetchGooglePlaceDetails($discoveredGoogle);
                if ($googleDetails && $hasCoords) {
                    $googleLat = $googleDetails['latitude'] ?? null;
                    $googleLng = $googleDetails['longitude'] ?? null;
                    if ($googleLat !== null && $googleLng !== null) {
                        $distance = $this->haversineM($lat, $lng, $googleLat, $googleLng);
                        if ($distance > 100) {
                            // Příliš daleko - neukládat ID
                            $discoveredGoogle = null;
                            error_log("Charging Discovery: Google ID příliš daleko ({$distance}m) pro objekt $postId");
                        } elseif ($distance > 50) {
                            // Podezřelé - ale uložit s varováním
                            error_log("Charging Discovery: Google ID podezřele daleko ({$distance}m) pro objekt $postId");
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
            
            if ($useOcm && $discoveredOcm) {
                $ocmDetails = $this->fetchOcmStationDetails($discoveredOcm);
                if ($ocmDetails && $hasCoords) {
                    $ocmLat = $ocmDetails['latitude'] ?? null;
                    $ocmLng = $ocmDetails['longitude'] ?? null;
                    if ($ocmLat !== null && $ocmLng !== null) {
                        $distance = $this->haversineM($lat, $lng, $ocmLat, $ocmLng);
                        if ($distance > 100) {
                            // Příliš daleko - neukládat ID
                            $discoveredOcm = null;
                            error_log("Charging Discovery: OCM ID příliš daleko ({$distance}m) pro objekt $postId");
                        } elseif ($distance > 50) {
                            // Podezřelé - ale uložit s varováním
                            error_log("Charging Discovery: OCM ID podezřele daleko ({$distance}m) pro objekt $postId");
                        }
                    }
                }
                if ($discoveredOcm) {
                    update_post_meta($postId, self::META_OCM_ID, $discoveredOcm);
                    $this->refreshOcmMetadata($postId, $discoveredOcm, true);
                } else {
                    // Pokud se ID neuložilo kvůli vzdálenosti, vymaž existující
                    delete_post_meta($postId, self::META_OCM_ID);
                    delete_post_meta($postId, self::META_OCM_CACHE);
                    delete_post_meta($postId, self::META_OCM_CACHE_EXP);
                }
            }
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
        $ocm = $this->maybeGetPostCache($postId, self::META_OCM_CACHE, self::META_OCM_CACHE_EXP);
        return [
            'google' => $google,
            'open_charge_map' => $ocm,
        ];
    }

    public function refreshGoogleMetadata(int $postId, string $placeId, bool $force = false): ?array {
        if ($placeId === '') {
            return null;
        }
        if (!$force) {
            // Pro data o dostupnosti použijeme speciální logiku
            if ($this->shouldRefreshLiveData($postId)) {
                // Aktualizovat data
            } else {
                $cached = $this->maybeGetPostCache($postId, self::META_GOOGLE_CACHE, self::META_GOOGLE_CACHE_EXP);
                if ($cached !== null) {
                    return $cached;
                }
            }
        }
        $details = $this->fetchGooglePlaceDetails($placeId);
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
                    // Pokud je availableCount přítomné, zkontrolujeme logiku
                    if (isset($connector['availableCount'])) {
                        $available = (int) $availableCount;
                        $outOfService = (int) ($connector['outOfServiceCount'] ?? 0);
                        $total = (int) $count;
                        
                        // Podezřelý stav: 0 dostupných + 0 mimo provoz = neznámá dostupnost
                        if ($available === 0 && $outOfService === 0 && $total > 0) {
                            // Není to skutečná dostupnost, ale neznámá
                            $hasAvailabilityData = false;
                        } else {
                            // Platná data o dostupnosti
                            $availableConnectors += $available;
                            $hasAvailabilityData = true;
                        }
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

    public function refreshOcmMetadata(int $postId, string $ocmId, bool $force = false): ?array {
        if ($ocmId === '') {
            return null;
        }
        if (!$force) {
            $cached = $this->maybeGetPostCache($postId, self::META_OCM_CACHE, self::META_OCM_CACHE_EXP);
            if ($cached !== null) {
                return $cached;
            }
        }
        $details = $this->fetchOcmStationDetails($ocmId);
        if ($details) {
            update_post_meta($postId, self::META_OCM_CACHE, $details);
            update_post_meta($postId, self::META_OCM_CACHE_EXP, time() + self::METADATA_TTL);
            
            // OCM obvykle neposkytuje data o aktuální dostupnosti, jen obecný stav
            // Proto ukládáme pouze celkový počet konektorů
            if (isset($details['status_summary']['total']) && $details['status_summary']['total'] > 0) {
                delete_post_meta($postId, '_charging_live_available');
                update_post_meta($postId, '_charging_live_total', $details['status_summary']['total']);
                delete_post_meta($postId, '_charging_live_source');
                delete_post_meta($postId, '_charging_live_updated');
                update_post_meta($postId, '_charging_live_data_available', '0');
            }
        }
        return $details;
    }

    public function refreshLiveStatus(int $postId, bool $force = false): ?array {
        if (!$force) {
            $cached = $this->maybeGetPostCache($postId, self::META_LIVE_STATUS, self::META_LIVE_STATUS_EXP);
            if ($cached !== null) {
                return $cached;
            }
        }
        $googleId = (string) get_post_meta($postId, self::META_GOOGLE_ID, true);
        $ocmId = (string) get_post_meta($postId, self::META_OCM_ID, true);
        $status = $this->fetchLiveStatus($googleId ?: null, $ocmId ?: null);
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

    private function discoverGooglePlaceId(string $title, ?float $lat, ?float $lng): ?string {
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '') {
            return null;
        }
        $query = [
            'query' => $title,
            'type' => 'electric_vehicle_charging_station',
            'language' => 'cs',
            'key' => $apiKey,
        ];
        if ($lat !== null && $lng !== null) {
            $query['location'] = $lat . ',' . $lng;
            $query['radius'] = '1000';
        }
        $url = add_query_arg($query, self::GOOGLE_TEXTSEARCH_URL);
        $response = wp_remote_get($url, [
            'timeout' => 10,
            'user-agent' => 'DobityBaterky/charging-discovery (+https://dobitybaterky.cz)',
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data['results']) || !is_array($data['results'])) {
            return null;
        }
        $best = null;
        $score = -INF;
        $candidates = [];
        foreach ($data['results'] as $item) {
            $placeId = (string) ($item['place_id'] ?? '');
            if ($placeId === '') {
                continue;
            }
            $candidateScore = 0.0;
            $name = (string) ($item['name'] ?? '');
            $nameSimilarity = 0.0;
            $addressBonus = 0.0;
            
            if ($title !== '' && $name !== '') {
                // Základní podobnost názvu
                $nameSimilarity = similar_text(mb_strtolower($title), mb_strtolower($name));
                $candidateScore += $nameSimilarity;
                
                // Bonus za shodu poskytovatele a adresy
                $titleParts = explode(' - ', $title);
                if (count($titleParts) >= 2) {
                    $provider = trim($titleParts[0]);
                    $address = trim($titleParts[1]);
                    
                    // Bonus za shodu poskytovatele (celý nebo částečný)
                    if (stripos($name, $provider) !== false) {
                        $addressBonus += 10.0;
                    } else {
                        // Zkusit částečnou shodu - odstranit ", a.s." a podobné
                        $providerShort = preg_replace('/,\s*(a\.s\.|s\.r\.o\.|spol\.\s*s\s*r\.o\.|s\.p\.|a\.s\.)$/i', '', $provider);
                        if (stripos($name, $providerShort) !== false) {
                            $addressBonus += 8.0; // Menší bonus za částečnou shodu
                        }
                    }
                    
                    // Bonus za slova z adresy
                    $addressWords = preg_split('/[\s,]+/', $address);
                    foreach ($addressWords as $word) {
                        if (strlen($word) > 2 && stripos($name, $word) !== false) {
                            $addressBonus += 5.0;
                        }
                    }
                }
                $candidateScore += $addressBonus;
            }
            // Hodnocení se nepoužívá pro výběr správného místa
            $distanceScore = 0.0;
            $dist = 0.0;
            if ($lat !== null && $lng !== null && isset($item['geometry']['location'])) {
                $ilat = (float) ($item['geometry']['location']['lat'] ?? 0.0);
                $ilng = (float) ($item['geometry']['location']['lng'] ?? 0.0);
                $dist = $this->haversineM($lat, $lng, $ilat, $ilng);
                // Preferovat nejbližší nabíječky - vzdálenost má mnohem větší váhu
                $distanceScore = max(0.0, 1000.0 - min(1000.0, $dist)) / 5.0; // Zvýšená váha vzdálenosti
                $candidateScore += $distanceScore;
            }
            
            $candidates[] = [
                'place_id' => $placeId,
                'name' => $name,
                'distance' => round($dist),
                'name_similarity' => $nameSimilarity,
                'address_bonus' => round($addressBonus, 2),
                'distance_score' => round($distanceScore, 2),
                'total_score' => round($candidateScore, 2)
            ];
            
            if ($candidateScore > $score) {
                $score = $candidateScore;
                $best = $placeId;
            }
        }
        
        // Debug logging odstraněno
        
        return $best;
    }

    private function discoverOcmStationId(string $title, ?float $lat, ?float $lng): ?string {
        $apiKey = (string) get_option('db_openchargemap_api_key');
        $query = [
            'output' => 'json',
            'compact' => 'true',
            'maxresults' => 20,
        ];
        if ($apiKey !== '') {
            $query['key'] = $apiKey;
        }
        if ($lat !== null && $lng !== null) {
            $query['latitude'] = $lat;
            $query['longitude'] = $lng;
            $query['distance'] = 10;
            $query['distanceunit'] = 'KM';
        } elseif ($title !== '') {
            $query['query'] = $title;
        } else {
            return null;
        }
        $url = add_query_arg($query, self::OCM_POI_URL);
        $headers = [
            'User-Agent' => 'DobityBaterky/charging-discovery (+https://dobitybaterky.cz)',
        ];
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => $headers,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        $best = null;
        $score = -INF;
        foreach ($data as $poi) {
            $id = (string) ($poi['ID'] ?? '');
            if ($id === '') {
                continue;
            }
            $candidateScore = 0.0;
            $name = (string) ($poi['AddressInfo']['Title'] ?? '');
            if ($title !== '' && $name !== '') {
                $candidateScore += similar_text(mb_strtolower($title), mb_strtolower($name));
            }
            if ($lat !== null && $lng !== null && isset($poi['AddressInfo']['Latitude'], $poi['AddressInfo']['Longitude'])) {
                $ilat = (float) $poi['AddressInfo']['Latitude'];
                $ilng = (float) $poi['AddressInfo']['Longitude'];
                $dist = $this->haversineM($lat, $lng, $ilat, $ilng);
                // Preferovat nejbližší nabíječky - vzdálenost má mnohem větší váhu
                $candidateScore += max(0.0, 1000.0 - min(1000.0, $dist)) / 5.0; // Zvýšená váha vzdálenosti
            }
            if ($candidateScore > $score) {
                $score = $candidateScore;
                $best = 'ocm_' . $id;
            }
        }
        return $best;
    }

    private function fetchGooglePlaceDetails(string $placeId): ?array {
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '' || $placeId === '') {
            return null;
        }
        
        // Použít nové Places API v1 pro data o konektorech
        $url = "https://places.googleapis.com/v1/places/$placeId";
        $fields = [
            'displayName',
            'formattedAddress', 
            'location',
            'photos',
            'currentOpeningHours',
            'internationalPhoneNumber',
            'websiteUri',
            'rating',
            'userRatingCount',
            'businessStatus',
            'utcOffsetMinutes',
            'evChargeOptions' // Nové pole pro data o konektorech
        ];
        
        $url .= '?fields=' . implode(',', $fields) . '&key=' . $apiKey;
        
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'user-agent' => 'DobityBaterky/charging-discovery (+https://dobitybaterky.cz)',
            'headers' => [
                'Content-Type' => 'application/json',
            ],
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data)) {
            return null;
        }
        
        // Nové API v1 má jinou strukturu - data jsou přímo v root objektu
        $photos = [];
        if (!empty($data['photos']) && is_array($data['photos'])) {
            foreach ($data['photos'] as $photo) {
                if (!isset($photo['name'])) {
                    continue;
                }
                $photos[] = [
                    'photo_reference' => (string) $photo['name'], // Nové API používá 'name' místo 'photo_reference'
                    'width' => isset($photo['widthPx']) ? (int) $photo['widthPx'] : null,
                    'height' => isset($photo['heightPx']) ? (int) $photo['heightPx'] : null,
                ];
            }
        }
        
        // Zpracování dat o konektorech
        $connectors = [];
        if (!empty($data['evChargeOptions']['connectorAggregation']) && is_array($data['evChargeOptions']['connectorAggregation'])) {
            foreach ($data['evChargeOptions']['connectorAggregation'] as $connector) {
                $connectors[] = [
                    'type' => (string) ($connector['type'] ?? ''),
                    'maxChargeRateKw' => isset($connector['maxChargeRateKw']) ? (int) $connector['maxChargeRateKw'] : null,
                    'count' => isset($connector['count']) ? (int) $connector['count'] : null,
                    'availableCount' => isset($connector['availableCount']) ? (int) $connector['availableCount'] : null,
                    'outOfServiceCount' => isset($connector['outOfServiceCount']) ? (int) $connector['outOfServiceCount'] : null,
                ];
            }
        }

        // Pokud nejsou fotky, přidat Street View jako fallback
        if (empty($photos) && isset($data['location']['latitude'], $data['location']['longitude'])) {
            $streetViewUrl = $this->generateStreetViewUrl(
                (float) $data['location']['latitude'], 
                (float) $data['location']['longitude']
            );
            if ($streetViewUrl) {
                $photos[] = [
                    'photo_reference' => 'streetview',
                    'street_view_url' => $streetViewUrl,
                    'width' => 640,
                    'height' => 480,
                ];
            }
        }

        $payload = [
            'name' => (string) ($data['displayName']['text'] ?? ''),
            'formatted_address' => (string) ($data['formattedAddress'] ?? ''),
            'latitude' => isset($data['location']['latitude']) ? (float) $data['location']['latitude'] : null,
            'longitude' => isset($data['location']['longitude']) ? (float) $data['location']['longitude'] : null,
            'opening_hours' => $data['currentOpeningHours'] ?? null,
            'photos' => $photos,
            'rating' => isset($data['rating']) ? (float) $data['rating'] : null,
            'user_ratings_total' => isset($data['userRatingCount']) ? (int) $data['userRatingCount'] : null,
            'phone' => (string) ($data['internationalPhoneNumber'] ?? ''),
            'website' => (string) ($data['websiteUri'] ?? ''),
            'maps_url' => '', // Nové API neposkytuje maps_url přímo
            'business_status' => (string) ($data['businessStatus'] ?? ''),
            'connectorCount' => isset($data['evChargeOptions']['connectorCount']) ? (int) $data['evChargeOptions']['connectorCount'] : null,
            'connectors' => $connectors,
        ];
        return $payload;
    }

    private function fetchOcmStationDetails(string $ocmId): ?array {
        if ($ocmId === '') {
            return null;
        }
        $numericId = preg_replace('/^ocm_/i', '', $ocmId);
        if ($numericId === '') {
            return null;
        }
        $apiKey = (string) get_option('db_openchargemap_api_key');
        $query = [
            'output' => 'json',
            'chargepoints' => 'true',
            'compact' => 'false',
            'includecomments' => 'false',
            'verbose' => 'true',
            'maxresults' => 1,
            'countrycode' => '',
            'id' => $numericId,
        ];
        if ($apiKey !== '') {
            $query['key'] = $apiKey;
        }
        $url = add_query_arg($query, self::OCM_POI_URL);
        $headers = [
            'User-Agent' => 'DobityBaterky/charging-discovery (+https://dobitybaterky.cz)',
        ];
        if ($apiKey !== '') {
            $headers['X-API-Key'] = $apiKey;
        }
        $response = wp_remote_get($url, [
            'timeout' => 12,
            'headers' => $headers,
        ]);
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $data = json_decode((string) wp_remote_retrieve_body($response), true);
        if (!is_array($data) || empty($data[0])) {
            return null;
        }
        $poi = $data[0];
        $address = $poi['AddressInfo'] ?? [];
        $connections = $poi['Connections'] ?? [];
        $connectors = [];
        $available = 0;
        $total = 0;
        foreach ($connections as $connection) {
            $status = (string) ($connection['StatusType']['Title'] ?? '');
            $isAvailable = stripos($status, 'available') !== false;
            $quantity = isset($connection['Quantity']) ? (int) $connection['Quantity'] : 1;
            $total += $quantity;
            if ($isAvailable) {
                $available += $quantity;
            }
            $connectors[] = [
                'type' => (string) ($connection['ConnectionType']['Title'] ?? ''),
                'power_kw' => isset($connection['PowerKW']) ? (float) $connection['PowerKW'] : null,
                'current_type' => (string) ($connection['CurrentType']['Title'] ?? ''),
                'status' => $status,
                'quantity' => $quantity,
            ];
        }
        return [
            'id' => $ocmId,
            'name' => (string) ($address['Title'] ?? ''),
            'address' => [
                'street' => (string) ($address['AddressLine1'] ?? ''),
                'town' => (string) ($address['Town'] ?? ''),
                'state' => (string) ($address['StateOrProvince'] ?? ''),
                'postcode' => (string) ($address['Postcode'] ?? ''),
                'country' => (string) ($address['Country']['Title'] ?? ''),
            ],
            'latitude' => isset($address['Latitude']) ? (float) $address['Latitude'] : null,
            'longitude' => isset($address['Longitude']) ? (float) $address['Longitude'] : null,
            'connectors' => $connectors,
            'status_summary' => [
                'available' => $available,
                'total' => $total,
            ],
            'url' => (string) ($poi['DataProvider']['WebsiteURL'] ?? ''),
            'last_updated' => (string) ($poi['DateLastStatusUpdate'] ?? ''),
            'data_provider' => (string) ($poi['DataProvider']['Title'] ?? ''),
        ];
    }

    private function fetchLiveStatus(?string $googleId, ?string $ocmId): ?array {
        $status = null;
        if ($ocmId) {
            $meta = $this->fetchOcmStationDetails($ocmId);
            if ($meta && isset($meta['status_summary'])) {
                $status = [
                    'available' => (int) ($meta['status_summary']['available'] ?? 0),
                    'total' => (int) ($meta['status_summary']['total'] ?? 0),
                    'source' => 'open_charge_map',
                    'updated_at' => $meta['last_updated'] ?? null,
                ];
            }
        }
        if (!$status && $googleId) {
            $details = $this->fetchGooglePlaceDetails($googleId);
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
     * Generuje URL pro Street View Static API jako fallback pro chybějící fotky
     */
    private function generateStreetViewUrl(float $lat, float $lng): ?string {
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '') {
            return null;
        }

        // Street View Static API URL
        return add_query_arg([
            'location' => $lat . ',' . $lng,
            'size' => '640x480',
            'fov' => '90',
            'heading' => '0',
            'pitch' => '0',
            'key' => $apiKey,
        ], 'https://maps.googleapis.com/maps/api/streetview');
    }
}
