<?php
/**
 * Frontend Simulation Test
 * Simuluje frontend volání nearby API a isochronů
 */

// Ensure WordPress is loaded
if (!defined('ABSPATH')) {
    define('ABSPATH', dirname(__FILE__) . '/../../../');
}
require_once ABSPATH . 'wp-load.php';

class FrontendSimulator {
    
    private $results = [];
    private $base_url;
    
    public function __construct() {
        $this->base_url = home_url('/wp-json/db/v1/');
    }
    
    public function run_simulation() {
        echo "🎭 Spouštím simulaci frontend volání...\n";
        
        $this->simulate_nearby_calls();
        $this->simulate_isochrones_calls();
        $this->simulate_mixed_workflow();
        
        $this->generate_simulation_report();
    }
    
    private function simulate_nearby_calls() {
        echo "📡 Simuluji nearby API volání...\n";
        
        global $wpdb;
        
        // Získat náhodné charging locations
        $charging_locations = $wpdb->get_results("
            SELECT p.ID, lat.meta_value+0 AS lat, lng.meta_value+0 AS lng
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key = '_db_lat'
            INNER JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key = '_db_lng'
            WHERE p.post_type = 'charging_location'
            AND p.post_status = 'publish'
            AND lat.meta_value != '' AND lng.meta_value != ''
            ORDER BY RAND()
            LIMIT 10
        ");
        
        $nearby_results = [];
        $total_time = 0;
        $cache_hits = 0;
        
        foreach ($charging_locations as $location) {
            $start_time = microtime(true);
            
            // Simulovat checkNearbyDataAvailable volání
            $check_url = $this->base_url . "nearby?origin_id={$location->ID}&type=charging_location&limit=1";
            $check_response = wp_remote_get($check_url, [
                'timeout' => 10,
                'headers' => [
                    'X-WP-Nonce' => wp_create_nonce('wp_rest')
                ]
            ]);
            
            if (is_wp_error($check_response)) {
                continue;
            }
            
            $check_data = json_decode(wp_remote_retrieve_body($check_response), true);
            $check_time = microtime(true) - $start_time;
            
            if ($check_data && isset($check_data['items']) && count($check_data['items']) > 0) {
                // Simulovat loadAndRenderNearby volání
                $load_start = microtime(true);
                $load_url = $this->base_url . "nearby?origin_id={$location->ID}&type=charging_location&limit=9";
                $load_response = wp_remote_get($load_url, [
                    'timeout' => 10,
                    'headers' => [
                        'X-WP-Nonce' => wp_create_nonce('wp_rest')
                    ]
                ]);
                
                if (!is_wp_error($load_response)) {
                    $load_data = json_decode(wp_remote_retrieve_body($load_response), true);
                    $load_time = microtime(true) - $load_start;
                    
                    $nearby_results[] = [
                        'location_id' => $location->ID,
                        'check_time_ms' => round($check_time * 1000, 2),
                        'load_time_ms' => round($load_time * 1000, 2),
                        'total_time_ms' => round(($check_time + $load_time) * 1000, 2),
                        'items_count' => count($load_data['items'] ?? []),
                        'cached' => $load_data['cached'] ?? false,
                        'has_isochrones' => !empty($load_data['isochrones'])
                    ];
                    
                    if ($load_data['cached'] ?? false) {
                        $cache_hits++;
                    }
                }
            }
            
            $total_time += $check_time;
        }
        
        $this->results['nearby_simulation'] = [
            'total_calls' => count($nearby_results),
            'cache_hits' => $cache_hits,
            'cache_hit_rate' => count($nearby_results) > 0 ? round(($cache_hits / count($nearby_results)) * 100, 2) : 0,
            'avg_response_time_ms' => count($nearby_results) > 0 ? round(array_sum(array_column($nearby_results, 'total_time_ms')) / count($nearby_results), 2) : 0,
            'total_time_ms' => round($total_time * 1000, 2),
            'results' => $nearby_results
        ];
        
        echo "  ✅ Simulováno " . count($nearby_results) . " nearby volání\n";
        echo "  📊 Cache hit rate: " . $this->results['nearby_simulation']['cache_hit_rate'] . "%\n";
        echo "  ⏱️ Průměrná doba: " . $this->results['nearby_simulation']['avg_response_time_ms'] . "ms\n";
    }
    
    private function simulate_isochrones_calls() {
        echo "🗺️ Simuluji isochrony...\n";
        
        global $wpdb;
        
        // Získat náhodné body s koordináty
        $locations = $wpdb->get_results("
            SELECT p.ID, p.post_type, lat.meta_value+0 AS lat, lng.meta_value+0 AS lng
            FROM {$wpdb->posts} p
            INNER JOIN {$wpdb->postmeta} lat ON lat.post_id = p.ID AND lat.meta_key IN ('_db_lat', '_poi_lat', '_rv_lat')
            INNER JOIN {$wpdb->postmeta} lng ON lng.post_id = p.ID AND lng.meta_key IN ('_db_lng', '_poi_lng', '_rv_lng')
            WHERE p.post_type IN ('charging_location', 'poi', 'rv_spot')
            AND p.post_status = 'publish'
            AND lat.meta_value != '' AND lng.meta_value != ''
            ORDER BY RAND()
            LIMIT 5
        ");
        
        $isochrones_results = [];
        $total_time = 0;
        
        foreach ($locations as $location) {
            $start_time = microtime(true);
            
            // Simulovat isochrony volání
            $isochrones_url = $this->base_url . "isochrones?lat={$location->lat}&lng={$location->lng}&profile=foot-walking&range=600,1200,1800";
            $isochrones_response = wp_remote_get($isochrones_url, [
                'timeout' => 15,
                'headers' => [
                    'X-WP-Nonce' => wp_create_nonce('wp_rest')
                ]
            ]);
            
            if (!is_wp_error($isochrones_response)) {
                $isochrones_data = json_decode(wp_remote_retrieve_body($isochrones_response), true);
                $response_time = microtime(true) - $start_time;
                
                $isochrones_results[] = [
                    'location_id' => $location->ID,
                    'post_type' => $location->post_type,
                    'response_time_ms' => round($response_time * 1000, 2),
                    'success' => !empty($isochrones_data),
                    'cached' => $isochrones_data['cached'] ?? false
                ];
                
                $total_time += $response_time;
            }
        }
        
        $this->results['isochrones_simulation'] = [
            'total_calls' => count($isochrones_results),
            'successful_calls' => count(array_filter($isochrones_results, function($r) { return $r['success']; })),
            'avg_response_time_ms' => count($isochrones_results) > 0 ? round(array_sum(array_column($isochrones_results, 'response_time_ms')) / count($isochrones_results), 2) : 0,
            'total_time_ms' => round($total_time * 1000, 2),
            'results' => $isochrones_results
        ];
        
        echo "  ✅ Simulováno " . count($isochrones_results) . " isochronů\n";
        echo "  📊 Úspěšnost: " . round((count(array_filter($isochrones_results, function($r) { return $r['success']; })) / max(count($isochrones_results), 1)) * 100, 2) . "%\n";
        echo "  ⏱️ Průměrná doba: " . $this->results['isochrones_simulation']['avg_response_time_ms'] . "ms\n";
    }
    
    private function simulate_mixed_workflow() {
        echo "🔄 Simuluji smíšený workflow...\n";
        
        global $wpdb;
        
        // Simulovat typický uživatelský workflow
        $workflow_steps = [
            'map_load' => 1,
            'marker_click' => 3,
            'nearby_check' => 3,
            'nearby_load' => 3,
            'isochrones_request' => 2
        ];
        
        $workflow_results = [];
        $total_requests = 0;
        $total_time = 0;
        
        foreach ($workflow_steps as $step => $count) {
            for ($i = 0; $i < $count; $i++) {
                $start_time = microtime(true);
                
                switch ($step) {
                    case 'map_load':
                        // Simulovat načtení mapy
                        $map_url = $this->base_url . "map?bounds=49.5,12.0,51.0,18.0";
                        $response = wp_remote_get($map_url, ['timeout' => 10]);
                        break;
                        
                    case 'marker_click':
                        // Simulovat kliknutí na marker
                        $marker_url = $this->base_url . "map?bounds=49.5,12.0,51.0,18.0&limit=50";
                        $response = wp_remote_get($marker_url, ['timeout' => 10]);
                        break;
                        
                    case 'nearby_check':
                        // Simulovat kontrolu nearby dat
                        $nearby_url = $this->base_url . "nearby?origin_id=1&type=charging_location&limit=1";
                        $response = wp_remote_get($nearby_url, ['timeout' => 10]);
                        break;
                        
                    case 'nearby_load':
                        // Simulovat načtení nearby dat
                        $nearby_url = $this->base_url . "nearby?origin_id=1&type=charging_location&limit=9";
                        $response = wp_remote_get($nearby_url, ['timeout' => 10]);
                        break;
                        
                    case 'isochrones_request':
                        // Simulovat isochrony
                        $isochrones_url = $this->base_url . "isochrones?lat=50.08&lng=14.42&profile=foot-walking&range=600,1200,1800";
                        $response = wp_remote_get($isochrones_url, ['timeout' => 15]);
                        break;
                }
                
                $response_time = microtime(true) - $start_time;
                $total_requests++;
                $total_time += $response_time;
                
                $workflow_results[] = [
                    'step' => $step,
                    'response_time_ms' => round($response_time * 1000, 2),
                    'success' => !is_wp_error($response)
                ];
            }
        }
        
        $this->results['workflow_simulation'] = [
            'total_requests' => $total_requests,
            'total_time_ms' => round($total_time * 1000, 2),
            'avg_response_time_ms' => round(($total_time / $total_requests) * 1000, 2),
            'successful_requests' => count(array_filter($workflow_results, function($r) { return $r['success']; })),
            'by_step' => array_count_values(array_column($workflow_results, 'step')),
            'results' => $workflow_results
        ];
        
        echo "  ✅ Simulováno " . $total_requests . " požadavků v workflow\n";
        echo "  ⏱️ Celková doba: " . round($total_time * 1000, 2) . "ms\n";
        echo "  📊 Průměrná doba: " . round(($total_time / $total_requests) * 1000, 2) . "ms\n";
    }
    
    private function generate_simulation_report() {
        echo "\n" . str_repeat("=", 60) . "\n";
        echo "📋 VÝSLEDKY SIMULACE FRONTEND VOLÁNÍ\n";
        echo str_repeat("=", 60) . "\n";
        
        // Nearby simulace
        echo "\n📡 NEARBY SIMULACE:\n";
        echo "  • Celkem volání: " . $this->results['nearby_simulation']['total_calls'] . "\n";
        echo "  • Cache hit rate: " . $this->results['nearby_simulation']['cache_hit_rate'] . "%\n";
        echo "  • Průměrná doba: " . $this->results['nearby_simulation']['avg_response_time_ms'] . "ms\n";
        echo "  • Celková doba: " . $this->results['nearby_simulation']['total_time_ms'] . "ms\n";
        
        // Isochrony simulace
        echo "\n🗺️ ISOCHRONY SIMULACE:\n";
        echo "  • Celkem volání: " . $this->results['isochrones_simulation']['total_calls'] . "\n";
        echo "  • Úspěšnost: " . round(($this->results['isochrones_simulation']['successful_calls'] / max($this->results['isochrones_simulation']['total_calls'], 1)) * 100, 2) . "%\n";
        echo "  • Průměrná doba: " . $this->results['isochrones_simulation']['avg_response_time_ms'] . "ms\n";
        echo "  • Celková doba: " . $this->results['isochrones_simulation']['total_time_ms'] . "ms\n";
        
        // Workflow simulace
        echo "\n🔄 WORKFLOW SIMULACE:\n";
        echo "  • Celkem požadavků: " . $this->results['workflow_simulation']['total_requests'] . "\n";
        echo "  • Úspěšnost: " . round(($this->results['workflow_simulation']['successful_requests'] / $this->results['workflow_simulation']['total_requests']) * 100, 2) . "%\n";
        echo "  • Průměrná doba: " . $this->results['workflow_simulation']['avg_response_time_ms'] . "ms\n";
        echo "  • Celková doba: " . $this->results['workflow_simulation']['total_time_ms'] . "ms\n";
        
        echo "\n📊 ROZLOŽENÍ PODLE KROKŮ:\n";
        foreach ($this->results['workflow_simulation']['by_step'] as $step => $count) {
            echo "  • " . $step . ": " . $count . " volání\n";
        }
        
        // Analýza problémů
        echo "\n⚠️ ANALÝZA PROBLÉMŮ:\n";
        
        if ($this->results['nearby_simulation']['avg_response_time_ms'] > 500) {
            echo "  • Pomalé nearby volání (" . $this->results['nearby_simulation']['avg_response_time_ms'] . "ms)\n";
        }
        
        if ($this->results['isochrones_simulation']['avg_response_time_ms'] > 2000) {
            echo "  • Pomalé isochrony (" . $this->results['isochrones_simulation']['avg_response_time_ms'] . "ms)\n";
        }
        
        if ($this->results['nearby_simulation']['cache_hit_rate'] < 30) {
            echo "  • Nízká cache hit rate pro nearby (" . $this->results['nearby_simulation']['cache_hit_rate'] . "%)\n";
        }
        
        $total_requests = $this->results['workflow_simulation']['total_requests'];
        if ($total_requests > 10) {
            echo "  • Vysoký počet požadavků v workflow (" . $total_requests . ")\n";
        }
        
        echo "\n💡 DOPORUČENÍ:\n";
        echo "  • Implementujte agresivnější cache pro nearby data\n";
        echo "  • Optimalizujte isochrony volání\n";
        echo "  • Snižte počet požadavků v frontend workflow\n";
        echo "  • Používejte batch volání místo jednotlivých\n";
        
        echo str_repeat("=", 60) . "\n";
    }
}

// Spustit simulaci
$simulator = new FrontendSimulator();
$simulator->run_simulation();
