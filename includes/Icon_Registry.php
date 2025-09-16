<?php
/**
 * Registry ikon pro mapové body
 * @package DobityBaterky
 */

namespace DB;

class Icon_Registry {
    private static $instance = null;
    private $base_url;
    private $base_path;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->base_url  = plugins_url( 'assets/icons/', DB_PLUGIN_FILE );
        $this->base_path = DB_PLUGIN_DIR . 'assets/icons/';
    }

    /**
     * Vrací pole s URL a barvou ikony pro daný příspěvek
     * @param \WP_Post $post
     * @return array{url: string, color: ?string}
     */
    public function get_icon( \WP_Post $post ) {
        $type = $post->post_type;
        if ( $type === 'charging_location' ) {
            // Pro nabíjecí místa se vždy používá jednotná ikona charger_type-198.svg
            // Barva se určuje podle AC/DC logiky na frontendu
            return [
                'slug' => 'charger_type-198.svg',
                'color' => null, // Barva se určuje podle AC/DC, ne podle term meta
            ];
        }
        if ( $type === 'rv_spot' ) {
            $rv_types = wp_get_post_terms( $post->ID, 'rv_type' );
            if ( !empty($rv_types) && !is_wp_error($rv_types) ) {
                $term = $rv_types[0];
                $icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );
                $color_hex = get_term_meta( $term->term_id, 'color_hex', true );
                $slug = $icon_slug ? $icon_slug . '.svg' : '';
                return [
                    'slug' => $slug,
                    'color' => $color_hex ?: null,
                ];
            } else {
                return [
                    'slug' => '',
                    'color' => null,
                ];
            }
        }
        if ( $type === 'poi' ) {
            $poi_terms = wp_get_post_terms( $post->ID, 'poi_type' );
            if ( !empty($poi_terms) && !is_wp_error($poi_terms) ) {
                $term = $poi_terms[0];
                $icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );
                $color_hex = get_term_meta( $term->term_id, 'color_hex', true );
                

                
                if ( !empty($icon_slug) ) {
                    $svg_path = $this->base_path . $icon_slug . '.svg';
                    if ( file_exists($svg_path) ) {
                        $svg_content = file_get_contents($svg_path);
                        // Úprava SVG: odstranění width/height a přidání width="100%" height="100%" style="display:block;"
                        $svg_content = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg_content);
                        $svg_content = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg_content);
                        $svg_content = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg_content, 1);
                        // Nastav fill a stroke na bílé
                        $svg_content = preg_replace('/fill="[^"]*"/', 'fill="#fff"', $svg_content);
                        $svg_content = preg_replace('/stroke="[^"]*"/', 'stroke="#fff"', $svg_content);
                        
                        return [
                            'slug' => $icon_slug,
                            'svg_content' => $svg_content,
                            'color' => '#FF6A4B', // Jednotná oranžová barva pro POI
                        ];
                    }
                }
                
                return [
                    'slug' => '',
                    'svg_content' => '',
                    'color' => '#FF6A4B', // Jednotná oranžová barva pro POI
                ];
            } else {
                return [
                    'slug' => '',
                    'svg_content' => '',
                    'color' => '#FF6A4B', // Jednotná oranžová barva pro POI
                ];
            }
        }
        return [
            'slug' => '',
            'color' => null,
        ];
    }
} 