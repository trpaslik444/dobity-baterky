<?php
/**
 * Mikrolokality pomocí Geohashe (taxonomie spot_zone)
 * @package DobityBaterky
 */

namespace DB;

/**
 * Třída pro správu mikrolokalit (geohash zón)
 */
class Spot_Zone {
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
     * Registrace hooků
     */
    public function register() {
        error_log('[Spot_Zone DEBUG] Registruji Spot_Zone taxonomii');
        add_action( 'init', array( $this, 'register_taxonomy' ) );
        add_action( 'save_post', array( $this, 'auto_assign_zone' ), 20, 2 );
    }

    /**
     * Registrace taxonomie spot_zone
     */
    public function register_taxonomy() {
        error_log('[Spot_Zone DEBUG] Spouštím registraci spot_zone taxonomie');
        
        $labels = array(
            'name'              => _x( 'Mikrolokality', 'taxonomy general name', 'dobity-baterky' ),
            'singular_name'     => _x( 'Mikrolokalita', 'taxonomy singular name', 'dobity-baterky' ),
            'search_items'      => __( 'Hledat mikrolokality', 'dobity-baterky' ),
            'all_items'         => __( 'Všechny mikrolokality', 'dobity-baterky' ),
            'edit_item'         => __( 'Upravit mikrolokalitu', 'dobity-baterky' ),
            'update_item'       => __( 'Aktualizovat mikrolokalitu', 'dobity-baterky' ),
            'add_new_item'      => __( 'Přidat novou mikrolokalitu', 'dobity-baterky' ),
            'new_item_name'     => __( 'Název nové mikrolokality', 'dobity-baterky' ),
            'menu_name'         => __( 'Mikrolokality', 'dobity-baterky' ),
        );
        register_taxonomy( 'spot_zone', array( 'charging_location', 'rv_spot', 'poi' ), array(
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'show_in_quick_edit'=> true,
            'meta_box_cb'       => null, // použij výchozí WP metabox
            'rewrite'           => array( 'slug' => 'mikrolokality' ),
        ) );
        error_log('[Spot_Zone DEBUG] spot_zone taxonomie zaregistrována');
    }

    /**
     * Automatické přiřazení mikrolokality (geohash) k příspěvku
     */
    public function auto_assign_zone( $post_id, $post ) {
        $types = array( 'charging_location', 'rv_spot', 'poi' );
        if ( ! in_array( $post->post_type, $types, true ) ) {
            return;
        }
        // Získání souřadnic podle typu
        $lat = $lng = null;
        if ( $post->post_type === 'charging_location' ) {
            $lat = get_post_meta( $post_id, '_db_lat', true );
            $lng = get_post_meta( $post_id, '_db_lng', true );
        } elseif ( $post->post_type === 'rv_spot' ) {
            $lat = get_post_meta( $post_id, '_rv_lat', true );
            $lng = get_post_meta( $post_id, '_rv_lng', true );
        } elseif ( $post->post_type === 'poi' ) {
            $lat = get_post_meta( $post_id, '_poi_lat', true );
            $lng = get_post_meta( $post_id, '_poi_lng', true );
        }
        // Převod čárky na tečku
        $lat = str_replace(',', '.', $lat);
        $lng = str_replace(',', '.', $lng);
        // Kontrola správnosti souřadnic
        if (
            $lat === '' || $lng === '' ||
            !is_numeric($lat) || !is_numeric($lng) ||
            $lat < -90 || $lat > 90 ||
            $lng < -180 || $lng > 180
        ) {
            error_log('Geohash assign: nevalidní souřadnice ' . print_r([$lat, $lng], true));
            return;
        }
        $geohash = $this->geohash( (float)$lat, (float)$lng, 7 );
        if ( ! $geohash ) return;
        // Najdi nebo vytvoř term
        $term = get_term_by( 'slug', $geohash, 'spot_zone' );
        if ( ! $term ) {
            $term = wp_insert_term( $geohash, 'spot_zone', array( 'slug' => $geohash ) );
            if ( is_wp_error( $term ) ) return;
            $term_id = $term['term_id'];
        } else {
            $term_id = $term->term_id;
        }
        // Přiřaď term příspěvku
        wp_set_post_terms( $post_id, array( $term_id ), 'spot_zone', false );
        error_log('Geohash assign: ' . $geohash . ' pro post_id ' . $post_id);
    }

    /**
     * Geohash algoritmus (Base32, délka 7)
     * @link https://en.wikipedia.org/wiki/Geohash
     */
    private function geohash( float $lat, float $lng, int $precision = 7 ): string {
        $base32 = '0123456789bcdefghjkmnpqrstuvwxyz';
        $lat_interval = [ -90.0, 90.0 ];
        $lng_interval = [ -180.0, 180.0 ];
        $geohash = '';
        $is_even = true;
        $bit = 0;
        $ch = 0;
        $bits = [16, 8, 4, 2, 1];
        while ( strlen($geohash) < $precision ) {
            if ( $is_even ) {
                $mid = ( $lng_interval[0] + $lng_interval[1] ) / 2;
                if ( $lng > $mid ) {
                    $ch |= $bits[$bit];
                    $lng_interval[0] = $mid;
                } else {
                    $lng_interval[1] = $mid;
                }
            } else {
                $mid = ( $lat_interval[0] + $lat_interval[1] ) / 2;
                if ( $lat > $mid ) {
                    $ch |= $bits[$bit];
                    $lat_interval[0] = $mid;
                } else {
                    $lat_interval[1] = $mid;
                }
            }
            $is_even = !$is_even;
            if ( ++$bit == 5 ) {
                $geohash .= $base32[$ch];
                $bit = 0;
                $ch = 0;
            }
        }
        return $geohash;
    }
} 