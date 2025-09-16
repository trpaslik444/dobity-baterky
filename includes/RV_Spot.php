<?php
/**
 * Registrace vlastního post typu pro místa určená obytným vozům (RV)
 *
 * @package DobityBaterky
 */

namespace DB;

/**
 * Třída pro registraci CPT rv_spot (RV místa)
 */
class RV_Spot {
    /** @var self */
    private static $instance = null;

    /**
     * Singleton instance
     * @return self
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
        error_log('[RV_Spot DEBUG] Registruji RV_Spot CPT');
        add_action( 'init', array( $this, 'register_cpt' ) );
    }

    /**
     * Registruje vlastní post type rv_spot
     */
    public function register_cpt() {
        error_log('[RV_Spot DEBUG] Spouštím registraci rv_spot post type');
        
        $labels = array(
            'name'               => _x( 'RV místa', 'Post type general name', 'dobity-baterky' ),
            'singular_name'      => _x( 'RV místo', 'Post type singular name', 'dobity-baterky' ),
            'menu_name'          => _x( 'RV místa', 'Admin Menu text', 'dobity-baterky' ),
            'name_admin_bar'     => _x( 'RV místo', 'Add New on Toolbar', 'dobity-baterky' ),
            'add_new'            => __( 'Přidat nové', 'dobity-baterky' ),
            'add_new_item'       => __( 'Přidat nové RV místo', 'dobity-baterky' ),
            'new_item'           => __( 'Nové RV místo', 'dobity-baterky' ),
            'edit_item'          => __( 'Upravit RV místo', 'dobity-baterky' ),
            'view_item'          => __( 'Zobrazit RV místo', 'dobity-baterky' ),
            'all_items'          => __( 'Všechna RV místa', 'dobity-baterky' ),
            'search_items'       => __( 'Hledat RV místa', 'dobity-baterky' ),
            'parent_item_colon'  => __( 'Nadřazené RV místo:', 'dobity-baterky' ),
            'not_found'          => __( 'Žádná RV místa nenalezena.', 'dobity-baterky' ),
            'not_found_in_trash' => __( 'Žádná RV místa v koši.', 'dobity-baterky' ),
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
            'menu_icon'          => 'dashicons-dashboard',
            'menu_position'      => 26,
            'rewrite'            => array( 'slug' => 'rv-mista' ),
            'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
        );

        register_post_type( 'rv_spot', $args );
        error_log('[RV_Spot DEBUG] rv_spot post type zaregistrován');
        
        // Registrace taxonomie typů RV stání
        $labels = array(
            'name'              => _x( 'Typy RV stání', 'taxonomy general name', 'dobity-baterky' ),
            'singular_name'     => _x( 'Typ RV stání', 'taxonomy singular name', 'dobity-baterky' ),
            'search_items'      => __( 'Hledat typy RV stání', 'dobity-baterky' ),
            'all_items'         => __( 'Všechny typy RV stání', 'dobity-baterky' ),
            'edit_item'         => __( 'Upravit typ RV stání', 'dobity-baterky' ),
            'update_item'       => __( 'Aktualizovat typ RV stání', 'dobity-baterky' ),
            'add_new_item'      => __( 'Přidat nový typ RV stání', 'dobity-baterky' ),
            'new_item_name'     => __( 'Název nového typu RV stání', 'dobity-baterky' ),
            'menu_name'         => __( 'Typy RV stání', 'dobity-baterky' ),
        );
        register_taxonomy( 'rv_type', array( 'rv_spot' ), array(
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'typ-rv' ),
        ) );
    }
} 