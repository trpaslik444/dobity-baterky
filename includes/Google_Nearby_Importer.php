<?php
declare(strict_types=1);

namespace DB;

use DB\Jobs\Nearby_Cron_Tools;
use DB\Jobs\POI_Quota_Manager;
use WP_Error;

if (!defined('ABSPATH')) {
    exit;
}

/**
 * On-demand enrichment of charging locations with nearby Google Places POIs.
 */
class Google_Nearby_Importer
{
    private const OPTION_FILTERS = 'db_google_nearby_filters';
    private const CACHE_TTL_DAYS = 30;
    private const DEFAULT_MAX_RESULTS = 12;
    private const DEFAULT_RADIUS_METERS = 2000;
    private const DEFAULT_COOLDOWN_HOURS = 24;
    private const CHARGING_META_LAST_RUN = '_charging_google_nearby_last_sync';
    private const CHARGING_META_LOCK = '_charging_google_nearby_lock';
    private const POI_META_LINKED_CHARGING = '_poi_related_charging';
    private const CHARGING_META_LINKED_POI = '_charging_related_poi';
    private const FALLBACK_ICON_SLUG = 'db-fallback-pin.svg';
    private const ASYNC_ACTION_HOOK = 'db_google_nearby_import';
    private const ASYNC_ACTION_GROUP = 'db-google-nearby';

    private static $asyncHooksRegistered = false;

    private const FALLBACK_ICON_CONTENT = <<<SVG
<?xml version="1.0" ?>
<!-- License: CC0 License. Made by SVG Repo: https://www.svgrepo.com/svg/384390/flag-location-map-marker-pin-pointer -->
<svg width="800px" height="800px" viewBox="0 0 60 60" id="flag" xmlns="http://www.w3.org/2000/svg"><defs><style>
    </style></defs><path d="M386.326,838.641c0-7.762-20.367,5.33-26.3,5.33V811.993c8.7,0.3,25.359,3,26.3,10.659,1.188,9.623,23.674,5.331,23.674,5.331S386.326,848.264,386.326,838.641Z" data-name="flag" id="flag-2" transform="translate(-350 -810)"/><path d="M353,810a3,3,0,0,1,3,3v54a3,3,0,0,1-6,0V813A3,3,0,0,1,353,810Z" data-name="flag copy" id="flag_copy" transform="translate(-350 -810)"/></svg>
SVG;

    public static function register_async_hooks(): void
    {
        if (self::$asyncHooksRegistered) {
            return;
        }

        add_action(self::ASYNC_ACTION_HOOK, [self::class, 'handle_async_import'], 10, 1);
        self::$asyncHooksRegistered = true;
    }

    public static function handle_async_import($chargingId): void
    {
        $chargingId = (int) $chargingId;
        if ($chargingId <= 0) {
            return;
        }

        $importer = new self();
        $importer->run_import_now($chargingId, $importer->get_config());
    }

    /**
     * Trigger enrichment for a charging location when the detail is requested.
     */
    public function maybe_import_for_charging(int $chargingId): array
    {
        $config = $this->get_config();
        $precheck = $this->precheck($chargingId, $config);
        $result = $precheck['result'];

        if (!$precheck['ok']) {
            return $result;
        }

        $mode = isset($config['import_mode']) ? $config['import_mode'] : 'sync';
        if ($mode === 'async') {
            $queue = $this->queue_async_import($chargingId);
            if ($queue['queued']) {
                $result['queued'] = true;
                $result['reason'] = $queue['reason'] ?? 'queued';
            } else {
                $result['reason'] = $queue['reason'] ?? 'queue_failed';
            }
            return $result;
        }

        return $this->run_import_now($chargingId, $config, $precheck);
    }

    private function run_import_now(int $chargingId, array $config, ?array $precheck = null): array
    {
        $precheck = $precheck ?? $this->precheck($chargingId, $config);
        $result = $precheck['result'];

        if (!$precheck['ok']) {
            return $result;
        }

        $lat = $precheck['lat'];
        $lng = $precheck['lng'];
        $apiKey = $precheck['apiKey'];

        $lockKey = self::CHARGING_META_LOCK . '_' . $chargingId;
        if (get_transient($lockKey)) {
            $result['reason'] = 'in_progress';
            return $result;
        }

        set_transient($lockKey, 1, MINUTE_IN_SECONDS * 5);

        $quota = new POI_Quota_Manager();
        if (!$quota->can_use_google()) {
            $result['reason'] = 'quota_exhausted';
            delete_transient($lockKey);
            return $result;
        }

        $response = $this->call_search_nearby($apiKey, $lat, $lng, $config);
        if (is_wp_error($response)) {
            $result['reason'] = $response->get_error_code();
            delete_transient($lockKey);
            return $result;
        }

        $result['ran'] = true;
        $places = (array) ($response['places'] ?? []);
        $result['places'] = count($places);

        if (empty($places)) {
            $result['reason'] = 'empty';
            update_post_meta($chargingId, self::CHARGING_META_LAST_RUN, time());
            delete_transient($lockKey);
            $quota->record_google(1);
            return $result;
        }

        $this->ensure_fallback_icon();

        foreach ($places as $place) {
            if (!is_array($place)) {
                continue;
            }
            try {
                if (!$this->passes_filters($place, $config)) {
                    $result['skipped']++;
                    continue;
                }

                $processed = $this->upsert_poi_from_place($place, $chargingId, $config);
                if ($processed['status'] === 'created') {
                    $result['created']++;
                } elseif ($processed['status'] === 'updated') {
                    $result['updated']++;
                }
                if ($processed['linked']) {
                    $result['linked']++;
                }
            } catch (\Throwable $e) {
                $result['skipped']++;
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Google Nearby Importer] Skipping place due to error: ' . $e->getMessage());
                }
            }
        }

        update_post_meta($chargingId, self::CHARGING_META_LAST_RUN, time());
        delete_transient($lockKey);
        $quota->record_google(1);

        if ($result['created'] > 0 || $result['updated'] > 0 || $result['linked'] > 0) {
            $this->invalidate_nearby_cache($chargingId);
        }

        return $result;
    }

    private function queue_async_import(int $chargingId): array
    {
        $quota = new POI_Quota_Manager();
        if (!$quota->can_use_google()) {
            return [
                'queued' => false,
                'reason' => 'quota_exhausted',
            ];
        }

        $args = ['charging_id' => (int) $chargingId];

        if (function_exists('as_next_scheduled_action')) {
            $next = as_next_scheduled_action(self::ASYNC_ACTION_HOOK, $args, self::ASYNC_ACTION_GROUP);
            if ($next !== false) {
                return [
                    'queued' => true,
                    'reason' => 'already_queued',
                ];
            }
        }

        if (function_exists('as_schedule_single_action')) {
            as_schedule_single_action(time() + 5, self::ASYNC_ACTION_HOOK, $args, self::ASYNC_ACTION_GROUP);
            return [
                'queued' => true,
                'reason' => 'queued',
            ];
        }

        if (function_exists('as_enqueue_async_action')) {
            as_enqueue_async_action(self::ASYNC_ACTION_HOOK, $args, self::ASYNC_ACTION_GROUP);
            return [
                'queued' => true,
                'reason' => 'queued',
            ];
        }

        if (wp_next_scheduled(self::ASYNC_ACTION_HOOK, [$chargingId])) {
            return [
                'queued' => true,
                'reason' => 'already_queued',
            ];
        }

        $scheduled = wp_schedule_single_event(time() + 5, self::ASYNC_ACTION_HOOK, [$chargingId]);
        if ($scheduled) {
            return [
                'queued' => true,
                'reason' => 'queued',
            ];
        }

        return [
            'queued' => false,
            'reason' => 'queue_failed',
        ];
    }

    private function precheck(int $chargingId, array $config): array
    {
        $result = $this->create_result();

        $post = get_post($chargingId);
        if (!$post || $post->post_type !== 'charging_location') {
            $result['reason'] = 'invalid_post';
            return [
                'ok' => false,
                'result' => $result,
            ];
        }

        $lat = (float) get_post_meta($chargingId, '_db_lat', true);
        $lng = (float) get_post_meta($chargingId, '_db_lng', true);
        if (!$lat || !$lng) {
            $result['reason'] = 'missing_coordinates';
            return [
                'ok' => false,
                'result' => $result,
            ];
        }

        $apiKey = (string) get_option('db_google_api_key');
        if ($apiKey === '') {
            $result['reason'] = 'missing_api_key';
            return [
                'ok' => false,
                'result' => $result,
            ];
        }

        $lastRun = (int) get_post_meta($chargingId, self::CHARGING_META_LAST_RUN, true);
        $cooldownSeconds = max(1, (int) ($config['cooldown_hours'] ?? self::DEFAULT_COOLDOWN_HOURS)) * HOUR_IN_SECONDS;
        if ($lastRun && ($lastRun + $cooldownSeconds) > time()) {
            $result['reason'] = 'cooldown_active';
            return [
                'ok' => false,
                'result' => $result,
            ];
        }

        $lockKey = self::CHARGING_META_LOCK . '_' . $chargingId;
        if (get_transient($lockKey)) {
            $result['reason'] = 'in_progress';
            return [
                'ok' => false,
                'result' => $result,
            ];
        }

        return [
            'ok' => true,
            'result' => $result,
            'lat' => $lat,
            'lng' => $lng,
            'apiKey' => $apiKey,
        ];
    }

    private function create_result(): array
    {
        return [
            'ran'      => false,
            'queued'   => false,
            'reason'   => null,
            'created'  => 0,
            'updated'  => 0,
            'linked'   => 0,
            'skipped'  => 0,
            'places'   => 0,
        ];
    }

    private function call_search_nearby(string $apiKey, float $lat, float $lng, array $config)
    {
        $includedTypes = array_values(array_filter(array_map('strval', (array) ($config['included_types'] ?? [])), static function ($type) {
            return $type !== '';
        }));
        // Whitelist základních v1 typů (konzervativní průnik běžně používaných)
        $allowedTypes = [
            'cafe','restaurant','bar','bakery','supermarket','shopping_mall','store',
            'tourist_attraction','museum','art_gallery','movie_theater','park','playground',
            'gym','spa','hair_care','beauty_salon','lodging','hotel','night_club','bowling_alley',
            'aquarium','zoo','library','tourist_information_center','viewpoint'
        ];
        $includedTypes = array_values(array_intersect(array_map('strtolower', $includedTypes), $allowedTypes));
        if (empty($includedTypes)) {
            // Minimální sada, která by měla být vždy validní
            $includedTypes = ['cafe','restaurant','tourist_attraction','lodging'];
        }

        $body = [
            'includedTypes' => $includedTypes,
            'maxResultCount' => (int) ($config['max_results'] ?? self::DEFAULT_MAX_RESULTS),
            'languageCode' => get_locale() ?: 'cs',
            'rankPreference' => 'RELEVANCE',
            'locationRestriction' => [
                'circle' => [
                    'center' => [
                        'latitude' => $lat,
                        'longitude' => $lng,
                    ],
                    'radius' => (int) ($config['radius_m'] ?? self::DEFAULT_RADIUS_METERS),
                ],
            ],
        ];

        // Primární (širší) FieldMask – může selhat 400 INVALID_ARGUMENT u některých účtů/konfigurací
        $fieldMaskFull = 'places.id,places.displayName,places.location,places.formattedAddress,places.primaryType,places.types,places.rating,places.userRatingCount,places.priceLevel,places.regularOpeningHours,places.shortFormattedAddress,places.iconMaskBaseUri,places.iconBackgroundColor,places.nationalPhoneNumber,places.internationalPhoneNumber,places.websiteUri,places.editorialSummary';
        // Konzervativní FieldMask – pouze bezpečná pole podporovaná v Nearby Search
        $fieldMaskSafe = 'places.id,places.displayName,places.location,places.formattedAddress,places.primaryType,places.types,places.rating,places.userRatingCount,places.priceLevel,places.shortFormattedAddress,places.iconMaskBaseUri,places.iconBackgroundColor';

        $make_request = function(string $mask) use ($apiKey, $body) {
            return wp_remote_post('https://places.googleapis.com/v1/places:searchNearby', [
                'headers' => [
                    'X-Goog-Api-Key' => $apiKey,
                    'X-Goog-FieldMask' => $mask,
                    'Content-Type' => 'application/json; charset=utf-8',
                ],
                'body' => wp_json_encode($body),
                'timeout' => 12,
            ]);
        };

        $res = $make_request($fieldMaskFull);
        if (is_wp_error($res)) {
            return $res;
        }
        $code = (int) wp_remote_retrieve_response_code($res);
        if ($code === 400) {
            // Zalogovat tělo odpovědi kvůli diagnostice a zkusit znovu s bezpečnou maskou
            $raw = wp_remote_retrieve_body($res);
            if (defined('WP_DEBUG') && WP_DEBUG) {
                error_log('[Google Nearby Importer] 400 on searchNearby with full FieldMask. Body: ' . substr((string)$raw, 0, 500));
            }
            // 1) zkus bezpečnou masku se stejnými typy
            $res = $make_request($fieldMaskSafe);
            $code = (int) wp_remote_retrieve_response_code($res);
            if ($code === 400) {
                // 2) zkus minimální sadu typů (kdyby v configu byl nepodporovaný typ)
                $fallbackBody = $body;
                $fallbackBody['includedTypes'] = ['cafe','restaurant','tourist_attraction','lodging'];
                $res = wp_remote_post('https://places.googleapis.com/v1/places:searchNearby', [
                    'headers' => [
                        'X-Goog-Api-Key' => $apiKey,
                        'X-Goog-FieldMask' => $fieldMaskSafe,
                        'Content-Type' => 'application/json; charset=utf-8',
                    ],
                    'body' => wp_json_encode($fallbackBody),
                    'timeout' => 12,
                ]);
                $code = (int) wp_remote_retrieve_response_code($res);
            }
        }

        if ($code < 200 || $code >= 300) {
            $err = new WP_Error('http_' . $code, 'HTTP ' . $code);
            $err->add_data([
                'body' => wp_remote_retrieve_body($res),
                'fieldMaskUsed' => ($code === 400 ? 'safe' : 'full'),
            ]);
            return $err;
        }

        $body = wp_remote_retrieve_body($res);
        $json = json_decode((string) $body, true);
        if (!is_array($json)) {
            return new WP_Error('invalid_json', 'Invalid JSON response from Google Places Nearby');
        }

        return $json;
    }

    private function passes_filters(array $place, array $config): bool
    {
        $minRating = (float) ($config['min_rating'] ?? 0.0);
        $rating = isset($place['rating']) ? (float) $place['rating'] : 0.0;
        if ($minRating > 0 && $rating > 0 && $rating < $minRating) {
            return false;
        }

        $types = array_map('strval', (array) ($place['types'] ?? []));
        $types = array_map('strtolower', $types);
        $allowed = array_map('strtolower', (array) $config['included_types']);
        $allowed = array_filter($allowed, static function ($type) {
            return $type !== '';
        });

        if (!empty($allowed)) {
            $matched = array_intersect($types, $allowed);
            if (empty($matched)) {
                return false;
            }
        }

        return true;
    }

    private function upsert_poi_from_place(array $place, int $chargingId, array $config): array
    {
        $placeId = (string) ($place['id'] ?? '');
        if ($placeId === '') {
            return ['status' => 'skipped', 'linked' => false];
        }

        $existingId = $this->find_poi_by_place_id($placeId);

        if ($existingId) {
            $linked = $this->link_relationship($existingId, $chargingId, $place);
            $manualOverride = get_post_meta($existingId, '_poi_manual_override', true) === '1';
            $cacheExpires = (int) get_post_meta($existingId, '_poi_google_cache_expires', true);
            $shouldRefresh = !$manualOverride && ($cacheExpires === 0 || $cacheExpires < time());
            if ($shouldRefresh) {
                $this->update_poi_from_place($existingId, $place, $config, false);
                return ['status' => 'updated', 'linked' => $linked];
            }
            return ['status' => 'existing', 'linked' => $linked];
        }

        $poiId = $this->create_poi_from_place($place, $config);
        if ($poiId) {
            $this->link_relationship($poiId, $chargingId, $place);
            return ['status' => 'created', 'linked' => true];
        }

        return ['status' => 'failed', 'linked' => false];
    }

    private function find_poi_by_place_id(string $placeId): ?int
    {
        $query = new \WP_Query([
            'post_type' => 'poi',
            'post_status' => ['publish', 'pending', 'draft'],
            'meta_key' => '_poi_google_place_id',
            'meta_value' => $placeId,
            'fields' => 'ids',
            'posts_per_page' => 1,
            'no_found_rows' => true,
        ]);

        if (!empty($query->posts)) {
            return (int) $query->posts[0];
        }

        return null;
    }

    private function create_poi_from_place(array $place, array $config): ?int
    {
        $title = $this->extract_display_name($place);
        if ($title === '') {
            $title = __('Nový podnik', 'dobity-baterky');
        }

        $poiId = wp_insert_post([
            'post_type' => 'poi',
            'post_status' => 'publish',
            'post_title' => $title,
            'post_name' => sanitize_title($title),
            'post_author' => get_current_user_id() ?: 0,
            'post_content' => $this->extract_editorial_summary($place),
        ], true);

        if (is_wp_error($poiId)) {
            return null;
        }

        $poiId = (int) $poiId;
        update_post_meta($poiId, '_poi_google_place_id', (string) ($place['id'] ?? ''));
        update_post_meta($poiId, '_poi_place_source', 'google_places');
        update_post_meta($poiId, '_poi_manual_override', '0');

        $this->update_poi_from_place($poiId, $place, $config, true);

        return $poiId;
    }

    private function update_poi_from_place(int $poiId, array $place, array $config, bool $isCreation): void
    {
        $latLng = $place['location']['latLng'] ?? null;
        if (is_array($latLng)) {
            $lat = isset($latLng['latitude']) ? (float) $latLng['latitude'] : null;
            $lng = isset($latLng['longitude']) ? (float) $latLng['longitude'] : null;
            if ($lat !== null) {
                update_post_meta($poiId, '_poi_lat', $lat);
            }
            if ($lng !== null) {
                update_post_meta($poiId, '_poi_lng', $lng);
            }
        }

        $address = (string) ($place['formattedAddress'] ?? ($place['shortFormattedAddress'] ?? ''));
        if ($address !== '') {
            update_post_meta($poiId, '_poi_address', $address);
        }

        $primaryType = (string) ($place['primaryType'] ?? '');
        if ($primaryType !== '') {
            update_post_meta($poiId, '_poi_main_type', $primaryType);
        }

        $types = array_map('strval', (array) ($place['types'] ?? []));
        $types = array_values(array_unique($types));
        if (!empty($types)) {
            update_post_meta($poiId, '_poi_types', $types);
        }

        $rating = isset($place['rating']) ? (float) $place['rating'] : null;
        if ($rating !== null) {
            update_post_meta($poiId, '_poi_rating', $rating);
        }

        if (isset($place['userRatingCount'])) {
            update_post_meta($poiId, '_poi_user_rating_count', (int) $place['userRatingCount']);
        }

        if (isset($place['priceLevel'])) {
            update_post_meta($poiId, '_poi_price_level', sanitize_text_field((string) $place['priceLevel']));
        }

        if (isset($place['websiteUri'])) {
            update_post_meta($poiId, '_poi_website', esc_url_raw((string) $place['websiteUri']));
        }

        if (isset($place['internationalPhoneNumber'])) {
            update_post_meta($poiId, '_poi_phone', sanitize_text_field((string) $place['internationalPhoneNumber']));
        } elseif (isset($place['nationalPhoneNumber'])) {
            update_post_meta($poiId, '_poi_phone', sanitize_text_field((string) $place['nationalPhoneNumber']));
        }

        $cache = [
            'rating' => $rating,
            'user_rating_count' => isset($place['userRatingCount']) ? (int) $place['userRatingCount'] : null,
            'price_level' => $place['priceLevel'] ?? null,
            'types' => $types,
            'primary_type' => $primaryType,
            'formatted_address' => $address,
            'regular_opening_hours' => $place['regularOpeningHours'] ?? null,
            'website' => $place['websiteUri'] ?? null,
            'phone' => $place['internationalPhoneNumber'] ?? ($place['nationalPhoneNumber'] ?? null),
            'icon_mask_uri' => $place['iconMaskBaseUri'] ?? null,
            'icon_background_color' => $place['iconBackgroundColor'] ?? null,
            'fetched_at' => time(),
        ];
        update_post_meta($poiId, '_poi_google_cache', $cache);
        update_post_meta($poiId, '_poi_google_cache_expires', time() + (self::CACHE_TTL_DAYS * DAY_IN_SECONDS));

        $this->assign_terms_for_types($poiId, $types, $place, $config);

        if ($isCreation) {
            clean_post_cache($poiId);
        }
    }

    private function link_relationship(int $poiId, int $chargingId, array $place): bool
    {
        $distance = $this->calculate_distance($poiId, $chargingId, $place);

        $poiLinked = get_post_meta($poiId, self::POI_META_LINKED_CHARGING, true);
        if (!is_array($poiLinked)) {
            $poiLinked = [];
        }
        $poiLinked[(string) $chargingId] = [
            'distance_m' => $distance,
            'source' => 'google_places',
            'updated_at' => time(),
        ];
        update_post_meta($poiId, self::POI_META_LINKED_CHARGING, $poiLinked);

        $chargingLinked = get_post_meta($chargingId, self::CHARGING_META_LINKED_POI, true);
        if (!is_array($chargingLinked)) {
            $chargingLinked = [];
        }
        $chargingLinked[(string) $poiId] = [
            'distance_m' => $distance,
            'source' => 'google_places',
            'updated_at' => time(),
        ];
        update_post_meta($chargingId, self::CHARGING_META_LINKED_POI, $chargingLinked);

        return true;
    }

    private function calculate_distance(int $poiId, int $chargingId, array $place): int
    {
        $poiLat = null;
        $poiLng = null;
        $location = $place['location']['latLng'] ?? null;
        if (is_array($location)) {
            $poiLat = isset($location['latitude']) ? (float) $location['latitude'] : null;
            $poiLng = isset($location['longitude']) ? (float) $location['longitude'] : null;
        }

        if ($poiLat === null || $poiLng === null) {
            $poiLat = (float) get_post_meta($poiId, '_poi_lat', true);
            $poiLng = (float) get_post_meta($poiId, '_poi_lng', true);
        }

        $chargingLat = (float) get_post_meta($chargingId, '_db_lat', true);
        $chargingLng = (float) get_post_meta($chargingId, '_db_lng', true);

        if (!$poiLat || !$poiLng || !$chargingLat || !$chargingLng) {
            return 0;
        }

        $earthRadius = 6371000;
        $dLat = deg2rad($chargingLat - $poiLat);
        $dLng = deg2rad($chargingLng - $poiLng);
        $a = sin($dLat / 2) * sin($dLat / 2) + cos(deg2rad($poiLat)) * cos(deg2rad($chargingLat)) * sin($dLng / 2) * sin($dLng / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return (int) round($earthRadius * $c);
    }

    private function assign_terms_for_types(int $poiId, array $types, array $place, array $config): void
    {
        $termIds = [];
        foreach ($this->map_types_to_terms($types) as $termData) {
            $term = $this->ensure_term_exists($termData['slug'], $termData['name']);
            if ($term) {
                $termIds[] = (int) $term->term_id;
                $this->maybe_assign_icon($term, $place);
                if (!empty($termData['google_type'])) {
                    update_term_meta($term->term_id, 'google_type', $termData['google_type']);
                }
            }
        }

        if (!empty($termIds)) {
            wp_set_post_terms($poiId, $termIds, 'poi_type', true);
        }
    }

    private function map_types_to_terms(array $types): array
    {
        $map = [
            'cafe' => ['slug' => 'kavarna', 'name' => __('Kavárna', 'dobity-baterky')],
            'coffee_shop' => ['slug' => 'kavarna', 'name' => __('Kavárna', 'dobity-baterky')],
            'restaurant' => ['slug' => 'restaurace', 'name' => __('Restaurace', 'dobity-baterky')],
            'bar' => ['slug' => 'bar', 'name' => __('Bar', 'dobity-baterky')],
            'bakery' => ['slug' => 'pekarstvi', 'name' => __('Pekařství', 'dobity-baterky')],
            'supermarket' => ['slug' => 'supermarket', 'name' => __('Supermarket', 'dobity-baterky')],
            'shopping_mall' => ['slug' => 'nakupni-centrum', 'name' => __('Nákupní centrum', 'dobity-baterky')],
            'store' => ['slug' => 'obchod', 'name' => __('Obchod', 'dobity-baterky')],
            'tourist_attraction' => ['slug' => 'turisticka-atrakce', 'name' => __('Turistická atrakce', 'dobity-baterky')],
            'museum' => ['slug' => 'muzeum', 'name' => __('Muzeum', 'dobity-baterky')],
            'art_gallery' => ['slug' => 'galerie', 'name' => __('Galerie', 'dobity-baterky')],
            'movie_theater' => ['slug' => 'kino', 'name' => __('Kino', 'dobity-baterky')],
            'theater' => ['slug' => 'divadlo', 'name' => __('Divadlo', 'dobity-baterky')],
            'park' => ['slug' => 'park', 'name' => __('Park', 'dobity-baterky')],
            'playground' => ['slug' => 'detske-hriste', 'name' => __('Dětské hřiště', 'dobity-baterky')],
            'gym' => ['slug' => 'fitcentrum', 'name' => __('Sportovní vyžití', 'dobity-baterky')],
            'spa' => ['slug' => 'spa', 'name' => __('Wellness & Spa', 'dobity-baterky')],
            'hair_care' => ['slug' => 'holicstvi', 'name' => __('Kadeřnictví / holičství', 'dobity-baterky')],
            'beauty_salon' => ['slug' => 'salon', 'name' => __('Beauty salon', 'dobity-baterky')],
            'lodging' => ['slug' => 'ubytovani', 'name' => __('Ubytování', 'dobity-baterky')],
            'hotel' => ['slug' => 'hotel', 'name' => __('Hotel', 'dobity-baterky')],
            'night_club' => ['slug' => 'nocni-klub', 'name' => __('Noční klub', 'dobity-baterky')],
            'bowling_alley' => ['slug' => 'bowling', 'name' => __('Bowling', 'dobity-baterky')],
            'aquarium' => ['slug' => 'akvarium', 'name' => __('Akvárium', 'dobity-baterky')],
            'zoo' => ['slug' => 'zoo', 'name' => __('Zoo', 'dobity-baterky')],
            'library' => ['slug' => 'knihovna', 'name' => __('Knihovna', 'dobity-baterky')],
            'tourist_information_center' => ['slug' => 'infocentrum', 'name' => __('Infocentrum', 'dobity-baterky')],
            'viewpoint' => ['slug' => 'vyhlidka', 'name' => __('Vyhlídka', 'dobity-baterky')],
            'hiking_area' => ['slug' => 'turistika', 'name' => __('Turistika', 'dobity-baterky')],
        ];

        $result = [];
        foreach ($types as $type) {
            $type = strtolower((string) $type);
            if ($type === '') {
                continue;
            }
            if (isset($map[$type])) {
                $result[$map[$type]['slug']] = $map[$type] + ['google_type' => $type];
            } else {
                $slug = sanitize_title($type);
                if ($slug === '') {
                    continue;
                }
                $name = ucwords(str_replace(['_', '-'], [' ', ' '], $type));
                $result[$slug] = ['slug' => $slug, 'name' => $name, 'google_type' => $type];
            }
        }

        return array_values($result);
    }

    private function ensure_term_exists(string $slug, string $name)
    {
        $existing = get_term_by('slug', $slug, 'poi_type');
        if ($existing && !is_wp_error($existing)) {
            return $existing;
        }

        $created = wp_insert_term($name, 'poi_type', ['slug' => $slug]);
        if (is_wp_error($created)) {
            return $existing && !is_wp_error($existing) ? $existing : null;
        }

        $termId = is_array($created) ? (int) ($created['term_id'] ?? 0) : (int) $created;
        if ($termId > 0) {
            return get_term($termId, 'poi_type');
        }

        return $existing && !is_wp_error($existing) ? $existing : null;
    }

    private function maybe_assign_icon($term, array $place): void
    {
        if (!is_object($term) || !isset($term->term_id)) {
            return;
        }

        $currentSlug = (string) get_term_meta($term->term_id, 'icon_slug', true);
        if ($currentSlug !== '') {
            return;
        }

        $iconMask = (string) ($place['iconMaskBaseUri'] ?? '');
        $backgroundColor = (string) ($place['iconBackgroundColor'] ?? '');

        if ($iconMask !== '') {
            $saved = $this->download_icon($iconMask, $term->slug);
            if ($saved) {
                update_term_meta($term->term_id, 'icon_slug', $saved);
                if ($backgroundColor !== '') {
                    update_term_meta($term->term_id, 'color_hex', $backgroundColor);
                }
                update_term_meta($term->term_id, 'icon_source', 'google');
                update_term_meta($term->term_id, 'icon_mask_uri', $iconMask);
                return;
            }
        }

        update_term_meta($term->term_id, 'icon_slug', self::FALLBACK_ICON_SLUG);
        update_term_meta($term->term_id, 'color_hex', $backgroundColor !== '' ? $backgroundColor : '#2563EB');
        update_term_meta($term->term_id, 'icon_source', 'fallback');
    }

    private function download_icon(string $iconMaskUri, string $termSlug): ?string
    {
        $ext = '.svg';
        if (strpos($iconMaskUri, '.png') !== false) {
            $ext = '.png';
        }
        $iconSlug = sanitize_title($termSlug) . '-google' . $ext;
        $iconsDir = DB_PLUGIN_DIR . 'assets/icons/';
        if (!file_exists($iconsDir)) {
            wp_mkdir_p($iconsDir);
        }

        $response = wp_remote_get($iconMaskUri, ['timeout' => 10]);
        if (is_wp_error($response)) {
            return null;
        }

        $body = wp_remote_retrieve_body($response);
        if ($body === '') {
            return null;
        }

        $path = $iconsDir . $iconSlug;
        $written = file_put_contents($path, $body);
        if ($written === false) {
            return null;
        }

        return $iconSlug;
    }

    private function invalidate_nearby_cache(int $chargingId): void
    {
        delete_post_meta($chargingId, '_db_nearby_cache_poi_foot');
        wp_cache_delete('db_nearby_processed_' . $chargingId . '_charging_location', 'db_nearby');

        if (class_exists(Nearby_Cron_Tools::class)) {
            Nearby_Cron_Tools::schedule_recompute(120, $chargingId, 'poi');
        }
    }

    private function extract_display_name(array $place): string
    {
        if (!empty($place['displayName']['text'])) {
            return (string) $place['displayName']['text'];
        }
        return trim((string) ($place['formattedAddress'] ?? ''));
    }

    private function extract_editorial_summary(array $place): string
    {
        if (!empty($place['editorialSummary']['text'])) {
            return (string) $place['editorialSummary']['text'];
        }
        return '';
    }

    private function ensure_fallback_icon(): void
    {
        $iconsDir = DB_PLUGIN_DIR . 'assets/icons/';
        if (!file_exists($iconsDir)) {
            wp_mkdir_p($iconsDir);
        }
        $path = $iconsDir . self::FALLBACK_ICON_SLUG;
        if (!file_exists($path)) {
            file_put_contents($path, self::FALLBACK_ICON_CONTENT);
        }
    }

    private function get_config(): array
    {
        $defaults = [
            'min_rating' => 3.5,
            'included_types' => [
                'cafe', 'restaurant', 'bar', 'bakery', 'supermarket',
                'shopping_mall', 'store', 'tourist_attraction', 'museum',
                'art_gallery', 'movie_theater', 'park', 'playground',
                'gym', 'spa', 'hair_care', 'lodging', 'hotel', 'viewpoint',
            ],
            'max_results' => self::DEFAULT_MAX_RESULTS,
            'radius_m' => self::DEFAULT_RADIUS_METERS,
            'cooldown_hours' => self::DEFAULT_COOLDOWN_HOURS,
            'import_mode' => 'sync',
        ];

        $option = get_option(self::OPTION_FILTERS, []);
        if (!is_array($option)) {
            $option = [];
        }

        $included = $option['included_types'] ?? $defaults['included_types'];
        if (is_string($included)) {
            $included = preg_split('/[\s,]+/', $included);
        }
        $included = is_array($included) ? array_values(array_filter(array_map('strval', $included), static function ($item) {
            return $item !== '';
        })) : $defaults['included_types'];

        return [
            'min_rating' => isset($option['min_rating']) ? (float) $option['min_rating'] : $defaults['min_rating'],
            'included_types' => $included,
            'max_results' => isset($option['max_results']) ? max(1, min(20, (int) $option['max_results'])) : $defaults['max_results'],
            'radius_m' => isset($option['radius_m']) ? max(100, min(10000, (int) $option['radius_m'])) : $defaults['radius_m'],
            'cooldown_hours' => isset($option['cooldown_hours']) ? max(1, min(168, (int) $option['cooldown_hours'])) : $defaults['cooldown_hours'],
            'import_mode' => isset($option['import_mode']) && in_array($option['import_mode'], ['sync', 'async'], true)
                ? $option['import_mode']
                : $defaults['import_mode'],
        ];
    }
}
