<?php
/**
 * Správa URL a navigace pro CPT místa
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Třída pro správu URL a navigace mezi místy
 */
class CPT_URL_Manager {

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
        add_action( 'init', array( $this, 'init' ) );
        add_filter( 'post_type_link', array( $this, 'customize_permalink' ), 10, 2 );
        add_action( 'wp_head', array( $this, 'add_meta_tags' ) );
    }

    /**
     * Inicializace
     */
    public function init() {
        // Přidání meta tagů pro lepší SEO
        add_action( 'wp_head', array( $this, 'add_meta_tags' ) );
        
        // Přidání Open Graph tagů
        add_action( 'wp_head', array( $this, 'add_open_graph_tags' ) );
    }

    /**
     * Přizpůsobí permalink pro CPT
     */
    public function customize_permalink( $permalink, $post ) {
        if ( ! in_array( $post->post_type, array( 'charging_location', 'rv_spot', 'poi' ) ) ) {
            return $permalink;
        }

        $slug_map = array(
            'charging_location' => 'lokality',
            'rv_spot' => 'rv-mista',
            'poi' => 'tipy'
        );

        $base_slug = $slug_map[ $post->post_type ];
        return home_url( '/' . $base_slug . '/' . $post->post_name . '/' );
    }

    /**
     * Vytvoří URL pro konkrétní místo
     */
    public function get_location_url( $post_id, $post_type = null ) {
        if ( ! $post_type ) {
            $post = get_post( $post_id );
            if ( ! $post ) return '';
            $post_type = $post->post_type;
        }

        $slug_map = array(
            'charging_location' => 'lokality',
            'rv_spot' => 'rv-mista',
            'poi' => 'tipy'
        );

        $base_slug = $slug_map[ $post_type ];
        $post_name = get_post_field( 'post_name', $post_id );
        
        return home_url( '/' . $base_slug . '/' . $post_name . '/' );
    }

    /**
     * Vytvoří odkaz pro otevření místa v nové záložce
     */
    public function get_location_link( $post_id, $post_type = null, $text = null ) {
        $url = $this->get_location_url( $post_id, $post_type );
        if ( ! $url ) return '';

        if ( ! $text ) {
            $post = get_post( $post_id );
            $text = $post ? $post->post_title : 'Zobrazit místo';
        }

        return sprintf(
            '<a href="%s" target="_blank" class="db-location-link" data-post-id="%d" data-post-type="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $post_id ),
            esc_attr( $post_type ?: get_post_type( $post_id ) ),
            esc_html( $text )
        );
    }

    /**
     * Vytvoří odkaz pro otevření místa v nové záložce s ikonou
     */
    public function get_location_link_with_icon( $post_id, $post_type = null, $text = null, $icon = '🔗' ) {
        $url = $this->get_location_url( $post_id, $post_type );
        if ( ! $url ) return '';

        if ( ! $text ) {
            $post = get_post( $post_id );
            $text = $post ? $post->post_title : 'Zobrazit místo';
        }

        return sprintf(
            '<a href="%s" target="_blank" class="db-location-link db-location-link-with-icon" data-post-id="%d" data-post-type="%s">%s %s</a>',
            esc_url( $url ),
            esc_attr( $post_id ),
            esc_attr( $post_type ?: get_post_type( $post_id ) ),
            $icon,
            esc_html( $text )
        );
    }

    /**
     * Vytvoří tlačítko pro otevření místa
     */
    public function get_location_button( $post_id, $post_type = null, $text = null, $class = 'db-button' ) {
        $url = $this->get_location_url( $post_id, $post_type );
        if ( ! $url ) return '';

        if ( ! $text ) {
            $post = get_post( $post_id );
            $text = $post ? $post->post_title : 'Zobrazit místo';
        }

        return sprintf(
            '<a href="%s" target="_blank" class="%s db-location-button" data-post-id="%d" data-post-type="%s">%s</a>',
            esc_url( $url ),
            esc_attr( $class ),
            esc_attr( $post_id ),
            esc_attr( $post_type ?: get_post_type( $post_id ) ),
            esc_html( $text )
        );
    }

    /**
     * Přidá meta tagy pro lepší SEO
     */
    public function add_meta_tags() {
        if ( ! is_singular( array( 'charging_location', 'rv_spot', 'poi' ) ) ) {
            return;
        }

        global $post;
        if ( ! $post ) return;

        $title = get_the_title( $post->ID );
        $description = wp_strip_all_tags( get_the_excerpt( $post->ID ) ?: get_the_title( $post->ID ) );
        $url = get_permalink( $post->ID );

        // Canonical URL
        echo '<link rel="canonical" href="' . esc_url( $url ) . '" />' . "\n";

        // Meta description
        if ( $description ) {
            echo '<meta name="description" content="' . esc_attr( $description ) . '" />' . "\n";
        }

        // Meta keywords podle typu místa
        $keywords = array( 'Dobitý Baterky' );
        
        switch ( $post->post_type ) {
            case 'charging_location':
                $keywords[] = 'nabíjecí stanice';
                $keywords[] = 'elektromobil';
                $keywords[] = 'EV charging';
                break;
            case 'rv_spot':
                $keywords[] = 'RV místo';
                $keywords[] = 'karavan';
                $keywords[] = 'obytné vozidlo';
                break;
            case 'poi':
                $keywords[] = 'POI';
                $keywords[] = 'bod zájmu';
                $keywords[] = 'tipy na výlet';
                break;
        }

        echo '<meta name="keywords" content="' . esc_attr( implode( ', ', $keywords ) ) . '" />' . "\n";
    }

    /**
     * Přidá Open Graph tagy pro sociální sítě
     */
    public function add_open_graph_tags() {
        if ( ! is_singular( array( 'charging_location', 'rv_spot', 'poi' ) ) ) {
            return;
        }

        global $post;
        if ( ! $post ) return;

        $title = get_the_title( $post->ID );
        $description = wp_strip_all_tags( get_the_excerpt( $post->ID ) ?: get_the_title( $post->ID ) );
        $url = get_permalink( $post->ID );
        $image = get_the_post_thumbnail_url( $post->ID, 'large' );

        // Open Graph tagy
        echo '<meta property="og:type" content="article" />' . "\n";
        echo '<meta property="og:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta property="og:description" content="' . esc_attr( $description ) . '" />' . "\n";
        echo '<meta property="og:url" content="' . esc_url( $url ) . '" />' . "\n";
        
        if ( $image ) {
            echo '<meta property="og:image" content="' . esc_url( $image ) . '" />' . "\n";
        }

        // Twitter Card tagy
        echo '<meta name="twitter:card" content="summary_large_image" />' . "\n";
        echo '<meta name="twitter:title" content="' . esc_attr( $title ) . '" />' . "\n";
        echo '<meta name="twitter:description" content="' . esc_attr( $description ) . '" />' . "\n";
        
        if ( $image ) {
            echo '<meta name="twitter:image" content="' . esc_url( $image ) . '" />' . "\n";
        }
    }

    /**
     * Vytvoří breadcrumb navigaci
     */
    public function get_breadcrumbs( $post_id = null ) {
        if ( ! $post_id ) {
            global $post;
            $post_id = $post ? $post->ID : 0;
        }

        if ( ! $post_id ) return '';

        $post = get_post( $post_id );
        if ( ! $post ) return '';

        $breadcrumbs = array();
        
        // Domovská stránka
        $breadcrumbs[] = '<a href="' . home_url() . '">Domů</a>';
        
        // Typ místa
        $type_labels = array(
            'charging_location' => 'Nabíjecí lokality',
            'rv_spot' => 'RV místa',
            'poi' => 'Tipy na výlet'
        );
        
        $type_label = $type_labels[ $post->post_type ] ?? 'Místa';
        $type_url = home_url( '/' . $this->get_type_slug( $post->post_type ) . '/' );
        
        $breadcrumbs[] = '<a href="' . $type_url . '">' . $type_label . '</a>';
        
        // Aktuální místo
        $breadcrumbs[] = '<span class="current">' . get_the_title( $post_id ) . '</span>';

        return '<nav class="db-breadcrumbs">' . implode( ' / ', $breadcrumbs ) . '</nav>';
    }

    /**
     * Získá slug pro typ místa
     */
    private function get_type_slug( $post_type ) {
        $slug_map = array(
            'charging_location' => 'lokality',
            'rv_spot' => 'rv-mista',
            'poi' => 'tipy'
        );

        return $slug_map[ $post_type ] ?? 'mista';
    }

    /**
     * Vytvoří navigační menu pro přepínání mezi místy
     */
    public function get_location_navigation( $current_post_id, $post_type = null ) {
        if ( ! $post_type ) {
            $post = get_post( $current_post_id );
            if ( ! $post ) return '';
            $post_type = $post->post_type;
        }

        // Získání sousedních míst stejného typu
        $args = array(
            'post_type' => $post_type,
            'posts_per_page' => -1,
            'post_status' => 'publish',
            'orderby' => 'title',
            'order' => 'ASC'
        );

        $locations = get_posts( $args );
        if ( count( $locations ) <= 1 ) return '';

        $navigation = '<div class="db-location-navigation">';
        $navigation .= '<h3>Všechna místa typu ' . $this->get_type_label( $post_type ) . '</h3>';
        $navigation .= '<div class="db-location-list">';

        foreach ( $locations as $location ) {
            $is_current = ( $location->ID == $current_post_id );
            $class = $is_current ? 'current' : '';
            $link = $this->get_location_url( $location->ID, $post_type );
            
            $navigation .= sprintf(
                '<a href="%s" class="db-location-nav-item %s" target="_blank">%s</a>',
                esc_url( $link ),
                esc_attr( $class ),
                esc_html( $location->post_title )
            );
        }

        $navigation .= '</div></div>';
        return $navigation;
    }

    /**
     * Získá label pro typ místa
     */
    private function get_type_label( $post_type ) {
        $labels = array(
            'charging_location' => 'Nabíjecí lokality',
            'rv_spot' => 'RV místa',
            'poi' => 'Tipy na výlet'
        );

        return $labels[ $post_type ] ?? 'Místa';
    }
}
