<?php
/**
 * REST API endpoint pro synchronizaci POIs z POI microservice do WordPressu
 * 
 * Tento endpoint přijímá POIs z POI microservice a vytváří WordPress post type 'poi'
 */

namespace DB;

if (!defined('ABSPATH')) {
    exit;
}

class REST_POI_Sync {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function register(): void {
        add_action('rest_api_init', function () {
            register_rest_route('db/v1', '/poi-sync', [
                'methods' => 'POST',
                'callback' => [$this, 'handle_sync'],
                'permission_callback' => [$this, 'check_permission'],
            ]);
        });
    }

    /**
     * Kontrola oprávnění - může být voláno z POI microservice
     */
    public function check_permission(): bool {
        // Zkontrolovat nonce nebo API key
        $nonce = $_SERVER['HTTP_X_WP_NONCE'] ?? '';
        if ($nonce && wp_verify_nonce($nonce, 'wp_rest')) {
            return true;
        }

        // Fallback: zkontrolovat API key v options
        $api_key = get_option('db_poi_sync_api_key', '');
        // Podpora různých header názvů
        $provided_key = $_SERVER['HTTP_X_API_KEY'] ?? 
                       $_SERVER['HTTP_X_WP_API_KEY'] ?? 
                       '';
        if ($api_key && $provided_key === $api_key) {
            return true;
        }

        // Pro vývoj: povolit pokud je uživatel přihlášený jako admin
        if (current_user_can('manage_options')) {
            return true;
        }

        return false;
    }

    /**
     * Zpracovat synchronizaci POI
     */
    public function handle_sync($request) {
        $params = $request->get_json_params();

        // Validace povinných polí
        $name = sanitize_text_field($params['name'] ?? '');
        $lat = isset($params['lat']) ? (float) $params['lat'] : null;
        $lon = isset($params['lon']) ? (float) $params['lon'] : null;
        $external_id = sanitize_text_field($params['external_id'] ?? ''); // ID z PostgreSQL

        if (!$name || $lat === null || $lon === null) {
            return new \WP_Error('missing_data', 'Chybí povinná data (name, lat, lon)', ['status' => 400]);
        }

        // Zkontrolovat, zda už POI existuje (podle external_id nebo GPS)
        $existing_id = $this->find_existing_poi($external_id, $lat, $lon, $name);

        if ($existing_id) {
            // Aktualizovat existující POI
            $post_id = $this->update_poi($existing_id, $params);
            return rest_ensure_response([
                'success' => true,
                'action' => 'updated',
                'post_id' => $post_id,
            ]);
        } else {
            // Vytvořit nový POI
            $post_id = $this->create_poi($params);
            if (is_wp_error($post_id)) {
                return $post_id;
            }
            return rest_ensure_response([
                'success' => true,
                'action' => 'created',
                'post_id' => $post_id,
            ]);
        }
    }

    /**
     * Najít existující POI podle external_id nebo GPS + jména
     */
    private function find_existing_poi(?string $external_id, float $lat, float $lon, string $name): ?int {
        global $wpdb;

        // Nejdříve zkusit podle external_id
        if ($external_id) {
            $post_id = $wpdb->get_var($wpdb->prepare(
                "SELECT post_id FROM {$wpdb->postmeta} 
                 WHERE meta_key = '_poi_external_id' AND meta_value = %s 
                 LIMIT 1",
                $external_id
            ));
            if ($post_id) {
                return (int) $post_id;
            }
        }

        // Pokud ne, zkusit podle GPS + jména (deduplikace)
        $candidates = $wpdb->get_results($wpdb->prepare(
            "SELECT p.ID, 
                    pm_lat.meta_value+0 AS lat,
                    pm_lng.meta_value+0 AS lon,
                    p.post_title
             FROM {$wpdb->posts} p
             INNER JOIN {$wpdb->postmeta} pm_lat ON pm_lat.post_id = p.ID AND pm_lat.meta_key = '_poi_lat'
             INNER JOIN {$wpdb->postmeta} pm_lng ON pm_lng.post_id = p.ID AND pm_lng.meta_key = '_poi_lng'
             WHERE p.post_type = 'poi' 
             AND p.post_status = 'publish'
             AND (
                 6371 * ACOS(
                     COS(RADIANS(%f)) * COS(RADIANS(pm_lat.meta_value+0)) *
                     COS(RADIANS(pm_lng.meta_value+0) - RADIANS(%f)) +
                     SIN(RADIANS(%f)) * SIN(RADIANS(pm_lat.meta_value+0))
                 )
             ) <= 0.05
             LIMIT 10",
            $lat, $lon, $lat
        ));

        // Zkontrolovat podobnost jména
        foreach ($candidates as $candidate) {
            $distance = $this->haversine_km($lat, $lon, (float) $candidate->lat, (float) $candidate->lon);
            if ($distance <= 0.05) { // 50 metrů
                $name_similarity = $this->name_similarity($name, $candidate->post_title);
                if ($name_similarity > 0.8) { // 80% podobnost
                    return (int) $candidate->ID;
                }
            }
        }

        return null;
    }

    /**
     * Vytvořit nový POI
     */
    private function create_poi(array $params): int {
        $post_id = wp_insert_post([
            'post_type' => 'poi',
            'post_title' => sanitize_text_field($params['name']),
            'post_status' => 'publish',
        ]);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        $this->update_poi_meta($post_id, $params);
        return $post_id;
    }

    /**
     * Aktualizovat existující POI
     */
    private function update_poi(int $post_id, array $params): int {
        wp_update_post([
            'ID' => $post_id,
            'post_title' => sanitize_text_field($params['name']),
        ]);

        $this->update_poi_meta($post_id, $params);
        return $post_id;
    }

    /**
     * Aktualizovat meta data POI
     */
    private function update_poi_meta(int $post_id, array $params): void {
        // Základní metadata
        if (isset($params['lat'])) {
            update_post_meta($post_id, '_poi_lat', (float) $params['lat']);
        }
        if (isset($params['lon'])) {
            update_post_meta($post_id, '_poi_lng', (float) $params['lon']);
        }
        if (isset($params['address'])) {
            update_post_meta($post_id, '_poi_address', sanitize_text_field($params['address']));
        }
        if (isset($params['city'])) {
            update_post_meta($post_id, '_poi_city', sanitize_text_field($params['city']));
        }
        if (isset($params['country'])) {
            update_post_meta($post_id, '_poi_country', sanitize_text_field($params['country']));
        }

        // Kategorie
        if (isset($params['category'])) {
            // Mapovat category na POI type taxonomy
            $category = sanitize_text_field($params['category']);
            wp_set_object_terms($post_id, [$category], 'poi_type', false);
        }

        // Rating
        if (isset($params['rating'])) {
            update_post_meta($post_id, '_poi_rating', (float) $params['rating']);
        }
        if (isset($params['rating_source'])) {
            update_post_meta($post_id, '_poi_rating_source', sanitize_text_field($params['rating_source']));
        }

        // Další metadata
        if (isset($params['price_level'])) {
            update_post_meta($post_id, '_poi_price_level', (int) $params['price_level']);
        }
        if (isset($params['website'])) {
            update_post_meta($post_id, '_poi_website', esc_url_raw($params['website']));
        }
        if (isset($params['phone'])) {
            update_post_meta($post_id, '_poi_phone', sanitize_text_field($params['phone']));
        }
        if (isset($params['opening_hours'])) {
            update_post_meta($post_id, '_poi_opening_hours', $params['opening_hours']);
        }
        if (isset($params['photo_url'])) {
            update_post_meta($post_id, '_poi_photo_url', esc_url_raw($params['photo_url']));
        }
        if (isset($params['photo_license'])) {
            update_post_meta($post_id, '_poi_photo_license', sanitize_text_field($params['photo_license']));
        }

        // External ID pro deduplikaci
        if (isset($params['external_id'])) {
            update_post_meta($post_id, '_poi_external_id', sanitize_text_field($params['external_id']));
        }

        // Source IDs
        if (isset($params['source_ids'])) {
            update_post_meta($post_id, '_poi_source_ids', $params['source_ids']);
        }
    }

    /**
     * Haversine vzdálenost v km
     */
    private function haversine_km(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earth_km = 6371.0;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) * sin($dLat / 2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon / 2) * sin($dLon / 2);
        $c = 2 * atan2(sqrt($a), sqrt(1 - $a));
        return $earth_km * $c;
    }

    /**
     * Podobnost jmen (jednoduchá Levenshtein podobnost)
     */
    private function name_similarity(string $name1, string $name2): float {
        $name1 = mb_strtolower($name1, 'UTF-8');
        $name2 = mb_strtolower($name2, 'UTF-8');
        
        // Odstranit diakritiku
        $name1 = iconv('UTF-8', 'ASCII//TRANSLIT', $name1);
        $name2 = iconv('UTF-8', 'ASCII//TRANSLIT', $name2);
        
        $len1 = mb_strlen($name1);
        $len2 = mb_strlen($name2);
        $max_len = max($len1, $len2);
        
        if ($max_len === 0) {
            return 1.0;
        }
        
        $distance = levenshtein($name1, $name2);
        return 1.0 - ($distance / $max_len);
    }
}

