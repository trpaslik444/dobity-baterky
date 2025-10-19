<?php
/**
 * Mapy.com Service - Vyhledávání POI přes Mapy.com API
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Db_Mapy_Service {

    private string $apiKey;

    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey ?? get_option('db_mapy_api_key', '');
    }

    /**
     * Vyhledá POI kolem bodu (preferNear). query můžeš přepínat (CS/EN).
     * Vrací pole items (raw z Mapy.com).
     */
    public function findChargersNear(float $lat, float $lon, int $radius_m = 3000, int $limit = 20): array {
        $cache_key = sprintf('db_mapy_poi_%s_%s_%d_%d', round($lat,5), round($lon,5), $radius_m, $limit);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $endpoint = 'https://api.mapy.com/v1/geocode';
        $params = [
            'query' => 'nabíjecí stanice',         // zkus i 'charging station' (EN), 'EV charger'
            'type'  => 'poi',
            'lang'  => 'cs',
            'limit' => $limit,
            // POZOR: pořadí lon,lat
            'preferNear' => $lon . ',' . $lat,
            'preferNearPrecision' => $radius_m,
            'apikey' => $this->apiKey
        ];
        $url = $endpoint . '?' . http_build_query($params);

        $res = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($res)) {
            error_log('[Mapy Service] Request error: ' . $res->get_error_message());
            return [];
        }
        
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $items = $data['items'] ?? [];
        
        set_transient($cache_key, $items, DAY_IN_SECONDS); // 24 h cache
        return $items;
    }

    /** Vytvoří deeplink na Mapy.com (otevře web/app), + univerzální geo: */
    public static function buildDeepLinks(float $lat, float $lon): array {
        // Nové URL schéma Mapy.com (fnc v1)
        $mapy_url = sprintf(
            'https://mapy.com/fnc/v1/showmap?center=%F,%F&zoom=17&marker=true',
            $lon, $lat // POZOR: mapy.com v URL používají lon,lat
        );
        return [
            'mapy' => $mapy_url,                   // otevře web nebo app Mapy.com
            'geo'  => sprintf('geo:%F,%F', $lat, $lon) // univerzální odkaz pro mobily
        ];
    }

    /**
     * Vyhledat obecné POI (ne jen nabíjecí stanice)
     */
    public function findPOINear(string $query, float $lat, float $lon, int $radius_m = 3000, int $limit = 20): array {
        $cache_key = sprintf('db_mapy_poi_%s_%s_%s_%d_%d', $query, round($lat,5), round($lon,5), $radius_m, $limit);
        $cached = get_transient($cache_key);
        if ($cached !== false) return $cached;

        $endpoint = 'https://api.mapy.com/v1/geocode';
        $params = [
            'query' => $query,
            'type'  => 'poi',
            'lang'  => 'cs',
            'limit' => $limit,
            'preferNear' => $lon . ',' . $lat,
            'preferNearPrecision' => $radius_m,
            'apikey' => $this->apiKey
        ];
        $url = $endpoint . '?' . http_build_query($params);

        $res = wp_remote_get($url, ['timeout' => 12]);
        if (is_wp_error($res)) {
            error_log('[Mapy Service] Request error: ' . $res->get_error_message());
            return [];
        }
        
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $items = $data['items'] ?? [];
        
        set_transient($cache_key, $items, DAY_IN_SECONDS); // 24 h cache
        return $items;
    }
}
