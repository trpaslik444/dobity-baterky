<?php
/**
 * REST API Controller pro nabíjecí stanice (pouze Mapy.com)
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB\Rest;

use DB\Services\Db_Charging_Service;

if (!defined('ABSPATH')) {
    exit;
}

class Db_Charging_Controller extends \WP_REST_Controller {

    public function register_routes(): void {
        register_rest_route('db/v1', '/charging-stations', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_get'],
            'args'     => [
                'lat' => ['required'=>true, 'type'=>'number'],
                'lon' => ['required'=>true, 'type'=>'number'],
                'radius_m' => ['required'=>false, 'type'=>'integer', 'default'=>3000],
                'limit' => ['required'=>false, 'type'=>'integer', 'default'=>20]
            ],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('db/v1', '/charging-stations/nearest', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_nearest'],
            'args'     => [
                'lat' => ['required'=>true, 'type'=>'number'],
                'lon' => ['required'=>true, 'type'=>'number'],
                'max_distance' => ['required'=>false, 'type'=>'integer', 'default'=>5000],
                'limit' => ['required'=>false, 'type'=>'integer', 'default'=>10]
            ],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('db/v1', '/charging-stations/analysis', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_analysis'],
            'args'     => [
                'lat' => ['required'=>true, 'type'=>'number'],
                'lon' => ['required'=>true, 'type'=>'number'],
                'radius_m' => ['required'=>false, 'type'=>'integer', 'default'=>5000]
            ],
            'permission_callback' => '__return_true'
        ]);
    }

    public function handle_get(\WP_REST_Request $req): \WP_REST_Response {
        $lat      = (float) $req->get_param('lat');
        $lon      = (float) $req->get_param('lon');
        $radius_m = (int) $req->get_param('radius_m');
        $limit    = (int) $req->get_param('limit');

        try {
            $service = new Db_Charging_Service();
            $stations = $service->findChargingStations($lat, $lon, $radius_m, $limit);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'stations' => $stations,
                    'count' => count($stations),
                    'query' => [
                        'lat' => $lat,
                        'lon' => $lon,
                        'radius_m' => $radius_m,
                        'limit' => $limit
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handle_nearest(\WP_REST_Request $req): \WP_REST_Response {
        $lat          = (float) $req->get_param('lat');
        $lon          = (float) $req->get_param('lon');
        $max_distance = (int) $req->get_param('max_distance');
        $limit        = (int) $req->get_param('limit');

        try {
            $service = new Db_Charging_Service();
            $stations = $service->findNearestChargingStations($lat, $lon, $max_distance, $limit);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'stations' => $stations,
                    'count' => count($stations),
                    'query' => [
                        'lat' => $lat,
                        'lon' => $lon,
                        'max_distance' => $max_distance,
                        'limit' => $limit
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handle_analysis(\WP_REST_Request $req): \WP_REST_Response {
        $lat      = (float) $req->get_param('lat');
        $lon      = (float) $req->get_param('lon');
        $radius_m = (int) $req->get_param('radius_m');

        try {
            $service = new Db_Charging_Service();
            $analysis = $service->analyzeChargingAvailability($lat, $lon, $radius_m);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'analysis' => $analysis,
                    'query' => [
                        'lat' => $lat,
                        'lon' => $lon,
                        'radius_m' => $radius_m
                    ]
                ]
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }
}
