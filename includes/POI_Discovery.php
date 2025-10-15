<?php
declare(strict_types=1);

namespace DB;

if (!defined('ABSPATH')) {
	exit;
}

/**
 * POI_Discovery: vyhledání externích ID (Google Places / Tripadvisor) pro POI.
 * - Používá textové vyhledávání s volitelným geo-biasem (lat,lng)
 * - Ukládá nalezené ID do post meta, pokud je povoleno ukládání
 */
class POI_Discovery {
	private const GOOGLE_TEXTSEARCH_URL = 'https://maps.googleapis.com/maps/api/place/textsearch/json';
	private const TRIPADVISOR_SEARCH_URL = 'https://api.content.tripadvisor.com/api/v1/location/search';
    private const GOOGLE_DETAILS_URL    = 'https://maps.googleapis.com/maps/api/place/details/json';

	/**
	 * Spustí discovery pro jedno POI.
	 *
	 * @param int  $postId ID POI
	 * @param bool $save   Pokud true, uloží ID do post meta
	 * @param bool $withTripadvisor Zkusit i Tripadvisor (pokud je API klíč)
	 * @param bool $useGoogle Použít Google API (pokud je false, přeskočí Google search)
	 * @return array{google_place_id: string|null, tripadvisor_location_id: string|null, debug: array}
	 */
	public function discoverForPoi(int $postId, bool $save = false, bool $withTripadvisor = false, bool $useGoogle = true): array {
		$post = get_post($postId);
		if (!$post || $post->post_type !== 'poi') {
			return ['google_place_id' => null, 'tripadvisor_location_id' => null, 'debug' => ['error' => 'invalid_post']];
		}

		$title = trim((string)$post->post_title);
		$lat = (float) get_post_meta($postId, '_poi_lat', true);
		$lng = (float) get_post_meta($postId, '_poi_lng', true);
		$hasCoords = ($lat !== 0.0 || $lng !== 0.0);

		$googlePlaceId = null;
		if ($useGoogle) {
			$googlePlaceId = $this->discoverGooglePlaceId($title, $hasCoords ? $lat : null, $hasCoords ? $lng : null);
		}
		
		$tripadvisorId = null;
		if ($withTripadvisor) {
			$tripadvisorId = $this->discoverTripadvisorLocationId($title, $hasCoords ? $lat : null, $hasCoords ? $lng : null);
		}

        if ($save) {
            if ($googlePlaceId && get_post_meta($postId, '_poi_google_place_id', true) === '') {
				update_post_meta($postId, '_poi_google_place_id', $googlePlaceId);
				// Zneplatnit případnou starou cache
				delete_post_meta($postId, '_poi_google_cache');
				delete_post_meta($postId, '_poi_google_cache_expires');
                // Korekce GPS z Google Details (pokud se výrazně liší)
                $geo = $this->fetchGooglePlaceGeometry($googlePlaceId);
                if ($geo && isset($geo['lat']) && isset($geo['lng'])) {
                    $newLat = (float)$geo['lat'];
                    $newLng = (float)$geo['lng'];
                    $hadCoords = ($lat !== 0.0 || $lng !== 0.0);
                    $dist = $hadCoords ? $this->haversineM($lat, $lng, $newLat, $newLng) : 0;
                    if (!$hadCoords || $dist > 80) {
                        update_post_meta($postId, '_poi_lat', $newLat);
                        update_post_meta($postId, '_poi_lng', $newLng);
                        if (function_exists('error_log')) {
                            @error_log('[DB_POI_DISCOVERY] GPS corrected for POI ' . $postId . ' place ' . $googlePlaceId . ' dist=' . (int)$dist . 'm to ' . $newLat . ',' . $newLng);
                        }
                    }
                }
			}
			if ($tripadvisorId && get_post_meta($postId, '_poi_tripadvisor_location_id', true) === '') {
				update_post_meta($postId, '_poi_tripadvisor_location_id', $tripadvisorId);
				delete_post_meta($postId, '_poi_tripadvisor_cache');
				delete_post_meta($postId, '_poi_tripadvisor_cache_expires');
			}
		}

		return [
			'google_place_id' => $googlePlaceId,
			'tripadvisor_location_id' => $tripadvisorId,
			'debug' => [
				'title' => $title,
				'lat' => $hasCoords ? $lat : null,
				'lng' => $hasCoords ? $lng : null,
			],
		];
	}

	/**
	 * Zpracuje dávku POI bez externích ID.
	 *
	 * @param int  $limit Max počet POI
	 * @param bool $save  Uložit výsledek
	 * @param bool $withTripadvisor Zkusit i Tripadvisor
	 * @return array{processed:int, updated:int}
	 */
	public function discoverBatch(int $limit = 50, bool $save = false, bool $withTripadvisor = false): array {
		$args = [
			'post_type' => 'poi',
			'post_status' => 'publish',
			'posts_per_page' => $limit,
			'fields' => 'ids',
			'meta_query' => [
				'relation' => 'OR',
				[
					'key' => '_poi_google_place_id',
					'compare' => 'NOT EXISTS',
				],
				[
					'key' => '_poi_google_place_id',
					'value' => '',
					'compare' => '=',
				],
			],
		];
		$ids = get_posts($args);
		if (!is_array($ids)) $ids = [];

		$processed = 0;
		$updated = 0;
		foreach ($ids as $poiId) {
			$processed++;
			$result = $this->discoverForPoi((int)$poiId, $save, $withTripadvisor, true);
			if (($result['google_place_id'] ?? null) || ($result['tripadvisor_location_id'] ?? null)) {
				$updated++;
			}
		}
		return ['processed' => $processed, 'updated' => $updated];
	}

	private function discoverGooglePlaceId(string $title, ?float $lat, ?float $lng): ?string {
		$apiKey = (string) get_option('db_google_api_key');
		if ($apiKey === '') {
			return null;
		}

		$queryArgs = [
			'query' => $title,
			'key' => $apiKey,
			'language' => 'cs',
		];
		if ($lat !== null && $lng !== null) {
			$queryArgs['location'] = $lat . ',' . $lng;
			$queryArgs['radius'] = '1000';
		}

		$url = add_query_arg($queryArgs, self::GOOGLE_TEXTSEARCH_URL);
		$response = wp_remote_get($url, [
			'timeout' => 10,
			'user-agent' => 'DobityBaterky/poi-discovery (+https://dobitybaterky.cz)'
		]);
		if (is_wp_error($response)) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return null;
		}
		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string)$body, true);
		if (!is_array($data) || empty($data['results']) || !is_array($data['results'])) {
			return null;
		}

		$bestPlaceId = null;
		$bestScore = -INF;
		foreach ($data['results'] as $item) {
			$placeId = (string)($item['place_id'] ?? '');
			$name = (string)($item['name'] ?? '');
			$rating = isset($item['rating']) ? (float)$item['rating'] : 0.0;
			$score = 0.0;
			if ($title !== '' && $name !== '') {
				$score += similar_text(mb_strtolower($title), mb_strtolower($name));
			}
			$score += $rating * 10.0;
			if ($lat !== null && $lng !== null && isset($item['geometry']['location'])) {
				$ilat = (float)$item['geometry']['location']['lat'];
				$ilng = (float)$item['geometry']['location']['lng'];
				$distM = $this->haversineM($lat, $lng, $ilat, $ilng);
				$score += max(0.0, 2000.0 - min(2000.0, (float)$distM)) / 100.0; // bonus za blízkost
			}
			if ($placeId !== '' && $score > $bestScore) {
				$bestScore = $score;
				$bestPlaceId = $placeId;
			}
		}
		return $bestPlaceId ?: null;
	}

    /**
     * Vrátí geometrii místa z Google Place Details.
     * @return array{lat: float, lng: float}|null
     */
    private function fetchGooglePlaceGeometry(string $placeId): ?array {
        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '' || $placeId === '') {
            return null;
        }
        $url = add_query_arg(array(
            'place_id' => $placeId,
            'fields'   => 'geometry',
            'key'      => $apiKey,
        ), self::GOOGLE_DETAILS_URL);
        $response = wp_remote_get($url, array('timeout' => 10, 'user-agent' => 'DobityBaterky/poi-discovery (+https://dobitybaterky.cz)'));
        if (is_wp_error($response)) {
            return null;
        }
        $code = (int) wp_remote_retrieve_response_code($response);
        if ($code < 200 || $code >= 300) {
            return null;
        }
        $body = wp_remote_retrieve_body($response);
        $data = json_decode((string)$body, true);
        $loc = $data['result']['geometry']['location'] ?? null;
        if (!is_array($loc)) return null;
        $lat = isset($loc['lat']) ? (float)$loc['lat'] : null;
        $lng = isset($loc['lng']) ? (float)$loc['lng'] : null;
        if ($lat === null || $lng === null) return null;
        return array('lat' => $lat, 'lng' => $lng);
    }

	private function discoverTripadvisorLocationId(string $title, ?float $lat, ?float $lng): ?string {
		$apiKey = (string) get_option('db_tripadvisor_api_key');
		if ($apiKey === '') {
			return null;
		}
		$queryArgs = [
			'key' => $apiKey,
			'searchQuery' => $title,
			'language' => 'cs',
			'category' => 'restaurants,coffee,attractions',
		];
		if ($lat !== null && $lng !== null) {
			$queryArgs['latLong'] = $lat . ',' . $lng;
		}
		$url = add_query_arg($queryArgs, self::TRIPADVISOR_SEARCH_URL);
		$response = wp_remote_get($url, [
			'timeout' => 10,
			'user-agent' => 'DobityBaterky/poi-discovery (+https://dobitybaterky.cz)'
		]);
		if (is_wp_error($response)) {
			return null;
		}
		$code = (int) wp_remote_retrieve_response_code($response);
		if ($code < 200 || $code >= 300) {
			return null;
		}
		$body = wp_remote_retrieve_body($response);
		$data = json_decode((string)$body, true);
		if (!is_array($data) || empty($data['data']) || !is_array($data['data'])) {
			return null;
		}
		$bestId = null;
		$bestScore = -INF;
		foreach ($data['data'] as $row) {
			$id = (string)($row['location_id'] ?? '');
			$name = (string)($row['name'] ?? '');
			$score = 0.0;
			if ($title !== '' && $name !== '') {
				$score += similar_text(mb_strtolower($title), mb_strtolower($name));
			}
			if ($lat !== null && $lng !== null && isset($row['latitude']) && isset($row['longitude'])) {
				$ilat = (float)$row['latitude'];
				$ilng = (float)$row['longitude'];
				$distM = $this->haversineM($lat, $lng, $ilat, $ilng);
				$score += max(0.0, 2000.0 - min(2000.0, (float)$distM)) / 100.0;
			}
			if ($id !== '' && $score > $bestScore) {
				$bestScore = $score;
				$bestId = $id;
			}
		}
		return $bestId ?: null;
	}

	private function haversineM(float $lat1, float $lng1, float $lat2, float $lng2): int {
		$earthKm = 6371.0;
		$dLat = deg2rad($lat2 - $lat1);
		$dLng = deg2rad($lng2 - $lng1);
		$a = sin($dLat/2) * sin($dLat/2) + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng/2) * sin($dLng/2);
		$c = 2 * atan2(sqrt($a), sqrt(1-$a));
		return (int) round($earthKm * $c * 1000);
	}
}


