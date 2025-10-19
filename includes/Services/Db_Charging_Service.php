<?php
/**
 * Charging Service - Vyhledávání nabíjecích stanic přes Mapy.com API
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB\Services;

if (!defined('ABSPATH')) {
    exit;
}

class Db_Charging_Service {

    private string $apiKey;

    public function __construct(?string $apiKey = null) {
        $this->apiKey = $apiKey ?? get_option('db_mapy_api_key', '');
    }

    /**
     * Vyhledá nabíjecí stanice kolem bodu s různými dotazy
     */
    public function findChargingStations(float $lat, float $lon, int $radius_m = 3000, int $limit = 20): array {
        $results = [];
        
        // Různé dotazy pro nabíjecí stanice
        $queries = [
            'nabíjecí stanice',
            'charging station',
            'EV charger',
            'elektrické nabíjení',
            'rychlodobíjecí stanice'
        ];
        
        foreach ($queries as $query) {
            $stations = $this->searchChargingStations($query, $lat, $lon, $radius_m, $limit);
            $results = array_merge($results, $stations);
        }
        
        // Odstraníme duplicity a seřadíme podle vzdálenosti
        $results = $this->removeDuplicates($results);
        $results = $this->sortByDistance($results, $lat, $lon);
        
        return array_slice($results, 0, $limit);
    }

    /**
     * Vyhledání nabíjecích stanic s konkrétním dotazem
     */
    private function searchChargingStations(string $query, float $lat, float $lon, int $radius_m, int $limit): array {
        $cache_key = sprintf('db_mapy_charging_%s_%s_%s_%d_%d', 
            md5($query), round($lat,5), round($lon,5), $radius_m, $limit);
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
            error_log('[Charging Service] Request error: ' . $res->get_error_message());
            return [];
        }
        
        $data = json_decode(wp_remote_retrieve_body($res), true);
        $items = $data['items'] ?? [];
        
        // Transformujeme data do jednotného formátu
        $transformed = array_map(function($item) use ($lat, $lon) {
            return $this->transformChargingStation($item, $lat, $lon);
        }, $items);
        
        set_transient($cache_key, $transformed, 30 * DAY_IN_SECONDS); // 30 dní cache
        return $transformed;
    }

    /**
     * Transformace dat nabíjecí stanice
     */
    private function transformChargingStation(array $item, float $originLat, float $originLon): array {
        $position = $item['position'] ?? [];
        $lat = (float) ($position['lat'] ?? 0);
        $lon = (float) ($position['lon'] ?? 0);
        
        return [
            // Základní informace
            'id' => $item['id'] ?? '',
            'name' => $item['name'] ?? '',
            'label' => $item['label'] ?? '',
            'type' => $item['type'] ?? 'poi',
            
            // Lokace
            'coords' => [
                'lat' => $lat,
                'lon' => $lon,
                'lng' => $lon // Pro kompatibilitu
            ],
            'address' => $item['location'] ?? '',
            'zip' => $item['zip'] ?? '',
            
            // Regionální struktura
            'regional_structure' => $item['regionalStructure'] ?? [],
            'bbox' => $item['bbox'] ?? [],
            
            // Vzdálenost od původního bodu
            'distance_m' => $this->calculateDistance($originLat, $originLon, $lat, $lon),
            
            // Odkazy
            'deep_links' => [
                'mapy' => $this->buildMapyUrl($lat, $lon, $item['name'] ?? ''),
                'geo' => sprintf('geo:%F,%F', $lat, $lon)
            ],
            
            // Analýza typu nabíjecí stanice
            'charging_type' => $this->analyzeChargingType($item),
            
            // Raw data pro další zpracování
            'raw' => $item
        ];
    }

    /**
     * Analýza typu nabíjecí stanice na základě názvu a labelu
     */
    private function analyzeChargingType(array $item): array {
        $name = strtolower($item['name'] ?? '');
        $label = strtolower($item['label'] ?? '');
        $fullText = $name . ' ' . $label;
        
        $types = [
            'fast_charging' => ['rychlodobíjecí', 'fast', 'dc', 'supercharger', 'ionity'],
            'slow_charging' => ['pomalé', 'slow', 'ac', 'domácí', 'wallbox'],
            'public' => ['veřejné', 'public', 'parkoviště', 'nákupní'],
            'private' => ['soukromé', 'private', 'firemní', 'hotel'],
            'tesla' => ['tesla', 'supercharger'],
            'premium' => ['premium', 'luxus', 'pre']
        ];
        
        $detectedTypes = [];
        foreach ($types as $type => $keywords) {
            foreach ($keywords as $keyword) {
                if (strpos($fullText, $keyword) !== false) {
                    $detectedTypes[] = $type;
                    break;
                }
            }
        }
        
        return [
            'detected_types' => array_unique($detectedTypes),
            'is_fast_charging' => in_array('fast_charging', $detectedTypes),
            'is_public' => in_array('public', $detectedTypes),
            'is_tesla' => in_array('tesla', $detectedTypes)
        ];
    }

    /**
     * Odstranění duplicitních stanic
     */
    private function removeDuplicates(array $stations): array {
        $seen = [];
        $unique = [];
        
        foreach ($stations as $station) {
            $key = $station['coords']['lat'] . ',' . $station['coords']['lon'];
            if (!isset($seen[$key])) {
                $seen[$key] = true;
                $unique[] = $station;
            }
        }
        
        return $unique;
    }

    /**
     * Seřazení podle vzdálenosti
     */
    private function sortByDistance(array $stations, float $originLat, float $originLon): array {
        usort($stations, function($a, $b) {
            return $a['distance_m'] <=> $b['distance_m'];
        });
        
        return $stations;
    }

    /**
     * Vytvoření Mapy.cz URL
     */
    private function buildMapyUrl(float $lat, float $lon, string $name = ''): string {
        $params = [
            'source' => 'coor',
            'id' => $lat . ',' . $lon
        ];
        
        if (!empty($name)) {
            $params['query'] = urlencode($name);
        }
        
        return 'https://mapy.cz/zakladni?' . http_build_query($params);
    }

    /**
     * Výpočet vzdálenosti v metrech
     */
    private function calculateDistance(float $lat1, float $lon1, float $lat2, float $lon2): float {
        $earthRadius = 6371000; // metry
        
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        
        $a = sin($dLat/2) * sin($dLat/2) +
             cos(deg2rad($lat1)) * cos(deg2rad($lat2)) *
             sin($dLon/2) * sin($dLon/2);
        
        $c = 2 * atan2(sqrt($a), sqrt(1-$a));
        
        return $earthRadius * $c;
    }

    /**
     * Vyhledání nejbližších nabíjecích stanic
     */
    public function findNearestChargingStations(float $lat, float $lon, int $maxDistance = 5000, int $limit = 10): array {
        $allStations = $this->findChargingStations($lat, $lon, $maxDistance, $limit * 2);
        
        $filtered = array_filter($allStations, function($station) use ($maxDistance) {
            return $station['distance_m'] <= $maxDistance;
        });
        
        return array_slice($filtered, 0, $limit);
    }

    /**
     * Analýza dostupnosti nabíjecích stanic v oblasti
     */
    public function analyzeChargingAvailability(float $lat, float $lon, int $radius_m = 5000): array {
        $stations = $this->findChargingStations($lat, $lon, $radius_m, 50);
        
        $analysis = [
            'total_stations' => count($stations),
            'fast_charging_count' => 0,
            'public_count' => 0,
            'tesla_count' => 0,
            'average_distance' => 0,
            'closest_distance' => null,
            'distribution' => [
                '0-500m' => 0,
                '500m-1km' => 0,
                '1km-2km' => 0,
                '2km+' => 0
            ]
        ];
        
        if (empty($stations)) {
            return $analysis;
        }
        
        $totalDistance = 0;
        $closestDistance = PHP_FLOAT_MAX;
        
        foreach ($stations as $station) {
            $distance = $station['distance_m'];
            $totalDistance += $distance;
            
            if ($distance < $closestDistance) {
                $closestDistance = $distance;
            }
            
            // Počítání typů
            if ($station['charging_type']['is_fast_charging']) {
                $analysis['fast_charging_count']++;
            }
            if ($station['charging_type']['is_public']) {
                $analysis['public_count']++;
            }
            if ($station['charging_type']['is_tesla']) {
                $analysis['tesla_count']++;
            }
            
            // Distribuce vzdáleností
            if ($distance <= 500) {
                $analysis['distribution']['0-500m']++;
            } elseif ($distance <= 1000) {
                $analysis['distribution']['500m-1km']++;
            } elseif ($distance <= 2000) {
                $analysis['distribution']['1km-2km']++;
            } else {
                $analysis['distribution']['2km+']++;
            }
        }
        
        $analysis['average_distance'] = round($totalDistance / count($stations));
        $analysis['closest_distance'] = round($closestDistance);
        
        return $analysis;
    }
}
