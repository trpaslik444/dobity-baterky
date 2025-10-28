<?php
/**
 * REST endpoints for handling favorites folders.
 *
 * @package DobityBaterky
 */

namespace DB;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;

class REST_Favorites {
    private static $instance = null;

    /** @var Favorites_Manager */
    private $manager;

    public static function get_instance(): REST_Favorites {
        if ( null === self::$instance ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->manager = Favorites_Manager::get_instance();
    }

    public function register(): void {
        add_action( 'rest_api_init', array( $this, 'register_routes' ) );
    }

    public function register_routes(): void {
        register_rest_route( 'db/v1', '/favorites', array(
            'methods'             => 'GET',
            'callback'            => array( $this, 'get_favorites' ),
            'permission_callback' => array( $this, 'ensure_permission' ),
        ) );

        register_rest_route( 'db/v1', '/favorites/folders', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'create_folder' ),
            'permission_callback' => array( $this, 'ensure_permission' ),
            'args'                => array(
                'name' => array(
                    'type'     => 'string',
                    'required' => true,
                ),
                'icon' => array(
                    'type' => 'string',
                ),
            ),
        ) );

        register_rest_route( 'db/v1', '/favorites/folders/(?P<folder_id>[A-Za-z0-9_\-]+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'delete_folder' ),
            'permission_callback' => array( $this, 'ensure_permission' ),
        ) );

        register_rest_route( 'db/v1', '/favorites/assign', array(
            'methods'             => 'POST',
            'callback'            => array( $this, 'assign' ),
            'permission_callback' => array( $this, 'ensure_permission' ),
        ) );

        register_rest_route( 'db/v1', '/favorites/assign/(?P<post_id>\d+)', array(
            'methods'             => 'DELETE',
            'callback'            => array( $this, 'remove_assignment' ),
            'permission_callback' => array( $this, 'ensure_permission' ),
        ) );
    }

    public function ensure_permission( WP_REST_Request $request ) {
        if ( ! wp_verify_nonce( $request->get_header( 'X-WP-Nonce' ), 'wp_rest' ) ) {
            return new WP_Error( 'invalid_nonce', __( 'Neplatný bezpečnostní token.', 'dobity-baterky' ), array( 'status' => 403 ) );
        }

        if ( ! is_user_logged_in() ) {
            return new WP_Error( 'forbidden', __( 'Musíte být přihlášeni.', 'dobity-baterky' ), array( 'status' => 401 ) );
        }

        if ( function_exists( 'db_user_can_see_map' ) && ! db_user_can_see_map() ) {
            return new WP_Error( 'forbidden', __( 'Nemáte oprávnění pro práci s oblíbenými.', 'dobity-baterky' ), array( 'status' => 403 ) );
        }

        return true;
    }

    public function get_favorites( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'forbidden', __( 'Musíte být přihlášeni.', 'dobity-baterky' ), array( 'status' => 401 ) );
        }

        $payload = $this->manager->get_localized_payload( $user_id );

        return rest_ensure_response( array(
            'folders'     => $payload['folders'],
            'assignments' => $payload['assignments'],
            'limits'      => array(
                'default' => Favorites_Manager::DEFAULT_FOLDER_LIMIT,
                'custom'  => Favorites_Manager::CUSTOM_FOLDER_LIMIT,
            ),
            'max_custom_folders' => Favorites_Manager::MAX_CUSTOM_FOLDERS,
        ) );
    }

    public function create_folder( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'forbidden', __( 'Musíte být přihlášeni.', 'dobity-baterky' ), array( 'status' => 401 ) );
        }

        $params = $request->get_json_params();
        $name   = isset( $params['name'] ) ? (string) $params['name'] : '';
        $icon   = isset( $params['icon'] ) ? (string) $params['icon'] : '';

        try {
            $folder = $this->manager->create_folder( $user_id, $name, $icon );
            $state  = $this->manager->get_state( $user_id );
            return rest_ensure_response( array(
                'folder'      => $folder,
                'folders'     => $this->manager->get_folders( $user_id ),
                'assignments' => $state['assignments'],
            ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'favorite_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }

    public function assign( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'forbidden', __( 'Musíte být přihlášeni.', 'dobity-baterky' ), array( 'status' => 401 ) );
        }

        $params   = $request->get_json_params();
        $post_id  = isset( $params['post_id'] ) ? absint( $params['post_id'] ) : 0;
        $folder_id = isset( $params['folder_id'] ) ? (string) $params['folder_id'] : '';
        $force    = ! empty( $params['force'] );

        if ( $post_id <= 0 || '' === $folder_id ) {
            return new WP_Error( 'invalid_params', __( 'Chybí potřebné parametry.', 'dobity-baterky' ), array( 'status' => 400 ) );
        }

        try {
            $result = $this->manager->assign_post( $user_id, $post_id, $folder_id, $force );
            if ( isset( $result['requires_confirmation'] ) && $result['requires_confirmation'] ) {
                return new WP_REST_Response( $result, 409 );
            }

            $folder = $this->manager->get_folder( $user_id, $folder_id );
            return rest_ensure_response( array(
                'success'        => true,
                'folder'         => $folder,
                'previousFolder' => $result['previous_folder'] ?? null,
                'counts'         => $result['counts'] ?? array(),
                'assignments'    => $result['assignments'] ?? array(),
            ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'favorite_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }

    public function remove_assignment( WP_REST_Request $request ) {
        $user_id = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'forbidden', __( 'Musíte být přihlášeni.', 'dobity-baterky' ), array( 'status' => 401 ) );
        }

        $post_id = absint( $request['post_id'] );
        if ( $post_id <= 0 ) {
            return new WP_Error( 'invalid_params', __( 'Chybí identifikátor místa.', 'dobity-baterky' ), array( 'status' => 400 ) );
        }

        try {
            $result = $this->manager->remove_post( $user_id, $post_id );
            return rest_ensure_response( array(
                'success'     => true,
                'counts'      => $result['counts'],
                'assignments' => $result['assignments'],
            ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'favorite_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }

    public function delete_folder( WP_REST_Request $request ) {
        $user_id   = get_current_user_id();
        if ( $user_id <= 0 ) {
            return new WP_Error( 'forbidden', __( 'Musíte být přihlášeni.', 'dobity-baterky' ), array( 'status' => 401 ) );
        }

        $folder_id = (string) $request['folder_id'];
        if ( $folder_id === '' ) {
            return new WP_Error( 'invalid_params', __( 'Chybí identifikátor složky.', 'dobity-baterky' ), array( 'status' => 400 ) );
        }

        try {
            $result = $this->manager->delete_folder( $user_id, $folder_id );
            return rest_ensure_response( array(
                'success'     => true,
                'folders'     => $result['folders'],
                'assignments' => $result['assignments'],
            ) );
        } catch ( \Throwable $e ) {
            return new WP_Error( 'favorite_error', $e->getMessage(), array( 'status' => 400 ) );
        }
    }
}
