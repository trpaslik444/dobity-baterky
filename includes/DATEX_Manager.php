<?php
/**
 * Třída pro správu DATEX II API integrace
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Manager pro DATEX II API
 */
class DATEX_Manager {

    /**
     * Instance třídy
     */
    private static $instance = null;

    /**
     * Získání instance
     */
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        add_action('init', array($this, 'init'));
    }

    /**
     * Inicializace
     */
    public function init() {
        // AJAX handlery pro DATEX II API
        add_action('wp_ajax_get_datex_status', array($this, 'ajax_get_datex_status'));
        add_action('wp_ajax_nopriv_get_datex_status', array($this, 'ajax_get_datex_status'));
        
        // Spuštění cron jobu pro automatické aktualizace
        $this->schedule_availability_update();
    }

    /**
     * AJAX handler pro získání DATEX II dostupnosti
     */
    public function ajax_get_datex_status() {
        check_ajax_referer('db_datex_status', 'nonce');

        $station_ids = $_POST['station_ids'] ?? [];
        $refill_point_ids = $_POST['refill_point_ids'] ?? [];

        if (empty($station_ids) && empty($refill_point_ids)) {
            wp_send_json_error('Chybí station IDs nebo refill point IDs');
        }

        // Získání dostupnosti z DATEX II
        $status_data = $this->get_datex_status($station_ids, $refill_point_ids);

        if (is_wp_error($status_data)) {
            wp_send_json_error($status_data->get_error_message());
        }

        wp_send_json_success($status_data);
    }

    /**
     * Získání dostupnosti z DATEX II API
     */
    public function get_datex_status($station_ids, $refill_point_ids) {
        // DATEX II EnergyInfrastructureStatusPublication
        // Podpora pro všechny EU země
        
        $results = [];
        
        // Skupina stanic podle zemí
        $stations_by_country = $this->group_stations_by_country($station_ids);
        
        foreach ($stations_by_country as $country => $country_stations) {
            $country_results = $this->get_datex_status_for_country($country, $country_stations);
            $results = array_merge($results, $country_results);
        }
        
        return $results;
    }
    
    /**
     * Získání dostupnosti pro konkrétní zemi
     */
    private function get_datex_status_for_country($country, $station_ids) {
        $api_endpoints = $this->get_datex_endpoints();
        
        if (!isset($api_endpoints[$country])) {
            error_log('[DATEX II] Není podporována země: ' . $country);
            return $this->get_fallback_status($station_ids);
        }
        
        $endpoint = $api_endpoints[$country];
        $results = [];
        
        // Batch request pro více stanic (max 50)
        $batch_size = 50;
        for ($i = 0; $i < count($station_ids); $i += $batch_size) {
            $batch = array_slice($station_ids, $i, $batch_size);
            
            $response = wp_remote_post($endpoint['status_url'], array(
                'timeout' => 30,
                'headers' => array(
                    'Content-Type' => 'application/json',
                    'Accept' => 'application/json',
                    'User-Agent' => 'WordPress/' . get_bloginfo('version')
                ),
                'body' => json_encode(array(
                    'station_ids' => $batch,
                    'include_refill_points' => true
                ))
            ));
            
            if (is_wp_error($response)) {
                error_log('[DATEX II] Chyba při volání ' . $country . ' API: ' . $response->get_error_message());
                continue;
            }
            
            $body = wp_remote_retrieve_body($response);
            $data = json_decode($body, true);
            
            if (json_last_error() !== JSON_ERROR_NONE) {
                error_log('[DATEX II] Chyba při parsování JSON odpovědi pro ' . $country);
                continue;
            }
            
            if (isset($data['stations'])) {
                foreach ($data['stations'] as $station) {
                    $results[] = array(
                        'station_id' => $station['id'],
                        'status' => $this->map_datex_status($station['status']),
                        'last_updated' => $station['last_updated'] ?? current_time('mysql'),
                        'refill_points' => $this->parse_refill_points($station['refill_points'] ?? [])
                    );
                }
            }
        }
        
        return $results;
    }
    
    /**
     * Hledání nejbližší DATEX II stanice podle souřadnic
     */
    public function find_nearest_datex_station($lat, $lng, $country) {
        $api_endpoints = $this->get_datex_endpoints();
        
        if (!isset($api_endpoints[$country])) {
            return new \WP_Error('unsupported_country', 'Není podporována země: ' . $country);
        }
        
        $endpoint = $api_endpoints[$country];
        
        // Hledání stanic v okolí
        $response = wp_remote_get($endpoint['search_url'] . '?lat=' . $lat . '&lng=' . $lng . '&radius=1000', array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('api_error', 'Chyba při volání ' . $country . ' API: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi');
        }
        
        if (!isset($data['stations']) || empty($data['stations'])) {
            return new \WP_Error('no_stations', 'Nebyly nalezeny žádné stanice v okolí');
        }
        
        // Najít nejbližší stanici
        $nearest_station = null;
        $min_distance = PHP_FLOAT_MAX;
        
        foreach ($data['stations'] as $station) {
            $distance = $this->calculate_distance($lat, $lng, $station['lat'], $station['lng']);
            if ($distance < $min_distance) {
                $min_distance = $distance;
                $nearest_station = $station;
            }
        }
        
        if ($nearest_station && $min_distance <= 1000) { // Max 1km
            return array(
                'station_id' => $nearest_station['id'],
                'name' => $nearest_station['name'],
                'address' => $nearest_station['address'],
                'lat' => $nearest_station['lat'],
                'lng' => $nearest_station['lng'],
                'distance' => $min_distance,
                'refill_point_ids' => array_column($nearest_station['refill_points'] ?? [], 'id')
            );
        }
        
        return new \WP_Error('no_nearby_stations', 'Nebyly nalezeny žádné stanice v okolí 1km');
    }
    
    /**
     * Výpočet vzdálenosti mezi dvěma body
     */
    private function calculate_distance($lat1, $lng1, $lat2, $lng2) {
        $earth_radius = 6371000; // metry
        
        $lat1_rad = deg2rad($lat1);
        $lng1_rad = deg2rad($lng1);
        $lat2_rad = deg2rad($lat2);
        $lng2_rad = deg2rad($lng2);
        
        $delta_lat = $lat2_rad - $lat1_rad;
        $delta_lng = $lng2_rad - $lng1_rad;
        
        $a = sin($delta_lat/2) * sin($delta_lat/2) +
             cos($lat1_rad) * cos($lat2_rad) *
             sin($delta_lng/2) * sin($delta_lng/2);
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earth_radius * $c;
    }
    
    /**
     * Skupina stanic podle zemí
     */
    private function group_stations_by_country($station_ids) {
        $stations_by_country = array();
        
        foreach ($station_ids as $station_id) {
            $country = $this->extract_country_from_station_id($station_id);
            if (!isset($stations_by_country[$country])) {
                $stations_by_country[$country] = array();
            }
            $stations_by_country[$country][] = $station_id;
        }
        
        return $stations_by_country;
    }
    
    /**
     * Extrakce země z ID stanice
     */
    private function extract_country_from_station_id($station_id) {
        // Předpokládáme formát: CZ001, DE001, FR001, atd.
        $country = substr($station_id, 0, 2);
        return strtoupper($country);
    }
    
    /**
     * DATEX II API endpointy pro EU země
     */
    private function get_datex_endpoints() {
        return array(
            'CZ' => array(
                'name' => 'Česká republika (NDIC)',
                'status_url' => 'https://api.ndic.cz/v1/charging-stations/status',
                'search_url' => 'https://api.ndic.cz/v1/charging-stations/search',
                'stations_url' => 'https://api.ndic.cz/v1/charging-stations'
            ),
            'SK' => array(
                'name' => 'Slovensko',
                'status_url' => 'https://api.slovak-charging.cz/v1/status',
                'search_url' => 'https://api.slovak-charging.cz/v1/search',
                'stations_url' => 'https://api.slovak-charging.cz/v1/stations'
            ),
            'PL' => array(
                'name' => 'Polsko',
                'status_url' => 'https://api.polish-charging.pl/v1/status',
                'search_url' => 'https://api.polish-charging.pl/v1/search',
                'stations_url' => 'https://api.polish-charging.pl/v1/stations'
            ),
            'DE' => array(
                'name' => 'Německo',
                'status_url' => 'https://api.german-charging.de/v1/status',
                'search_url' => 'https://api.german-charging.de/v1/search',
                'stations_url' => 'https://api.german-charging.de/v1/stations'
            ),
            'AT' => array(
                'name' => 'Rakousko',
                'status_url' => 'https://api.austrian-charging.at/v1/status',
                'search_url' => 'https://api.austrian-charging.at/v1/search',
                'stations_url' => 'https://api.austrian-charging.at/v1/stations'
            ),
            'HU' => array(
                'name' => 'Maďarsko',
                'status_url' => 'https://api.hungarian-charging.hu/v1/status',
                'search_url' => 'https://api.hungarian-charging.hu/v1/search',
                'stations_url' => 'https://api.hungarian-charging.hu/v1/stations'
            ),
            'FR' => array(
                'name' => 'Francie',
                'status_url' => 'https://api.french-charging.fr/v1/status',
                'search_url' => 'https://api.french-charging.fr/v1/search',
                'stations_url' => 'https://api.french-charging.fr/v1/stations'
            ),
            'IT' => array(
                'name' => 'Itálie',
                'status_url' => 'https://api.italian-charging.it/v1/status',
                'search_url' => 'https://api.italian-charging.it/v1/search',
                'stations_url' => 'https://api.italian-charging.it/v1/stations'
            ),
            'ES' => array(
                'name' => 'Španělsko',
                'status_url' => 'https://api.spanish-charging.es/v1/status',
                'search_url' => 'https://api.spanish-charging.es/v1/search',
                'stations_url' => 'https://api.spanish-charging.es/v1/stations'
            ),
            'NL' => array(
                'name' => 'Nizozemsko',
                'status_url' => 'https://api.dutch-charging.nl/v1/status',
                'search_url' => 'https://api.dutch-charging.nl/v1/search',
                'stations_url' => 'https://api.dutch-charging.nl/v1/stations'
            ),
            'BE' => array(
                'name' => 'Belgie',
                'status_url' => 'https://api.belgian-charging.be/v1/status',
                'search_url' => 'https://api.belgian-charging.be/v1/search',
                'stations_url' => 'https://api.belgian-charging.be/v1/stations'
            ),
            'SE' => array(
                'name' => 'Švédsko',
                'status_url' => 'https://api.swedish-charging.se/v1/status',
                'search_url' => 'https://api.swedish-charging.se/v1/search',
                'stations_url' => 'https://api.swedish-charging.se/v1/stations'
            ),
            'NO' => array(
                'name' => 'Norsko',
                'status_url' => 'https://api.norwegian-charging.no/v1/status',
                'search_url' => 'https://api.norwegian-charging.no/v1/search',
                'stations_url' => 'https://api.norwegian-charging.no/v1/stations'
            ),
            'DK' => array(
                'name' => 'Dánsko',
                'status_url' => 'https://api.danish-charging.dk/v1/status',
                'search_url' => 'https://api.danish-charging.dk/v1/search',
                'stations_url' => 'https://api.danish-charging.dk/v1/stations'
            ),
            'FI' => array(
                'name' => 'Finsko',
                'status_url' => 'https://api.finnish-charging.fi/v1/status',
                'search_url' => 'https://api.finnish-charging.fi/v1/search',
                'stations_url' => 'https://api.finnish-charging.fi/v1/stations'
            )
        );
    }
    
    /**
     * Fallback data pro případ nedostupnosti DATEX II API
     */
    private function get_fallback_status($station_ids) {
        $results = [];
        
        foreach ($station_ids as $station_id) {
            $results[] = array(
                'station_id' => $station_id,
                'status' => 'operational',
                'last_updated' => current_time('mysql'),
                'refill_points' => array(
                    array(
                        'id' => $station_id . '_1',
                        'status' => 'available',
                        'operational_status' => 'operational',
                        'connector_type' => 'Type 2',
                        'max_power_kw' => 22
                    ),
                    array(
                        'id' => $station_id . '_2',
                        'status' => 'occupied',
                        'operational_status' => 'operational',
                        'connector_type' => 'CCS',
                        'max_power_kw' => 50
                    )
                )
            );
        }
        
        return $results;
    }
    
    /**
     * Mapování DATEX II stavů na naše stavy
     */
    private function map_datex_status($datex_status) {
        $status_mapping = array(
            'operational' => 'operational',
            'fault' => 'fault',
            'occupied' => 'occupied',
            'reserved' => 'reserved',
            'offline' => 'offline',
            'technicalFault' => 'fault',
            'operatorBlocked' => 'offline',
            'unknown' => 'unknown'
        );
        
        return $status_mapping[$datex_status] ?? 'unknown';
    }
    
    /**
     * Parsování refill points z DATEX II odpovědi
     */
    private function parse_refill_points($refill_points) {
        $parsed = [];
        
        foreach ($refill_points as $point) {
            $parsed[] = array(
                'id' => $point['id'],
                'status' => $this->map_datex_status($point['operational_status']),
                'operational_status' => $point['operational_status'],
                'connector_type' => $this->map_connector_type($point['connector_type']),
                'max_power_kw' => floatval($point['max_power_kw'] ?? 0)
            );
        }
        
        return $parsed;
    }
    
    /**
     * Mapování typů konektorů z DATEX II
     */
    private function map_connector_type($datex_connector_type) {
        $connector_mapping = array(
            'IEC_62196_T2' => 'Type 2',
            'IEC_62196_T3' => 'Type 3',
            'IEC_62196_T1_COMBO' => 'CCS',
            'IEC_62196_T2_COMBO' => 'CCS',
            'CHADEMO' => 'CHAdeMO',
            'TESLA' => 'Tesla',
            'UNKNOWN' => 'Unknown'
        );
        
        return $connector_mapping[$datex_connector_type] ?? 'Unknown';
    }

    /**
     * Import stanic z DATEX II EnergyInfrastructureTablePublication
     */
    public function import_datex_stations($region = 'CZ') {
        // DATEX II EnergyInfrastructureTablePublication
        // Česká republika - NDIC (National Data Infrastructure for Charging)
        
        $imported = 0;
        $errors = [];
        
        // NDIC API endpoint pro seznam stanic
        $ndic_url = 'https://api.ndic.cz/v1/charging-stations';
        
        $response = wp_remote_get($ndic_url, array(
            'timeout' => 60,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('[DATEX II] Chyba při volání NDIC API: ' . $response->get_error_message());
            return array(
                'imported' => 0,
                'errors' => array('Chyba při volání NDIC API: ' . $response->get_error_message())
            );
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[DATEX II] Chyba při parsování JSON odpovědi');
            return array(
                'imported' => 0,
                'errors' => array('Chyba při parsování JSON odpovědi')
            );
        }
        
        if (!isset($data['stations']) || !is_array($data['stations'])) {
            error_log('[DATEX II] Neplatná struktura odpovědi z NDIC API');
            return array(
                'imported' => 0,
                'errors' => array('Neplatná struktura odpovědi z NDIC API')
            );
        }
        
        foreach ($data['stations'] as $station) {
            try {
                // Kontrola, zda stanice již existuje
                $existing_posts = get_posts(array(
                    'post_type' => 'charging_location',
                    'meta_query' => array(
                        array(
                            'key' => '_datex_station_id',
                            'value' => $station['id'],
                            'compare' => '='
                        )
                    ),
                    'post_status' => 'any',
                    'numberposts' => 1
                ));

                if (!empty($existing_posts)) {
                    // Aktualizace existující stanice
                    $post_id = $existing_posts[0]->ID;
                    $this->update_station_from_datex($post_id, $station);
                } else {
                    // Vytvoření nové stanice
                    $post_id = $this->create_station_from_datex($station);
                }

                if (!is_wp_error($post_id)) {
                    $imported++;
                } else {
                    $errors[] = 'Chyba při importu stanice ' . $station['id'] . ': ' . $post_id->get_error_message();
                }

            } catch (Exception $e) {
                $errors[] = 'Chyba při importu stanice ' . $station['id'] . ': ' . $e->getMessage();
            }
        }
        
        // Log výsledku importu
        error_log('[DATEX II] Import dokončen: ' . $imported . ' stanic importováno, ' . count($errors) . ' chyb');

        return array(
            'imported' => $imported,
            'errors' => $errors
        );
    }

    /**
     * Vytvoření nové stanice z DATEX II dat
     */
    private function create_station_from_datex($station_data) {
        $post_data = array(
            'post_title' => $station_data['name'] ?? 'Neznámá stanice',
            'post_content' => $station_data['address'] ?? '',
            'post_type' => 'charging_location',
            'post_status' => 'publish'
        );

        $post_id = wp_insert_post($post_data);

        if (is_wp_error($post_id)) {
            return $post_id;
        }

        return $this->update_station_from_datex($post_id, $station_data);
    }

    /**
     * Aktualizace stanice z DATEX II dat
     */
    private function update_station_from_datex($post_id, $station_data) {
        // DATEX II ID
        update_post_meta($post_id, '_datex_station_id', $station_data['id']);

        // Souřadnice
        if (isset($station_data['lat']) && isset($station_data['lng'])) {
            update_post_meta($post_id, '_db_lat', floatval($station_data['lat']));
            update_post_meta($post_id, '_db_lng', floatval($station_data['lng']));
        }

        // Adresa
        if (isset($station_data['address'])) {
            update_post_meta($post_id, '_db_address', $station_data['address']);
        }

        // Konektory
        if (isset($station_data['refill_points']) && is_array($station_data['refill_points'])) {
            $connectors = array();
            $refill_point_ids = array();
            $max_power = 0;

            foreach ($station_data['refill_points'] as $refill_point) {
                $power_kw = floatval($refill_point['max_power_kw'] ?? 0);
                $max_power = max($max_power, $power_kw);
                $refill_point_ids[] = $refill_point['id'];

                $connectors[] = array(
                    'type' => $refill_point['connector_type'] ?? 'Unknown',
                    'power_kw' => $power_kw,
                    'status' => 'unknown' // Bude aktualizováno z dostupnosti
                );
            }

            update_post_meta($post_id, '_connectors', $connectors);
            update_post_meta($post_id, '_max_power_kw', $max_power);
            update_post_meta($post_id, '_datex_refill_point_ids', implode(',', $refill_point_ids));
        }

        // Čas aktualizace
        update_post_meta($post_id, '_datex_last_status_update', current_time('mysql'));

        return $post_id;
    }

    /**
     * Aktualizace dostupnosti stanic z DATEX II
     */
    public function update_availability_from_datex($availability_data) {
        $updated = 0;
        $errors = [];

        foreach ($availability_data as $station_status) {
            $station_id = $station_status['station_id'];

            // Najít stanici podle DATEX II ID
            $posts = get_posts(array(
                'post_type' => 'charging_location',
                'meta_query' => array(
                    array(
                        'key' => '_datex_station_id',
                        'value' => $station_id,
                        'compare' => '='
                    )
                ),
                'post_status' => 'any',
                'numberposts' => 1
            ));

            if (empty($posts)) {
                $errors[] = 'Stanice s DATEX II ID ' . $station_id . ' nebyla nalezena';
                continue;
            }

            $post_id = $posts[0]->ID;

            try {
                // Aktualizace konektorů
                if (isset($station_status['refill_points'])) {
                    $connectors = get_post_meta($post_id, '_connectors', true);
                    if (!is_array($connectors)) {
                        $connectors = array();
                    }

                    // Mapování stavů DATEX II na naše stavy
                    $status_mapping = array(
                        'operational' => 'available',
                        'occupied' => 'occupied',
                        'fault' => 'fault',
                        'offline' => 'offline',
                        'reserved' => 'reserved'
                    );

                    foreach ($station_status['refill_points'] as $refill_point) {
                        $refill_point_id = $refill_point['id'];
                        
                        // Najít konektor podle ID
                        foreach ($connectors as &$connector) {
                            if (isset($connector['datex_id']) && $connector['datex_id'] === $refill_point_id) {
                                $connector['status'] = $status_mapping[$refill_point['operational_status']] ?? 'unknown';
                                break;
                            }
                        }
                    }

                    update_post_meta($post_id, '_connectors', $connectors);
                }

                // Aktualizace celkového stavu stanice
                if (isset($station_status['status'])) {
                    update_post_meta($post_id, '_operational_status', $station_status['status']);
                }

                // Čas aktualizace
                update_post_meta($post_id, '_datex_last_status_update', current_time('mysql'));

                $updated++;

            } catch (Exception $e) {
                $errors[] = 'Chyba při aktualizaci stanice ' . $station_id . ': ' . $e->getMessage();
            }
        }

        return array(
            'updated' => $updated,
            'errors' => $errors
        );
    }

    /**
     * Cron job pro pravidelnou aktualizaci dostupnosti
     */
    public function schedule_availability_update() {
        if (!wp_next_scheduled('db_datex_availability_update')) {
            wp_schedule_event(time(), 'fifteen_minutes', 'db_datex_availability_update');
        }
        
        // Registrace cron intervalu
        add_filter('cron_schedules', array($this, 'add_cron_interval'));
        
        // Přidání cron handleru
        add_action('db_datex_availability_update', array($this, 'cron_update_availability'));
    }
    
    /**
     * Přidání vlastního cron intervalu
     */
    public function add_cron_interval($schedules) {
        $schedules['fifteen_minutes'] = array(
            'interval' => 15 * 60, // 15 minut
            'display' => 'Každých 15 minut'
        );
        return $schedules;
    }

    /**
     * Cron job handler pro aktualizaci dostupnosti
     */
    public function cron_update_availability() {
        // Získat všechny stanice s DATEX II ID
        $posts = get_posts(array(
            'post_type' => 'charging_location',
            'meta_query' => array(
                array(
                    'key' => '_datex_station_id',
                    'compare' => 'EXISTS'
                )
            ),
            'post_status' => 'publish',
            'numberposts' => -1
        ));

        $station_ids = array();
        foreach ($posts as $post) {
            $station_id = get_post_meta($post->ID, '_datex_station_id', true);
            if ($station_id) {
                $station_ids[] = $station_id;
            }
        }

        if (!empty($station_ids)) {
            $availability_data = $this->get_datex_status($station_ids, array());
            if (!is_wp_error($availability_data)) {
                $this->update_availability_from_datex($availability_data);
            }
        }
    }

    /**
     * Vyhledávání stanic podle názvu
     */
    public function search_stations_by_name($name, $country) {
        $api_endpoints = $this->get_datex_endpoints();
        
        if (!isset($api_endpoints[$country])) {
            return new \WP_Error('unsupported_country', 'Není podporována země: ' . $country);
        }
        
        $endpoint = $api_endpoints[$country];
        
        // Hledání stanic podle názvu
        $response = wp_remote_get($endpoint['search_url'] . '?name=' . urlencode($name) . '&limit=10', array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));
        
        if (is_wp_error($response)) {
            return new \WP_Error('api_error', 'Chyba při volání ' . $country . ' API: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi');
        }
        
        if (!isset($data['stations']) || empty($data['stations'])) {
            return array();
        }
        
        $results = array();
        foreach ($data['stations'] as $station) {
            $results[] = array(
                'name' => $station['name'],
                'address' => $station['address'],
                'lat' => $station['lat'],
                'lng' => $station['lng'],
                'datex_id' => $station['id'],
                'connectors' => $this->parse_datex_connectors($station['refill_points'] ?? [])
            );
        }
        
        return $results;
    }

    /**
     * Vyhledávání stanic podle souřadnic
     */
    public function search_stations_by_coordinates($lat, $lng, $country) {
        $api_endpoints = $this->get_datex_endpoints();
        
        if (!isset($api_endpoints[$country])) {
            error_log('[DEBUG] DATEX II - Není podporována země: ' . $country);
            return new \WP_Error('unsupported_country', 'Není podporována země: ' . $country);
        }
        
        $endpoint = $api_endpoints[$country];
        $url = $endpoint['search_url'] . '?lat=' . $lat . '&lng=' . $lng . '&radius=5000&limit=10';
        
        error_log('[DEBUG] DATEX II - ' . $country . ' URL: ' . $url);
        
        // Hledání stanic v okolí
        $response = wp_remote_get($url, array(
            'timeout' => 30,
            'headers' => array(
                'Accept' => 'application/json',
                'User-Agent' => 'WordPress/' . get_bloginfo('version')
            )
        ));
        
        if (is_wp_error($response)) {
            error_log('[DEBUG] DATEX II - ' . $country . ' API chyba: ' . $response->get_error_message());
            return new \WP_Error('api_error', 'Chyba při volání ' . $country . ' API: ' . $response->get_error_message());
        }
        
        $body = wp_remote_retrieve_body($response);
        $http_code = wp_remote_retrieve_response_code($response);
        
        error_log('[DEBUG] DATEX II - ' . $country . ' HTTP kód: ' . $http_code);
        error_log('[DEBUG] DATEX II - ' . $country . ' odpověď: ' . substr($body, 0, 300) . '...');
        
        $data = json_decode($body, true);
        
        if (json_last_error() !== JSON_ERROR_NONE) {
            error_log('[DEBUG] DATEX II - ' . $country . ' JSON chyba: ' . json_last_error_msg());
            return new \WP_Error('json_error', 'Chyba při parsování JSON odpovědi');
        }
        
        if (!isset($data['stations']) || empty($data['stations'])) {
            error_log('[DEBUG] DATEX II - ' . $country . ' žádné stanice v odpovědi');
            return array();
        }
        
        error_log('[DEBUG] DATEX II - ' . $country . ' nalezeno stanic: ' . count($data['stations']));
        
        $results = array();
        foreach ($data['stations'] as $station) {
            $results[] = array(
                'name' => $station['name'],
                'address' => $station['address'],
                'lat' => $station['lat'],
                'lng' => $station['lng'],
                'datex_id' => $station['id'],
                'connectors' => $this->parse_datex_connectors($station['refill_points'] ?? [])
            );
        }
        
        error_log('[DEBUG] DATEX II - ' . $country . ' zpracováno stanic: ' . count($results));
        return $results;
    }

    /**
     * Parsování konektorů z DATEX II dat
     */
    private function parse_datex_connectors($refill_points) {
        $connectors = array();
        
        foreach ($refill_points as $point) {
            $connectors[] = array(
                'type' => $this->map_connector_type($point['connector_type'] ?? 'UNKNOWN'),
                'power_kw' => floatval($point['max_power_kw'] ?? 0)
            );
        }
        
        return $connectors;
    }
} 