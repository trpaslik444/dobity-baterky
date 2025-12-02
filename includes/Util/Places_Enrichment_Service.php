<?php
/**
 * Centralized Google Places enrichment service with daily quota guard and
 * panic switch.
 */

namespace DB\Util;

use WP_Error;

if (!defined('ABSPATH')) { exit; }

class Places_Enrichment_Service {
    private const DEFAULT_MAX_REQUESTS = 300;
    private const DEFAULT_RECENT_DAYS = 7;
    private const ENV_MAX_REQUESTS = 'MAX_PLACES_REQUESTS_PER_DAY';
    private const ENV_ENABLED = 'PLACES_ENRICHMENT_ENABLED';
    private const ENV_RECENT_DAYS = 'PLACES_ENRICHMENT_CACHE_DAYS';

    private static $instance = null;
    private $usageTable;
    private $inFlight = array();

    private function __construct() {
        global $wpdb;
        $this->usageTable = $wpdb->prefix . 'db_places_usage';
    }

    public static function get_instance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function is_enabled(): bool {
        $flag = getenv(self::ENV_ENABLED);
        if ($flag === false) {
            return true;
        }
        return filter_var($flag, FILTER_VALIDATE_BOOLEAN);
    }

    public function get_recent_days(): int {
        $days = intval(getenv(self::ENV_RECENT_DAYS));
        return $days > 0 ? $days : self::DEFAULT_RECENT_DAYS;
    }

    public function get_daily_cap(): int {
        $limit = intval(getenv(self::ENV_MAX_REQUESTS));
        return $limit > 0 ? $limit : self::DEFAULT_MAX_REQUESTS;
    }

    public function request_place_details(string $placeId, array $context = array()) {
        $context = wp_parse_args($context, array(
            'post_id' => null,
            'force' => false,
            'endpoint' => 'places_details',
        ));

        $postId = $context['post_id'];
        $force = (bool) $context['force'];
        $endpoint = (string) $context['endpoint'];

        if (!$this->is_enabled()) {
            $this->log_usage($placeId, $endpoint, 'disabled');
            return array(
                'enriched' => false,
                'reason' => 'feature_flag_disabled',
            );
        }

        $key = $endpoint . ':' . $placeId;
        if (isset($this->inFlight[$key])) {
            return $this->inFlight[$key];
        }

        $quotaCheck = $this->reserve_quota($endpoint);
        if (is_wp_error($quotaCheck)) {
            $this->log_usage($placeId, $endpoint, 'quota_blocked');
            return array(
                'enriched' => false,
                'reason' => 'quota_exceeded',
            );
        }

        $response = $this->call_google_place_details($placeId);
        if (is_wp_error($response)) {
            $this->log_usage($placeId, $endpoint, 'error');
            return $response;
        }

        $this->log_usage($placeId, $endpoint, 'ok');
        $payload = array(
            'enriched' => true,
            'data' => $response,
        );

        $this->inFlight[$key] = $payload;
        return $payload;
    }

    private function reserve_quota(string $apiName) {
        global $wpdb;

        $limit = $this->get_daily_cap();
        if ($limit <= 0) {
            return true; // unlimited
        }

        $today = gmdate('Y-m-d');
        $table = $this->usageTable;

        // Ensure table exists before manipulating
        $this->maybe_create_table();

        $wpdb->query('START TRANSACTION');
        $row = $wpdb->get_row(
            $wpdb->prepare(
                "SELECT request_count FROM {$table} WHERE usage_date = %s AND api_name = %s FOR UPDATE",
                $today,
                $apiName
            ),
            ARRAY_A
        );

        if (!$row) {
            $wpdb->insert(
                $table,
                array(
                    'usage_date' => $today,
                    'api_name' => $apiName,
                    'request_count' => 0,
                ),
                array('%s', '%s', '%d')
            );
            $row = array('request_count' => 0);
        }

        $count = intval($row['request_count']);
        if ($count >= $limit) {
            $wpdb->query('ROLLBACK');
            return new WP_Error('places_quota_exceeded', 'Denní limit Google Places byl vyčerpán.');
        }

        $wpdb->update(
            $table,
            array('request_count' => $count + 1),
            array('usage_date' => $today, 'api_name' => $apiName),
            array('%d'),
            array('%s', '%s')
        );
        $wpdb->query('COMMIT');

        $this->maybe_notify_threshold($count + 1, $limit, $apiName);
        return true;
    }

    private function call_google_place_details(string $placeId) {
        $api_key = get_option('db_google_api_key');
        if (!$api_key) {
            return new WP_Error('no_api_key', 'Google API klíč není nastaven', array('status' => 500));
        }

        $url = 'https://maps.googleapis.com/maps/api/place/details/json';
        $args = array(
            'place_id' => $placeId,
            'fields' => 'name,formatted_address,geometry,types,place_id,formatted_phone_number,international_phone_number,website,rating,user_ratings_total,price_level,opening_hours,photos,icon,icon_background_color,icon_mask_base_uri,editorial_summary,url,vicinity,utc_offset,business_status,reviews,delivery,dine_in,takeout,serves_beer,serves_wine,serves_breakfast,serves_lunch,serves_dinner,wheelchair_accessible_entrance,curbside_pickup,reservable',
            'key' => $api_key,
            'language' => 'cs'
        );

        $url = add_query_arg($args, $url);
        $response = wp_remote_get($url, array('timeout' => 30));

        if (is_wp_error($response)) {
            return new WP_Error('google_api_error', 'Chyba při volání Google API: ' . $response->get_error_message(), array('status' => 500));
        }

        $status_code = wp_remote_retrieve_response_code($response);
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log('[PLACES_ENRICHMENT] Google Place Details API Response: ' . $body);

        if ($status_code !== 200) {
            return new WP_Error('google_api_error', 'Google API chyba: HTTP ' . $status_code, array('status' => 500));
        }

        if (isset($data['error_message'])) {
            return new WP_Error('google_api_error', 'Google API chyba: ' . $data['error_message'], array('status' => 500));
        }

        if (($data['status'] ?? '') !== 'OK') {
            return new WP_Error('google_api_error', 'Google API chyba: ' . ($data['status'] ?? 'UNKNOWN_ERROR'), array('status' => 500));
        }

        $result = $data['result'];
        $photos_all = array();
        if (isset($result['photos']) && is_array($result['photos']) && count($result['photos']) > 0) {
            foreach ($result['photos'] as $ph) {
                $photos_all[] = array(
                    'photoReference' => $ph['photo_reference'] ?? '',
                    'width' => $ph['width'] ?? 0,
                    'height' => $ph['height'] ?? 0,
                    'htmlAttributions' => $ph['html_attributions'] ?? array(),
                );
            }
        }

        return array(
            'placeId' => $result['place_id'],
            'displayName' => array('text' => $result['name']),
            'formattedAddress' => $result['formatted_address'] ?? '',
            'location' => array(
                'latitude' => $result['geometry']['location']['lat'],
                'longitude' => $result['geometry']['location']['lng'],
            ),
            'types' => $result['types'] ?? array(),
            'nationalPhoneNumber' => $result['formatted_phone_number'] ?? '',
            'internationalPhoneNumber' => $result['international_phone_number'] ?? '',
            'websiteUri' => $result['website'] ?? '',
            'rating' => $result['rating'] ?? 0,
            'userRatingCount' => $result['user_ratings_total'] ?? 0,
            'priceLevel' => $result['price_level'] ?? '',
            'regularOpeningHours' => $result['opening_hours'] ?? null,
            'photos' => $photos_all,
            'iconUri' => $result['icon'] ?? '',
            'iconBackgroundColor' => $result['icon_background_color'] ?? '',
            'iconMaskUri' => $result['icon_mask_base_uri'] ?? '',
            'editorialSummary' => $result['editorial_summary'] ?? null,
            'url' => $result['url'] ?? '',
            'vicinity' => $result['vicinity'] ?? '',
            'utcOffset' => $result['utc_offset'] ?? '',
            'businessStatus' => $result['business_status'] ?? '',
            'reviews' => $result['reviews'] ?? array(),
            'delivery' => $result['delivery'] ?? false,
            'dineIn' => $result['dine_in'] ?? false,
            'takeout' => $result['takeout'] ?? false,
            'servesBeer' => $result['serves_beer'] ?? false,
            'servesWine' => $result['serves_wine'] ?? false,
            'servesBreakfast' => $result['serves_breakfast'] ?? false,
            'servesLunch' => $result['serves_lunch'] ?? false,
            'servesDinner' => $result['serves_dinner'] ?? false,
            'wheelchairAccessibleEntrance' => $result['wheelchair_accessible_entrance'] ?? false,
            'curbsidePickup' => $result['curbside_pickup'] ?? false,
            'reservable' => $result['reservable'] ?? false,
            'rawData' => $result,
        );
    }

    private function log_usage(string $placeId, string $endpoint, string $status): void {
        $msg = sprintf('[PLACES_ENRICHMENT] place=%s endpoint=%s status=%s', $placeId, $endpoint, $status);
        error_log($msg);
    }

    private function maybe_notify_threshold(int $count, int $limit, string $apiName): void {
        if ($limit <= 0) {
            return;
        }
        $threshold = (int) floor($limit * 0.8);
        if ($count === $threshold) {
            $this->notifyAdminAboutQuotaThreshold($threshold, $count, $apiName);
        }
    }

    public function notifyAdminAboutQuotaThreshold(int $threshold, int $count, string $apiName): void {
        // TODO: Integrate with real notification channel (email/Slack/etc.).
        error_log(sprintf('[PLACES_ENRICHMENT] Quota %s/%s reached for %s', $count, $threshold, $apiName));
    }

    private function maybe_create_table(): void {
        global $wpdb;
        $table_name = $this->usageTable;
        $charset_collate = $wpdb->get_charset_collate();
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';
        $sql = "CREATE TABLE {$table_name} (
            usage_date DATE NOT NULL,
            api_name VARCHAR(64) NOT NULL,
            request_count INT UNSIGNED NOT NULL DEFAULT 0,
            PRIMARY KEY  (usage_date, api_name),
            KEY api_name_idx (api_name)
        ) {$charset_collate};";
        dbDelta($sql);
    }
}
