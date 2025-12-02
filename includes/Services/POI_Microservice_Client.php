<?php
/**
 * POI Microservice Client
 * 
 * Klient pro komunikaci s POI microservice API
 * WordPress volá POI microservice a pak sám vytváří posty
 */

namespace DB\Services;

if (!defined('ABSPATH')) {
    exit;
}

class POI_Microservice_Client {
    private static $instance = null;
    private $api_url;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        // URL POI microservice z konstanty (wp-config.php) nebo options
        if (defined('DB_POI_SERVICE_URL')) {
            $this->api_url = DB_POI_SERVICE_URL;
        } else {
            $this->api_url = get_option('db_poi_service_url', 'http://localhost:3333');
        }
        
        // Validace URL
        if (!filter_var($this->api_url, FILTER_VALIDATE_URL)) {
            error_log('[POI Microservice Client] Invalid API URL: ' . $this->api_url);
            $this->api_url = 'http://localhost:3333'; // Fallback
        }
        
        // Odstranit trailing slash
        $this->api_url = rtrim($this->api_url, '/');
    }

    /**
     * Získat nearby POIs z POI microservice
     * 
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $radius Radius v metrech (default 2000)
     * @param int $minCount Minimální počet POIs (default 10)
     * @param bool $refresh Ignorovat cache (default false)
     * @return array|WP_Error Array s POIs nebo WP_Error při chybě
     */
    public function get_nearby_pois($lat, $lng, $radius = 2000, $minCount = 10, $refresh = false) {
        $url = $this->api_url . '/api/pois/nearby';
        $args = array(
            'lat' => $lat,
            'lon' => $lng,
            'radius' => $radius,
            'minCount' => $minCount,
            'refresh' => $refresh ? 'true' : 'false',
        );

        $url = add_query_arg($args, $url);

        // Získat timeout z options nebo použít default
        $timeout = (int) get_option('db_poi_service_timeout', 30);
        $max_retries = (int) get_option('db_poi_service_max_retries', 3);
        
        $last_error = null;
        
        // Retry logika s exponential backoff
        for ($attempt = 1; $attempt <= $max_retries; $attempt++) {
            $response = wp_remote_get($url, array(
                'timeout' => $timeout,
                'headers' => array(
                    'Accept' => 'application/json',
                ),
            ));

            if (is_wp_error($response)) {
                $last_error = $response;
                
                // Pokud není poslední pokus, počkat před dalším pokusem
                if ($attempt < $max_retries) {
                    $wait_time = pow(2, $attempt - 1); // Exponential backoff: 1s, 2s, 4s
                    sleep($wait_time);
                    continue;
                }
                
                return $response;
            }

            $status_code = wp_remote_retrieve_response_code($response);
            
            // Retry pouze pro 5xx chyby nebo timeout
            if ($status_code >= 500 && $status_code < 600 && $attempt < $max_retries) {
                $wait_time = pow(2, $attempt - 1);
                sleep($wait_time);
                continue;
            }
            
            if ($status_code !== 200) {
                $body = wp_remote_retrieve_body($response);
                return new \WP_Error(
                    'poi_service_error',
                    sprintf('POI microservice returned status %d: %s', $status_code, $body),
                    array('status' => $status_code)
                );
            }

            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                return new \WP_Error(
                    'poi_service_json_error',
                    'Failed to parse JSON response from POI microservice: ' . json_last_error_msg()
                );
            }

            return $data;
        }
        
        // Pokud všechny pokusy selhaly, vrátit poslední chybu
        return $last_error ?: new \WP_Error(
            'poi_service_max_retries',
            sprintf('POI microservice failed after %d attempts', $max_retries)
        );
    }

    /**
     * Synchronizovat POIs z POI microservice do WordPressu
     * 
     * @param float $lat Latitude
     * @param float $lng Longitude
     * @param int $radius Radius v metrech
     * @param bool $refresh Ignorovat cache
     * @return array Array s výsledky synchronizace
     */
    public function sync_nearby_pois_to_wordpress($lat, $lng, $radius = 2000, $refresh = false) {
        $result = $this->get_nearby_pois($lat, $lng, $radius, 10, $refresh);

        if (is_wp_error($result)) {
            return array(
                'success' => false,
                'error' => $result->get_error_message(),
                'synced' => 0,
                'failed' => 0,
            );
        }

        if (!isset($result['pois']) || !is_array($result['pois'])) {
            return array(
                'success' => false,
                'error' => 'Invalid response format from POI microservice',
                'synced' => 0,
                'failed' => 0,
            );
        }

        $synced = 0;
        $failed = 0;

        foreach ($result['pois'] as $poi) {
            $post_id = $this->create_or_update_poi($poi);
            if ($post_id) {
                $synced++;
            } else {
                $failed++;
            }
        }

        return array(
            'success' => true,
            'synced' => $synced,
            'failed' => $failed,
            'total' => count($result['pois']),
            'providers_used' => $result['providers_used'] ?? array(),
        );
    }

    /**
     * Vytvořit nebo aktualizovat WordPress POI post
     * 
     * @param array $poi POI data z microservice
     * @return int|false Post ID nebo false při chybě
     */
    private function create_or_update_poi($poi) {
        // Validace povinných polí
        if (!isset($poi['lat']) || !isset($poi['lon']) || !isset($poi['name'])) {
            return false;
        }

        $lat = (float) $poi['lat'];
        $lng = (float) $poi['lon'];
        $name = sanitize_text_field($poi['name']);
        
        // Validace GPS souřadnic
        if ($lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            error_log('[POI Microservice Client] Invalid GPS coordinates: ' . $lat . ', ' . $lng);
            return false;
        }
        
        // Validace názvu
        if (empty($name) || strlen($name) > 255) {
            error_log('[POI Microservice Client] Invalid name: ' . $name);
            return false;
        }
        
        // Validace rating (pokud existuje)
        if (isset($poi['rating']) && ($poi['rating'] < 0 || $poi['rating'] > 5)) {
            error_log('[POI Microservice Client] Invalid rating: ' . $poi['rating']);
            unset($poi['rating']); // Odstranit nevalidní rating
        }
        
        // Validace category (pokud existuje)
        if (isset($poi['category']) && !is_string($poi['category'])) {
            error_log('[POI Microservice Client] Invalid category: ' . print_r($poi['category'], true));
            unset($poi['category']);
        }

        // Najít existující POI podle external_id nebo GPS + jméno
        $existing_id = $this->find_existing_poi($poi, $lat, $lng, $name);

        if ($existing_id) {
            // Aktualizovat existující
            return $this->update_poi($existing_id, $poi);
        } else {
            // Vytvořit nový
            return $this->create_poi($poi);
        }
    }

    /**
     * Najít existující POI
     */
    private function find_existing_poi($poi, $lat, $lng, $name) {
        global $wpdb;

        // Nejdříve zkusit podle external_id (source_ids)
        if (isset($poi['source_ids']) && is_array($poi['source_ids'])) {
            foreach ($poi['source_ids'] as $source => $source_id) {
                $post_id = $wpdb->get_var($wpdb->prepare(
                    "SELECT post_id FROM {$wpdb->postmeta} 
                     WHERE meta_key = '_poi_external_id' AND meta_value = %s 
                     LIMIT 1",
                    $source . ':' . $source_id
                ));
                if ($post_id) {
                    return (int) $post_id;
                }
            }
        }

        // Pokud ne, zkusit podle GPS + jméno (deduplikace)
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
            $lat, $lng, $lat
        ));

        // Zkontrolovat podobnost jména
        foreach ($candidates as $candidate) {
            $distance = $this->haversine_km($lat, $lng, (float) $candidate->lat, (float) $candidate->lon);
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
     * Vytvořit nový POI post
     */
    private function create_poi($poi) {
        $post_id = wp_insert_post(array(
            'post_type' => 'poi',
            'post_title' => sanitize_text_field($poi['name']),
            'post_status' => 'publish',
        ));

        if (is_wp_error($post_id)) {
            return false;
        }

        $this->update_poi_meta($post_id, $poi);
        return $post_id;
    }

    /**
     * Aktualizovat existující POI post
     */
    private function update_poi($post_id, $poi) {
        wp_update_post(array(
            'ID' => $post_id,
            'post_title' => sanitize_text_field($poi['name']),
        ));

        $this->update_poi_meta($post_id, $poi);
        return $post_id;
    }

    /**
     * Aktualizovat POI meta data
     */
    private function update_poi_meta($post_id, $poi) {
        // Základní metadata
        if (isset($poi['lat'])) {
            update_post_meta($post_id, '_poi_lat', (float) $poi['lat']);
        }
        if (isset($poi['lon'])) {
            update_post_meta($post_id, '_poi_lng', (float) $poi['lon']);
        }
        if (isset($poi['address'])) {
            update_post_meta($post_id, '_poi_address', sanitize_text_field($poi['address']));
        }
        if (isset($poi['city'])) {
            update_post_meta($post_id, '_poi_city', sanitize_text_field($poi['city']));
        }
        if (isset($poi['country'])) {
            update_post_meta($post_id, '_poi_country', sanitize_text_field($poi['country']));
        }

        // Kategorie
        if (isset($poi['category'])) {
            $category = sanitize_text_field($poi['category']);
            wp_set_object_terms($post_id, array($category), 'poi_type', false);
        }

        // Rating
        if (isset($poi['rating'])) {
            update_post_meta($post_id, '_poi_rating', (float) $poi['rating']);
        }
        if (isset($poi['rating_source'])) {
            update_post_meta($post_id, '_poi_rating_source', sanitize_text_field($poi['rating_source']));
        }

        // Další metadata
        if (isset($poi['price_level'])) {
            update_post_meta($post_id, '_poi_price_level', (int) $poi['price_level']);
        }
        if (isset($poi['website'])) {
            update_post_meta($post_id, '_poi_website', esc_url_raw($poi['website']));
        }
        if (isset($poi['phone'])) {
            update_post_meta($post_id, '_poi_phone', sanitize_text_field($poi['phone']));
        }
        if (isset($poi['opening_hours'])) {
            // opening_hours může být array (JSON) nebo string
            if (is_array($poi['opening_hours'])) {
                update_post_meta($post_id, '_poi_opening_hours', $poi['opening_hours']);
            } else {
                update_post_meta($post_id, '_poi_opening_hours', sanitize_textarea_field($poi['opening_hours']));
            }
        }
        if (isset($poi['photo_url'])) {
            update_post_meta($post_id, '_poi_photo_url', esc_url_raw($poi['photo_url']));
        }
        if (isset($poi['photo_license'])) {
            update_post_meta($post_id, '_poi_photo_license', sanitize_text_field($poi['photo_license']));
        }

        // External IDs pro deduplikaci
        if (isset($poi['source_ids']) && is_array($poi['source_ids'])) {
            // Uložit první external_id jako primární
            $first_source = key($poi['source_ids']);
            $first_id = $poi['source_ids'][$first_source];
            if ($first_source && $first_id) {
                update_post_meta($post_id, '_poi_external_id', $first_source . ':' . $first_id);
            }
        }

        // Source IDs (celý objekt)
        if (isset($poi['source_ids']) && is_array($poi['source_ids'])) {
            update_post_meta($post_id, '_poi_source_ids', $poi['source_ids']);
        }
    }

    /**
     * Haversine vzdálenost v km
     */
    private function haversine_km($lat1, $lon1, $lat2, $lon2) {
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
     * Podobnost jmen
     */
    private function name_similarity($name1, $name2) {
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

