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
            // Pro nabíječky načteme ikony z databáze stejně jako u POI
            $icon_slug = get_post_meta($post->ID, '_icon_slug', true);
            $icon_color = get_post_meta($post->ID, '_icon_color', true);
            
            // Pokud máme slug, načteme SVG obsah
            $svg_content = '';
            if (!empty($icon_slug)) {
                $svg_path = $this->base_path . $icon_slug . '.svg';
                if (file_exists($svg_path)) {
                    $svg_content = file_get_contents($svg_path);
                }
            }
            
            return [
                'slug' => $icon_slug,
                'color' => $icon_color,
                'svg_content' => $svg_content,
            ];
        }
        if ( $type === 'rv_spot' ) {
            // Centrální barva RV pinu a SVG ikony
            $global_rv_color = get_option('db_rv_color', '#FCE67D');
            $global_rv_color = is_string($global_rv_color) ? sanitize_hex_color($global_rv_color) : '#FCE67D';
            if (empty($global_rv_color)) { $global_rv_color = '#FCE67D'; }

            $rv_types = wp_get_post_terms( $post->ID, 'rv_type' );
            $icon_slug = '';
            if ( !empty($rv_types) && !is_wp_error($rv_types) ) {
                $term = $rv_types[0];
                $icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );
            }

            if ( !empty($icon_slug) ) {
                // Preferuj uploads, pak assets
                $up = wp_upload_dir();
                $uploads_path = trailingslashit($up['basedir']) . 'dobity-baterky/icons/';
                $svg_path = $uploads_path . $icon_slug . '.svg';
                if ( ! file_exists($svg_path) ) {
                    $svg_path = $this->base_path . $icon_slug . '.svg';
                }
                if ( file_exists($svg_path) ) {
                    $svg_content = file_get_contents($svg_path);
                    $svg_content = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg_content);
                    $svg_content = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg_content);
                    $svg_content = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg_content, 1);
                    $icon_fill = get_option('db_rv_icon_color', '#049FE8');
                    if (!is_string($icon_fill) || !preg_match('/^#[0-9a-fA-F]{6}$/', $icon_fill)) { $icon_fill = '#049FE8'; }
                    $svg_content = preg_replace('/fill="[^"]*"/', 'fill="' . $icon_fill . '"', $svg_content);
                    $svg_content = preg_replace('/stroke="[^"]*"/', 'stroke="' . $icon_fill . '"', $svg_content);
                    return [
                        'slug' => $icon_slug,
                        'svg_content' => $svg_content,
                        'color' => $global_rv_color,
                    ];
                }
            }

            return [
                'slug' => $icon_slug ?: '',
                'svg_content' => '',
                'color' => $global_rv_color,
            ];
        }
        if ( $type === 'poi' ) {
            $poi_terms = wp_get_post_terms( $post->ID, 'poi_type' );
            if ( !empty($poi_terms) && !is_wp_error($poi_terms) ) {
                $term = $poi_terms[0];
                $icon_slug = get_term_meta( $term->term_id, 'icon_slug', true );
                $color_hex = get_term_meta( $term->term_id, 'color_hex', true );
                

                
                if ( !empty($icon_slug) ) {
                // Preferuj uploads, pak assets
                $up = wp_upload_dir();
                $uploads_path = trailingslashit($up['basedir']) . 'dobity-baterky/icons/';
                $svg_path = $uploads_path . $icon_slug . '.svg';
                if ( ! file_exists($svg_path) ) {
                    $svg_path = $this->base_path . $icon_slug . '.svg';
                }
                if ( file_exists($svg_path) ) {
                        $svg_content = file_get_contents($svg_path);
                        // Úprava SVG: odstranění width/height a přidání width="100%" height="100%" style="display:block;"
                        $svg_content = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg_content);
                        $svg_content = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg_content);
                        $svg_content = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg_content, 1);
                        // Nastav fill a stroke podle globální barvy SVG ikony
                        $icon_fill = get_option('db_poi_icon_color', '#049FE8');
                        if (!is_string($icon_fill) || !preg_match('/^#[0-9a-fA-F]{6}$/', $icon_fill)) {
                            $icon_fill = '#049FE8';
                        }
                        $svg_content = preg_replace('/fill="[^"]*"/', 'fill="' . $icon_fill . '"', $svg_content);
                        $svg_content = preg_replace('/stroke="[^"]*"/', 'stroke="' . $icon_fill . '"', $svg_content);
                        // Centrální barva POI pinů (option), fallback na #FCE67D dle brandbooku
                        $global_poi_color = get_option('db_poi_color', '#FCE67D');
                        $global_poi_color = is_string($global_poi_color) ? sanitize_hex_color($global_poi_color) : '#FCE67D';
                        if (empty($global_poi_color)) {
                            $global_poi_color = '#FCE67D';
                        }
                        return [
                            'slug' => $icon_slug,
                            'svg_content' => $svg_content,
                            // POI barva se čerpá z centrálního nastavení
                            'color' => $global_poi_color,
                        ];
                    }
                }
                
                // Pokud není SVG dekorace, vrátit defaultní barvu z centrálního nastavení
                $global_poi_color = get_option('db_poi_color', '#FCE67D');
                $global_poi_color = is_string($global_poi_color) ? sanitize_hex_color($global_poi_color) : '#FCE67D';
                if (empty($global_poi_color)) {
                    $global_poi_color = '#FCE67D';
                }
                return [
                    'slug' => '',
                    'svg_content' => '',
                    'color' => $global_poi_color,
                ];
            } else {
                // Fallback pro případy bez termu – stále použít centrální barvu
                $global_poi_color = get_option('db_poi_color', '#FCE67D');
                $global_poi_color = is_string($global_poi_color) ? sanitize_hex_color($global_poi_color) : '#FCE67D';
                if (empty($global_poi_color)) {
                    $global_poi_color = '#FCE67D';
                }
                return [
                    'slug' => '',
                    'svg_content' => '',
                    'color' => $global_poi_color,
                ];
            }
        }
        return [
            'slug' => '',
            'color' => null,
        ];
    }
} 