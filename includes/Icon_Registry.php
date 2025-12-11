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
    
    // OPTIMALIZACE 2: Statická cache pro SVG obsah ikon (cache dle post_type/icon_slug/color)
    private static $svg_cache = [];

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
     * Validuje icon_slug - povoluje poi_type-* a rv_type-* slugy (soubory existují v assets/icons/)
     * @param string $icon_slug
     * @return string Validovaný icon_slug nebo prázdný string
     */
    private function validateIconSlug($icon_slug) {
        // Povolit poi_type-* a rv_type-* slugy - soubory existují v assets/icons/
        // Validovat pouze prázdný string nebo neplatné znaky
        if (empty($icon_slug) || !is_string($icon_slug)) {
            return '';
        }
        // Povolit alfanumerické znaky, pomlčky, podtržítka (včetně poi_type-* a rv_type-*)
        if (!preg_match('/^[a-zA-Z0-9_-]+$/', $icon_slug)) {
            return '';
        }
        return $icon_slug;
    }

    /**
     * Vrátí globální barvu pro POI piny (sanitizovanou)
     */
    private function get_global_poi_color(): string {
        $color = get_option('db_poi_color', '#FCE67D');
        $color = is_string($color) ? sanitize_hex_color($color) : '#FCE67D';
        if (empty($color)) {
            $color = '#FCE67D';
        }
        return $color;
    }

    /**
     * Fallback data pro POI ikonu (poi-default.svg)
     * @return array
     */
    private function get_poi_fallback_icon(): array {
        $color = $this->get_global_poi_color();
        $svg_content = $this->get_svg_content_cached('poi-default', 'poi', 'db_poi_icon_color');
        $icon_url = trailingslashit($this->base_url) . 'poi-default.svg';

        return [
            'slug' => 'poi-default',
            'svg_content' => $svg_content,
            'icon_url' => $icon_url,
            'color' => $color,
        ];
    }
    
    /**
     * Získá SVG obsah z cache nebo načte ze souboru
     * @param string $icon_slug
     * @param string $post_type
     * @param string $color_option_key Klíč pro option s barvou (např. 'db_charger_icon_color')
     * @return string SVG obsah
     */
    private function get_svg_content_cached($icon_slug, $post_type, $color_option_key = '') {
        if (empty($icon_slug)) {
            return '';
        }
        
        // Cache klíč: post_type + icon_slug + color
        $icon_color = get_option($color_option_key, '#049FE8');
        if (!is_string($icon_color) || !preg_match('/^#[0-9a-fA-F]{6}$/', $icon_color)) {
            $icon_color = '#049FE8';
        }
        $cache_key = $post_type . '_' . $icon_slug . '_' . $icon_color;
        
        // Zkontrolovat cache
        if (isset(self::$svg_cache[$cache_key])) {
            return self::$svg_cache[$cache_key];
        }
        
        // Načíst ze souboru
        $svg_path = $this->base_path . $icon_slug . '.svg';
        if (!file_exists($svg_path)) {
            self::$svg_cache[$cache_key] = '';
            return '';
        }
        
        $svg_content = file_get_contents($svg_path);
        // Úprava SVG: odstranění width/height a přidání width="100%" height="100%" style="display:block;"
        $svg_content = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg_content);
        $svg_content = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg_content);
        $svg_content = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg_content, 1);
        
        // Nastavit barvu podle option (icon_color je již validován regexem, ale použijeme escape pro bezpečnost)
        $icon_color_escaped = htmlspecialchars($icon_color, ENT_QUOTES, 'UTF-8');
        $svg_content = preg_replace('/fill="[^"]*"/', 'fill="' . $icon_color_escaped . '"', $svg_content);
        $svg_content = preg_replace('/stroke="[^"]*"/', 'stroke="' . $icon_color_escaped . '"', $svg_content);
        // Pokud SVG nemá fill/stroke atributy, přidáme je
        if (strpos($svg_content, 'fill=') === false) {
            $svg_content = preg_replace('/<svg([^>]*)>/', '<svg$1 fill="' . $icon_color_escaped . '">', $svg_content);
        }
        
        // Uložit do cache
        self::$svg_cache[$cache_key] = $svg_content;
        
        return $svg_content;
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
                    // Úprava SVG: odstranění width/height a přidání width="100%" height="100%" style="display:block;"
                    $svg_content = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg_content);
                    $svg_content = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg_content);
                    $svg_content = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg_content, 1);
                    // Nastavit barvu podle globálního nastavení pro všechny charging locations
                    $icon_fill = get_option('db_charger_icon_color', '#049FE8');
                    if (!is_string($icon_fill) || !preg_match('/^#[0-9a-fA-F]{6}$/', $icon_fill)) {
                        $icon_fill = '#049FE8';
                    }
                    $svg_content = preg_replace('/fill="[^"]*"/', 'fill="' . $icon_fill . '"', $svg_content);
                    $svg_content = preg_replace('/stroke="[^"]*"/', 'stroke="' . $icon_fill . '"', $svg_content);
                    // Pokud SVG nemá fill/stroke atributy, přidáme je
                    if (strpos($svg_content, 'fill=') === false) {
                        $svg_content = preg_replace('/<svg([^>]*)>/', '<svg$1 fill="' . $icon_fill . '">', $svg_content);
                    }
                }
            } else {
                // Fallback pro charging locations bez icon_slug - načíst defaultní charger ikonu (s cache)
                $svg_content = $this->get_svg_content_cached('charger ivon no fill', 'charging_location', 'db_charger_icon_color');
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
                $icon_slug = $this->validateIconSlug(get_term_meta( $term->term_id, 'icon_slug', true ));
            }

            if ( !empty($icon_slug) ) {
                // Preferuj uploads, pak assets
                $up = wp_upload_dir();
                $uploads_path = trailingslashit($up['basedir']) . 'dobity-baterky/icons/';
                $svg_path = $uploads_path . $icon_slug . '.svg';
                $is_upload = file_exists($svg_path);
                if ( ! $is_upload ) {
                    $svg_path = $this->base_path . $icon_slug . '.svg';
                }
                if ( file_exists($svg_path) ) {
                    // Použít cache pro SVG obsah (pokud není v uploads)
                    if (!$is_upload) {
                        $svg_content = $this->get_svg_content_cached($icon_slug, 'rv_spot', 'db_rv_icon_color');
                    } else {
                        // Pro uploads načíst přímo (ne cache, protože může být dynamické)
                        $svg_content = file_get_contents($svg_path);
                        $svg_content = preg_replace('/<svg([^>]*)width="[^"]*"/','<svg$1', $svg_content);
                        $svg_content = preg_replace('/<svg([^>]*)height="[^"]*"/','<svg$1', $svg_content);
                        $svg_content = preg_replace('/<svg /', '<svg width="100%" height="100%" style="display:block;" ', $svg_content, 1);
                        $icon_fill = get_option('db_rv_icon_color', '#049FE8');
                        if (!is_string($icon_fill) || !preg_match('/^#[0-9a-fA-F]{6}$/', $icon_fill)) { $icon_fill = '#049FE8'; }
                        $svg_content = preg_replace('/fill="[^"]*"/', 'fill="' . $icon_fill . '"', $svg_content);
                        $svg_content = preg_replace('/stroke="[^"]*"/', 'stroke="' . $icon_fill . '"', $svg_content);
                    }
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
            $fallback_icon = $this->get_poi_fallback_icon();
            $global_poi_color = $fallback_icon['color'];
            $poi_terms = wp_get_post_terms( $post->ID, 'poi_type' );
            if ( !empty($poi_terms) && !is_wp_error($poi_terms) ) {
                $term = $poi_terms[0];
                $term_id = $term->term_id;
                $icon_slug = $this->validateIconSlug(get_term_meta( $term_id, 'icon_slug', true ));

                // PRIORITA 1: Zkusit načíst z Icon Admin konfigurace (uploads/dobity-baterky/icons/poi_type-{term_id}.svg)
                // Toto je obecná cesta podle Icon Admin konfigurace, ne jen icon_slug z termu
                $up = wp_upload_dir();
                $uploads_path = trailingslashit($up['basedir']) . 'dobity-baterky/icons/';
                $uploads_url = trailingslashit($up['baseurl']) . 'dobity-baterky/icons/';
                $icon_admin_slug = 'poi_type-' . $term_id;
                $icon_admin_path = $uploads_path . $icon_admin_slug . '.svg';
                $icon_admin_url = $uploads_url . $icon_admin_slug . '.svg';
                $is_icon_admin_upload = file_exists($icon_admin_path);
                
                // PRIORITA 2: Pokud máme icon_slug z term meta, zkusit ho použít
                if ( !empty($icon_slug) ) {
                    // Preferuj uploads, pak assets
                    $svg_path = $is_icon_admin_upload ? $icon_admin_path : ($uploads_path . $icon_slug . '.svg');
                    $is_upload = file_exists($svg_path);
                    if ( ! $is_upload ) {
                        $svg_path = $this->base_path . $icon_slug . '.svg';
                    }
                    if ( file_exists($svg_path) ) {
                        // Použít cache pro SVG obsah (pokud není v uploads)
                        if (!$is_upload) {
                            $svg_content = $this->get_svg_content_cached($icon_slug, 'poi', 'db_poi_icon_color');
                        } else {
                            // Pro uploads načíst přímo (ne cache, protože může být dynamické)
                            $svg_content = file_get_contents($svg_path);
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
                        }
                        // Vrátit icon_url pokud je v uploads
                        $icon_url = $is_upload ? ($is_icon_admin_upload ? $icon_admin_url : ($uploads_url . $icon_slug . '.svg')) : null;
                        return [
                            'slug' => $icon_slug,
                            'svg_content' => $svg_content,
                            'icon_url' => $icon_url,
                            // POI barva se čerpá z centrálního nastavení
                            'color' => $global_poi_color,
                        ];
                    }
                }
                
                // PRIORITA 3: Pokud nemáme icon_slug, ale máme Icon Admin soubor, použít ho
                if ($is_icon_admin_upload) {
                    $svg_content = file_get_contents($icon_admin_path);
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
                    return [
                        'slug' => $icon_admin_slug,
                        'svg_content' => $svg_content,
                        'icon_url' => $icon_admin_url,
                        'color' => $global_poi_color,
                    ];
                }
                // Pokud není SVG dekorace, vrátit fallback ikonu
                return $fallback_icon;
            } else {
                // Fallback pro případy bez termu – použít default
                return $fallback_icon;
            }
        }
        return [
            'slug' => '',
            'color' => null,
        ];
    }
} 