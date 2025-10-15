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
            if ($useGoogle && $discoveredGoogle) {
                update_post_meta($postId, self::META_GOOGLE_ID, $discoveredGoogle);
                $this->refreshGoogleMetadata($postId, $discoveredGoogle, true);
            }
            if ($useOcm && $discoveredOcm) {
                update_post_meta($postId, self::META_OCM_ID, $discoveredOcm);
                $this->refreshOcmMetadata($postId, $discoveredOcm, true);
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
            $cached = $this->maybeGetPostCache($postId, self::META_GOOGLE_CACHE, self::META_GOOGLE_CACHE_EXP);
            if ($cached !== null) {
                return $cached;
            }
        }
        $details = $this->fetchGooglePlaceDetails($placeId);
        if ($details) {
            update_post_meta($postId, self::META_GOOGLE_CACHE, $details);
            update_post_meta($postId, self::META_GOOGLE_CACHE_EXP, time() + self::METADATA_TTL);
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
        foreach ($data['results'] as $item) {
            $placeId = (string) ($item['place_id'] ?? '');
            if ($placeId === '') {
                continue;
            }
            $candidateScore = 0.0;
            $name = (string) ($item['name'] ?? '');
            if ($title !== '' && $name !== '') {
                $candidateScore += similar_text(mb_strtolower($title), mb_strtolower($name));
            }
            $rating = isset($item['rating']) ? (float) $item['rating'] : 0.0;
            $candidateScore += $rating * 10.0;
            if ($lat !== null && $lng !== null && isset($item['geometry']['location'])) {
                $ilat = (float) ($item['geometry']['location']['lat'] ?? 0.0);
                $ilng = (float) ($item['geometry']['location']['lng'] ?? 0.0);
                $dist = $this->haversineM($lat, $lng, $ilat, $ilng);
                // Preferovat nejbližší nabíječky - vzdálenost má větší váhu
                $candidateScore += max(0.0, 1000.0 - min(1000.0, $dist)) / 10.0;
            }
            if ($candidateScore > $score) {
                $score = $candidateScore;
                $best = $placeId;
            }
        }
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
                // Preferovat nejbližší nabíječky - vzdálenost má větší váhu
                $candidateScore += max(0.0, 1000.0 - min(1000.0, $dist)) / 10.0;
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
        $fields = implode(',', [
            'name', 'geometry/location', 'formatted_address', 'photos', 'opening_hours',
            'international_phone_number', 'website', 'url', 'rating', 'user_ratings_total', 'business_status'
        ]);
        $url = add_query_arg([
            'place_id' => $placeId,
            'fields' => $fields,
            'language' => 'cs',
            'key' => $apiKey,
        ], self::GOOGLE_DETAILS_URL);
        $response = wp_remote_get($url, [
            'timeout' => 12,
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
        if (!is_array($data) || !isset($data['result']) || !is_array($data['result'])) {
            return null;
        }
        $result = $data['result'];
        $photos = [];
        if (!empty($result['photos']) && is_array($result['photos'])) {
            foreach ($result['photos'] as $photo) {
                if (!isset($photo['photo_reference'])) {
                    continue;
                }
                $photos[] = [
                    'photo_reference' => (string) $photo['photo_reference'],
                    'width' => isset($photo['width']) ? (int) $photo['width'] : null,
                    'height' => isset($photo['height']) ? (int) $photo['height'] : null,
                ];
            }
        }
        $geometry = $result['geometry']['location'] ?? [];
        $payload = [
            'name' => (string) ($result['name'] ?? ''),
            'formatted_address' => (string) ($result['formatted_address'] ?? ''),
            'latitude' => isset($geometry['lat']) ? (float) $geometry['lat'] : null,
            'longitude' => isset($geometry['lng']) ? (float) $geometry['lng'] : null,
            'opening_hours' => $result['opening_hours'] ?? null,
            'photos' => $photos,
            'rating' => isset($result['rating']) ? (float) $result['rating'] : null,
            'user_ratings_total' => isset($result['user_ratings_total']) ? (int) $result['user_ratings_total'] : null,
            'phone' => (string) ($result['international_phone_number'] ?? ''),
            'website' => (string) ($result['website'] ?? ''),
            'maps_url' => (string) ($result['url'] ?? ''),
            'business_status' => (string) ($result['business_status'] ?? ''),
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
}
