<?php
/**
 * REST API Controller pro nabíjecí stanice
 * @package DobityBaterky
 */

declare(strict_types=1);

namespace DB\Rest;

use DB\Services\Db_Mapy_Service;
use DB\Services\Db_Ocm_Service;
use DB\Services\Db_Charging_Enricher;

if (!defined('ABSPATH')) {
    exit;
}

class Db_Chargers_Controller extends \WP_REST_Controller {

    public function register_routes(): void {
        register_rest_route('db/v1', '/chargers', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_get'],
            'args'     => [
                'lat' => ['required'=>true, 'type'=>'number'],
                'lon' => ['required'=>true, 'type'=>'number'],
                'radius_m' => ['required'=>false, 'type'=>'integer', 'default'=>1200],
                'limit' => ['required'=>false, 'type'=>'integer', 'default'=>12]
            ],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('db/v1', '/chargers/nearest', [
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

        register_rest_route('db/v1', '/chargers/ocm/(?P<ocm_id>\d+)', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_ocm_detail'],
            'args'     => [
                'ocm_id' => ['required'=>true, 'type'=>'integer'],
                'lat' => ['required'=>true, 'type'=>'number'],
                'lon' => ['required'=>true, 'type'=>'number']
            ],
            'permission_callback' => '__return_true'
        ]);

        register_rest_route('db/v1', '/poi', [
            'methods'  => \WP_REST_Server::READABLE,
            'callback' => [$this, 'handle_poi_search'],
            'args'     => [
                'query' => ['required'=>true, 'type'=>'string'],
                'lat' => ['required'=>true, 'type'=>'number'],
                'lon' => ['required'=>true, 'type'=>'number'],
                'radius_m' => ['required'=>false, 'type'=>'integer', 'default'=>1200],
                'limit' => ['required'=>false, 'type'=>'integer', 'default'=>12]
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
            $enricher = new Db_Charging_Enricher(new Db_Mapy_Service(), new Db_Ocm_Service());
            $items    = $enricher->getEnrichedChargers($lat, $lon, $radius_m, $limit, 1);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'count' => count($items),
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
            $enricher = new Db_Charging_Enricher(new Db_Mapy_Service(), new Db_Ocm_Service());
            $items    = $enricher->findNearestChargers($lat, $lon, $max_distance, $limit);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'count' => count($items),
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

    public function handle_ocm_detail(\WP_REST_Request $req): \WP_REST_Response {
        $ocm_id = (int) $req->get_param('ocm_id');
        $lat    = (float) $req->get_param('lat');
        $lon    = (float) $req->get_param('lon');

        try {
            $enricher = new Db_Charging_Enricher(new Db_Mapy_Service(), new Db_Ocm_Service());
            $item     = $enricher->getEnrichedChargerByOCMId($ocm_id, $lat, $lon);

            if (!$item) {
                return new \WP_REST_Response([
                    'success' => false,
                    'error' => 'Charging station not found'
                ], 404);
            }

            return new \WP_REST_Response([
                'success' => true,
                'data' => $item
            ], 200);
        } catch (\Exception $e) {
            return new \WP_REST_Response([
                'success' => false,
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function handle_poi_search(\WP_REST_Request $req): \WP_REST_Response {
        $query   = sanitize_text_field($req->get_param('query'));
        $lat     = (float) $req->get_param('lat');
        $lon     = (float) $req->get_param('lon');
        $radius_m = (int) $req->get_param('radius_m');
        $limit   = (int) $req->get_param('limit');

        try {
            $enricher = new Db_Charging_Enricher(new Db_Mapy_Service(), new Db_Ocm_Service());
            $items    = $enricher->getEnrichedPOI($query, $lat, $lon, $radius_m, $limit);

            return new \WP_REST_Response([
                'success' => true,
                'data' => [
                    'items' => $items,
                    'count' => count($items),
                    'query' => [
                        'query' => $query,
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
}
