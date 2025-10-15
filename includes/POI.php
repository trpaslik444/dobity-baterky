<?php
/**
 * CPT pro Body zájmu (POI)
 * @package DobityBaterky
 */

namespace DB;

/**
 * Třída pro registraci CPT poi (Body zájmu)
 */
class POI {
    private static $instance = null;

    /**
     * Singleton instance
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Připojí registraci CPT na hook init
     */
    public function register() {
        error_log('[POI DEBUG] Registruji POI CPT');
        add_action( 'init', array( $this, 'register_cpt' ) );
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        // Po publikaci POI -> zařadit do discovery fronty
        add_action( 'publish_poi', array( $this, 'enqueue_discovery_on_publish' ), 10, 1 );
        // Sloupec a meta pro "DB doporučuje"
        add_filter( 'manage_poi_posts_columns', array( $this, 'add_recommended_column' ) );
        add_action( 'manage_poi_posts_custom_column', array( $this, 'render_recommended_column' ), 10, 2 );
        add_filter( 'manage_edit-poi_sortable_columns', function($cols){ $cols['db_recommended']= 'db_recommended'; return $cols; } );
        add_action( 'pre_get_posts', array( $this, 'sort_by_recommended' ) );
    }

    public function enqueue_discovery_on_publish( $post_id ) {
        try {
            if ( file_exists( __DIR__ . '/Jobs/POI_Discovery_Queue_Manager.php' ) ) {
                require_once __DIR__ . '/Jobs/POI_Discovery_Queue_Manager.php';
                $qm = new \DB\Jobs\POI_Discovery_Queue_Manager();
                $qm->enqueue( (int)$post_id, 0 );
                if ( file_exists( __DIR__ . '/Jobs/POI_Discovery_Worker.php' ) ) {
                    require_once __DIR__ . '/Jobs/POI_Discovery_Worker.php';
                    \DB\Jobs\POI_Discovery_Worker::dispatch(1);
                }
            }
        } catch ( \Throwable $__ ) {}
    }

    /**
     * Registruje vlastní post type poi
     */
    public function register_cpt() {
        error_log('[POI DEBUG] Spouštím registraci poi post type');
        
        $labels = array(
            'name'               => _x( 'Body zájmu / POI', 'Post type general name', 'dobity-baterky' ),
            'singular_name'      => _x( 'POI', 'Post type singular name', 'dobity-baterky' ),
            'menu_name'          => _x( 'Body zájmu', 'Admin Menu text', 'dobity-baterky' ),
            'name_admin_bar'     => _x( 'POI', 'Add New on Toolbar', 'dobity-baterky' ),
            'add_new'            => __( 'Přidat nový', 'dobity-baterky' ),
            'add_new_item'       => __( 'Přidat nový POI', 'dobity-baterky' ),
            'new_item'           => __( 'Nový POI', 'dobity-baterky' ),
            'edit_item'          => __( 'Upravit POI', 'dobity-baterky' ),
            'view_item'          => __( 'Zobrazit POI', 'dobity-baterky' ),
            'all_items'          => __( 'Všechny body zájmu', 'dobity-baterky' ),
            'search_items'       => __( 'Hledat body zájmu', 'dobity-baterky' ),
            'parent_item_colon'  => __( 'Nadřazený POI:', 'dobity-baterky' ),
            'not_found'          => __( 'Žádné body zájmu nenalezeny.', 'dobity-baterky' ),
            'not_found_in_trash' => __( 'Žádné body zájmu v koši.', 'dobity-baterky' ),
        );
        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'has_archive'        => true,
            'show_in_rest'       => true,
            'menu_icon'          => 'dashicons-location-alt',
            'menu_position'      => 27,
            'rewrite'            => array( 'slug' => 'tipy' ),
            'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
            'taxonomies'         => array( 'poi_type' ),
        );
        register_post_type( 'poi', $args );
        error_log('[POI DEBUG] poi post type zaregistrován');
    }

    // Admin: sloupec "DB doporučuje"
    public function add_recommended_column( $columns ) {
        $columns['db_recommended'] = 'DB doporučuje';
        return $columns;
    }

    public function render_recommended_column( $column, $post_id ) {
        if ( $column === 'db_recommended' ) {
            $val = get_post_meta( $post_id, '_db_recommended', true );
            echo $val ? '✓' : '—';
        }
    }

    public function sort_by_recommended( $query ) {
        if ( ! is_admin() || ! $query->is_main_query() ) return;
        if ( $query->get( 'orderby' ) === 'db_recommended' ) {
            $query->set( 'meta_key', '_db_recommended' );
            $query->set( 'orderby', 'meta_value_num' );
        }
    }

    /**
     * Registruje taxonomii poi_type
     */
    public function register_taxonomy() {
        $labels = array(
            'name'              => _x( 'Typy POI', 'taxonomy general name', 'dobity-baterky' ),
            'singular_name'     => _x( 'Typ POI', 'taxonomy singular name', 'dobity-baterky' ),
            'search_items'      => __( 'Hledat typy POI', 'dobity-baterky' ),
            'all_items'         => __( 'Všechny typy POI', 'dobity-baterky' ),
            'edit_item'         => __( 'Upravit typ POI', 'dobity-baterky' ),
            'update_item'       => __( 'Aktualizovat typ POI', 'dobity-baterky' ),
            'add_new_item'      => __( 'Přidat nový typ POI', 'dobity-baterky' ),
            'new_item_name'     => __( 'Název nového typu POI', 'dobity-baterky' ),
            'menu_name'         => __( 'Typy POI', 'dobity-baterky' ),
        );
        register_taxonomy( 'poi_type', array( 'poi' ), array(
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'typ-poi' ),
        ) );
    }
} 