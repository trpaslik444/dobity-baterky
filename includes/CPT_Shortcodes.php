<?php
/**
 * Shortcode pro zobrazení odkazů na místa
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Třída pro shortcode funkce
 */
class CPT_Shortcodes {

    /**
     * Singleton instance
     */
    private static $instance = null;

    /**
     * Získá instanci třídy
     */
    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    private function __construct() {
        add_action( 'init', array( $this, 'register_shortcodes' ) );
    }

    /**
     * Registruje všechny shortcode
     */
    public function register_shortcodes() {
        // Shortcode pro odkaz na místo
        add_shortcode( 'db_location_link', array( $this, 'location_link_shortcode' ) );
        
        // Shortcode pro tlačítko místa
        add_shortcode( 'db_location_button', array( $this, 'location_button_shortcode' ) );
        
        // Shortcode pro seznam míst
        add_shortcode( 'db_locations_list', array( $this, 'locations_list_shortcode' ) );
        
        // Shortcode pro navigaci mezi místy
        add_shortcode( 'db_location_nav', array( $this, 'location_nav_shortcode' ) );
    }

    /**
     * Shortcode pro odkaz na místo
     * Použití: [db_location_link id="123" type="charging_location" text="Zobrazit stanici"]
     */
    public function location_link_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
            'type' => '',
            'text' => '',
            'icon' => '🔗',
            'class' => 'db-location-link'
        ), $atts );

        if ( ! $atts['id'] ) {
            return '<span class="db-error">Chybí ID místa</span>';
        }

        $url_manager = CPT_URL_Manager::get_instance();
        return $url_manager->get_location_link_with_icon( 
            intval( $atts['id'] ), 
            $atts['type'], 
            $atts['text'], 
            $atts['icon'] 
        );
    }

    /**
     * Shortcode pro tlačítko místa
     * Použití: [db_location_button id="123" type="charging_location" text="Zobrazit stanici"]
     */
    public function location_button_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'id' => 0,
            'type' => '',
            'text' => '',
            'class' => 'db-button db-button-primary'
        ), $atts );

        if ( ! $atts['id'] ) {
            return '<span class="db-error">Chybí ID místa</span>';
        }

        $url_manager = CPT_URL_Manager::get_instance();
        return $url_manager->get_location_button( 
            intval( $atts['id'] ), 
            $atts['type'], 
            $atts['text'], 
            $atts['class'] 
        );
    }

    /**
     * Shortcode pro seznam míst
     * Použití: [db_locations_list type="charging_location" limit="10" orderby="title"]
     */
    public function locations_list_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'type' => 'charging_location',
            'limit' => 10,
            'orderby' => 'title',
            'order' => 'ASC',
            'show_map' => 'false',
            'show_description' => 'true'
        ), $atts );

        $args = array(
            'post_type' => $atts['type'],
            'posts_per_page' => intval( $atts['limit'] ),
            'post_status' => 'publish',
            'orderby' => $atts['orderby'],
            'order' => $atts['order']
        );

        $locations = get_posts( $args );
        if ( empty( $locations ) ) {
            return '<p>Žádná místa nebyla nalezena.</p>';
        }

        $url_manager = CPT_URL_Manager::get_instance();
        $output = '<div class="db-locations-list db-locations-list-' . esc_attr( $atts['type'] ) . '">';
        
        foreach ( $locations as $location ) {
            $output .= '<div class="db-location-item">';
            $output .= '<h3>' . esc_html( $location->post_title ) . '</h3>';
            
            if ( $atts['show_description'] === 'true' && $location->post_excerpt ) {
                $output .= '<p>' . esc_html( $location->post_excerpt ) . '</p>';
            }
            
            $output .= $url_manager->get_location_button( 
                $location->ID, 
                $atts['type'], 
                'Zobrazit místo',
                'db-button db-button-small'
            );
            
            $output .= '</div>';
        }
        
        $output .= '</div>';
        return $output;
    }

    /**
     * Shortcode pro navigaci mezi místy
     * Použití: [db_location_nav current_id="123" type="charging_location"]
     */
    public function location_nav_shortcode( $atts ) {
        $atts = shortcode_atts( array(
            'current_id' => 0,
            'type' => ''
        ), $atts );

        if ( ! $atts['current_id'] ) {
            global $post;
            $atts['current_id'] = $post ? $post->ID : 0;
        }

        if ( ! $atts['current_id'] ) {
            return '<span class="db-error">Nebylo možné určit aktuální místo</span>';
        }

        $url_manager = CPT_URL_Manager::get_instance();
        return $url_manager->get_location_navigation( 
            intval( $atts['current_id'] ), 
            $atts['type'] 
        );
    }
}
