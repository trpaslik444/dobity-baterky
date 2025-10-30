<?php
/**
 * Gutenberg blok pro zobrazení jednotlivých CPT záznamů
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Třída pro registraci a správu Gutenberg bloku pro zobrazení CPT
 */
class CPT_Display_Block {

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
        add_action( 'init', array( $this, 'register_block' ) );
        add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );
    }

    /**
     * Registruje Gutenberg blok
     */
    public function register_block() {
        if ( ! function_exists( 'register_block_type' ) ) {
            return;
        }

        register_block_type( 'dobity-baterky/cpt-display', array(
            'editor_script' => 'dobity-baterky-cpt-block-editor',
            'editor_style'  => 'dobity-baterky-cpt-block-editor',
            'style'         => 'dobity-baterky-cpt-block',
            'render_callback' => array( $this, 'render_block' ),
            'attributes' => array(
                'postType' => array(
                    'type' => 'string',
                    'default' => 'charging_location'
                ),
                'postId' => array(
                    'type' => 'number',
                    'default' => 0
                ),
                'showMap' => array(
                    'type' => 'boolean',
                    'default' => true
                ),
                'showConnectors' => array(
                    'type' => 'boolean',
                    'default' => true
                ),
                'showServices' => array(
                    'type' => 'boolean',
                    'default' => true
                ),
                'showOpeningHours' => array(
                    'type' => 'boolean',
                    'default' => true
                )
            )
        ) );
    }

    /**
     * Načte potřebné assety
     */
    public function enqueue_assets() {
        if ( has_block( 'dobity-baterky/cpt-display' ) ) {
            wp_enqueue_style(
                'dobity-baterky-cpt-block',
                DB_PLUGIN_URL . 'assets/single-templates.css',
                array(),
                DB_PLUGIN_VERSION
            );

            wp_enqueue_script(
                'dobity-baterky-cpt-block',
                DB_PLUGIN_URL . 'assets/single-templates.js',
                array(),
                DB_PLUGIN_VERSION,
                true
            );
        }
    }

    /**
     * Renderuje blok na frontendu
     */
    public function render_block( $attributes ) {
        $post_type = $attributes['postType'] ?? 'charging_location';
        $post_id = $attributes['postId'] ?? 0;

        // Pokud není specifikován post ID, zkus najít aktuální post
        if ( ! $post_id ) {
            global $post;
            if ( $post && $post->post_type === $post_type ) {
                $post_id = $post->ID;
            }
        }

        if ( ! $post_id ) {
            return '<p>Nebyl nalezen žádný záznam typu ' . esc_html( $post_type ) . '</p>';
        }

        $post_obj = get_post( $post_id );
        if ( ! $post_obj || $post_obj->post_type !== $post_type ) {
            return '<p>Záznam nebyl nalezen</p>';
        }

        // Renderuj podle typu postu
        switch ( $post_type ) {
            case 'charging_location':
                return $this->render_charging_location( $post_obj, $attributes );
            case 'rv_spot':
                return $this->render_rv_spot( $post_obj, $attributes );
            case 'poi':
                return $this->render_poi( $post_obj, $attributes );
            default:
                return '<p>Nepodporovaný typ záznamu</p>';
        }
    }

    /**
     * Renderuje charging_location
     */
    private function render_charging_location( $post, $attributes ) {
        // Načíst překlady
        $translations = array();
        if ( class_exists( '\\DB\\Translation_Manager' ) ) {
            $translation_manager = \DB\Translation_Manager::get_instance();
            $translations = $translation_manager->get_frontend_translations();
        }
        
        $lat = get_post_meta( $post->ID, '_db_lat', true );
        $lng = get_post_meta( $post->ID, '_db_lng', true );
        $address = get_post_meta( $post->ID, '_db_address', true );
        $operator = get_post_meta( $post->ID, '_operator', true );
        $connectors = get_post_meta( $post->ID, '_connectors', true );

        $charger_types = wp_get_post_terms( $post->ID, 'charger_type' );
        $providers = wp_get_post_terms( $post->ID, 'provider' );

        ob_start();
        ?>
        <div class="db-single db-single-charging">
            <!-- Hero sekce -->
            <div class="db-hero">
                <div class="db-hero-content">
                    <div class="db-hero-header">
                        <h1 class="db-title"><?php echo esc_html( $post->post_title ); ?></h1>
                        <?php if ( ! empty( $charger_types ) ) : ?>
                            <div class="db-badges">
                                <?php foreach ( $charger_types as $type ) : ?>
                                    <span class="db-badge db-badge-charger"><?php echo esc_html( $type->name ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="db-hero-meta">
                        <?php if ( $address ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-location"></span>
                                <span><?php echo esc_html( $address ); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $lat && $lng ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-coordinates"></span>
                                <span><?php echo esc_html( $lat ); ?>, <?php echo esc_html( $lng ); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $operator ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-operator"></span>
                                <span><?php echo esc_html( $operator ); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="db-hero-image">
                    <?php if ( has_post_thumbnail( $post->ID ) ) : ?>
                        <?php echo get_the_post_thumbnail( $post->ID, 'medium', array( 'class' => 'db-featured-image' ) ); ?>
                    <?php else : ?>
                        <div class="db-placeholder-image">
                            <span class="db-icon db-icon-charger"></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Navigace -->
            <div class="db-navigation">
                <div class="db-nav-dropdown">
                    <button class="db-nav-button">
                        <span class="db-icon db-icon-navigation"></span>
                        Navigace
                    </button>
                    <div class="db-nav-menu">
                        <?php if ( $lat && $lng ) : ?>
                            <a href="https://maps.google.com/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-google"></span>
                                Google Maps
                            </a>
                            <a href="https://maps.apple.com/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-apple"></span>
                                Apple Maps
                            </a>
                            <a href="https://mapy.cz/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-mapy"></span>
                                Mapy.cz
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Konektory -->
            <?php if ( $attributes['showConnectors'] && $connectors ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-connector"></span>
                        Konektory
                    </h2>
                    <div class="db-connectors-grid">
                        <?php 
                        $connector_data = json_decode( $connectors, true );
                        if ( is_array( $connector_data ) ) :
                            foreach ( $connector_data as $connector ) :
                                $power = $connector['power'] ?? '';
                                $status = $connector['status'] ?? 'available';
                                $type = $connector['type'] ?? '';
                        ?>
                            <div class="db-connector-card">
                                <div class="db-connector-header">
                                    <span class="db-connector-type"><?php echo esc_html( $type ); ?></span>
                                    <span class="db-connector-status db-status-<?php echo esc_attr( $status ); ?>">
                                        <?php echo esc_html( $status === 'available' ? 'Dostupné' : 'Obsazené' ); ?>
                                    </span>
                                </div>
                                <?php if ( $power ) : ?>
                                    <div class="db-connector-power">
                                        <span class="db-icon db-icon-power"></span>
                                        <?php echo esc_html( $power ); ?> kW
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endforeach;
                        endif;
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Popis -->
            <?php if ( $post->post_content ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-description"></span>
                        <?php echo esc_html( isset( $translations['translations']['common']['description'] ) ? $translations['translations']['common']['description'] : 'Popis' ); ?>
                    </h2>
                    <div class="db-content">
                        <?php echo wp_kses_post( $post->post_content ); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Mapa -->
            <?php if ( $attributes['showMap'] && $lat && $lng ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-map"></span>
                        <?php echo esc_html( isset( $translations['translations']['common']['map'] ) ? $translations['translations']['common']['map'] : 'Mapa' ); ?>
                    </h2>
                    <div class="db-map-container" 
                         data-lat="<?php echo esc_attr( $lat ); ?>" 
                         data-lng="<?php echo esc_attr( $lng ); ?>" 
                         data-title="<?php echo esc_attr( $post->post_title ); ?>">
                        <div class="db-map-loading"><?php echo esc_html( isset( $translations['translations']['map']['map_loading'] ) ? $translations['translations']['map']['map_loading'] : 'Načítání mapy...' ); ?></div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Licence -->
            <div class="db-section">
                <h2 class="db-section-title">
                    <span class="db-icon db-icon-license"></span>
                    <?php echo esc_html( isset( $translations['translations']['common']['license'] ) ? $translations['translations']['common']['license'] : 'Licence' ); ?>
                </h2>
                <p class="db-license">
                    <?php echo esc_html( isset( $translations['translations']['templates']['license_text'] ) ? $translations['translations']['templates']['license_text'] : 'Data jsou poskytována v rámci projektu Dobitý Baterky. Pro komerční použití kontaktujte autora.' ); ?>
                </p>
            </div>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderuje rv_spot
     */
    private function render_rv_spot( $post, $attributes ) {
        // Načíst překlady
        $translations = array();
        if ( class_exists( '\\DB\\Translation_Manager' ) ) {
            $translation_manager = \DB\Translation_Manager::get_instance();
            $translations = $translation_manager->get_frontend_translations();
        }
        
        $lat = get_post_meta( $post->ID, '_rv_lat', true );
        $lng = get_post_meta( $post->ID, '_rv_lng', true );
        $address = get_post_meta( $post->ID, '_rv_address', true );
        $services = get_post_meta( $post->ID, '_rv_services', true );
        $price = get_post_meta( $post->ID, '_rv_price', true );

        $rv_types = wp_get_post_terms( $post->ID, 'rv_type' );

        ob_start();
        ?>
        <div class="db-single db-single-rv">
            <!-- Hero sekce -->
            <div class="db-hero">
                <div class="db-hero-content">
                    <div class="db-hero-header">
                        <h1 class="db-title"><?php echo esc_html( $post->post_title ); ?></h1>
                        <?php if ( ! empty( $rv_types ) ) : ?>
                            <div class="db-badges">
                                <?php foreach ( $rv_types as $type ) : ?>
                                    <span class="db-badge db-badge-rv"><?php echo esc_html( $type->name ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="db-hero-meta">
                        <?php if ( $address ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-location"></span>
                                <span><?php echo esc_html( $address ); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $lat && $lng ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-coordinates"></span>
                                <span><?php echo esc_html( $lat ); ?>, <?php echo esc_html( $lng ); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $price ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-price"></span>
                                <span><?php echo esc_html( $price ); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="db-hero-image">
                    <?php if ( has_post_thumbnail( $post->ID ) ) : ?>
                        <?php echo get_the_post_thumbnail( $post->ID, 'medium', array( 'class' => 'db-featured-image' ) ); ?>
                    <?php else : ?>
                        <div class="db-placeholder-image">
                            <span class="db-icon db-icon-rv"></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Navigace -->
            <div class="db-navigation">
                <div class="db-nav-dropdown">
                    <button class="db-nav-button">
                        <span class="db-icon db-icon-navigation"></span>
                        Navigace
                    </button>
                    <div class="db-nav-menu">
                        <?php if ( $lat && $lng ) : ?>
                            <a href="https://maps.google.com/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-google"></span>
                                Google Maps
                            </a>
                            <a href="https://maps.apple.com/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-apple"></span>
                                Apple Maps
                            </a>
                            <a href="https://mapy.cz/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-mapy"></span>
                                Mapy.cz
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Služby -->
            <?php if ( $attributes['showServices'] && $services ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-services"></span>
                        Služby
                    </h2>
                    <div class="db-services-grid">
                        <?php 
                        $services_data = json_decode( $services, true );
                        if ( is_array( $services_data ) ) :
                            foreach ( $services_data as $service ) :
                                $name = $service['name'] ?? '';
                                $description = $service['description'] ?? '';
                                $available = $service['available'] ?? true;
                        ?>
                            <div class="db-service-card">
                                <div class="db-service-header">
                                    <span class="db-service-name"><?php echo esc_html( $name ); ?></span>
                                    <span class="db-service-status db-status-<?php echo $available ? 'available' : 'unavailable'; ?>">
                                        <?php echo $available ? 'Dostupné' : 'Nedostupné'; ?>
                                    </span>
                                </div>
                                <?php if ( $description ) : ?>
                                    <div class="db-service-description">
                                        <?php echo esc_html( $description ); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endforeach;
                        endif;
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Popis -->
            <?php if ( $post->post_content ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-description"></span>
                        <?php echo esc_html( isset( $translations['translations']['common']['description'] ) ? $translations['translations']['common']['description'] : 'Popis' ); ?>
                    </h2>
                    <div class="db-content">
                        <?php echo wp_kses_post( $post->post_content ); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Mapa -->
            <?php if ( $attributes['showMap'] && $lat && $lng ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-map"></span>
                        <?php echo esc_html( isset( $translations['translations']['common']['map'] ) ? $translations['translations']['common']['map'] : 'Mapa' ); ?>
                    </h2>
                    <div class="db-map-container" 
                         data-lat="<?php echo esc_attr( $lat ); ?>" 
                         data-lng="<?php echo esc_attr( $lng ); ?>" 
                         data-title="<?php echo esc_attr( $post->post_title ); ?>">
                        <div class="db-map-loading"><?php echo esc_html( isset( $translations['translations']['map']['map_loading'] ) ? $translations['translations']['map']['map_loading'] : 'Načítání mapy...' ); ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }

    /**
     * Renderuje poi
     */
    private function render_poi( $post, $attributes ) {
        // Načíst překlady
        $translations = array();
        if ( class_exists( '\\DB\\Translation_Manager' ) ) {
            $translation_manager = \DB\Translation_Manager::get_instance();
            $translations = $translation_manager->get_frontend_translations();
        }
        $lat = get_post_meta( $post->ID, '_poi_lat', true );
        $lng = get_post_meta( $post->ID, '_poi_lng', true );
        $address = get_post_meta( $post->ID, '_poi_address', true );
        $phone = get_post_meta( $post->ID, '_poi_phone', true );
        $website = get_post_meta( $post->ID, '_poi_website', true );
        $rating = get_post_meta( $post->ID, '_poi_rating', true );
        $price_level = get_post_meta( $post->ID, '_poi_price_level', true );
        $opening_hours = get_post_meta( $post->ID, '_poi_opening_hours', true );

        $poi_types = wp_get_post_terms( $post->ID, 'poi_type' );

        ob_start();
        ?>
        <div class="db-single db-single-poi">
            <!-- Hero sekce -->
            <div class="db-hero">
                <div class="db-hero-content">
                    <div class="db-hero-header">
                        <h1 class="db-title"><?php echo esc_html( $post->post_title ); ?></h1>
                        <?php if ( ! empty( $poi_types ) ) : ?>
                            <div class="db-badges">
                                <?php foreach ( $poi_types as $type ) : ?>
                                    <span class="db-badge db-badge-poi"><?php echo esc_html( $type->name ); ?></span>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="db-hero-meta">
                        <?php if ( $address ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-location"></span>
                                <span><?php echo esc_html( $address ); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $lat && $lng ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-coordinates"></span>
                                <span><?php echo esc_html( $lat ); ?>, <?php echo esc_html( $lng ); ?></span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $rating ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-rating"></span>
                                <span><?php echo esc_html( $rating ); ?>/5</span>
                            </div>
                        <?php endif; ?>
                        
                        <?php if ( $price_level ) : ?>
                            <div class="db-meta-item">
                                <span class="db-icon db-icon-price"></span>
                                <span><?php echo str_repeat( '€', $price_level ); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="db-hero-image">
                    <?php if ( has_post_thumbnail( $post->ID ) ) : ?>
                        <?php echo get_the_post_thumbnail( $post->ID, 'medium', array( 'class' => 'db-featured-image' ) ); ?>
                    <?php else : ?>
                        <div class="db-placeholder-image">
                            <span class="db-icon db-icon-poi"></span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Navigace -->
            <div class="db-navigation">
                <div class="db-nav-dropdown">
                    <button class="db-nav-button">
                        <span class="db-icon db-icon-navigation"></span>
                        Navigace
                    </button>
                    <div class="db-nav-menu">
                        <?php if ( $lat && $lng ) : ?>
                            <a href="https://maps.google.com/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-google"></span>
                                Google Maps
                            </a>
                            <a href="https://maps.apple.com/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-apple"></span>
                                Apple Maps
                            </a>
                            <a href="https://mapy.cz/?q=<?php echo esc_attr( $lat ); ?>,<?php echo esc_attr( $lng ); ?>" target="_blank" class="db-nav-item">
                                <span class="db-icon db-icon-mapy"></span>
                                Mapy.cz
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Kontakt -->
            <?php if ( $phone || $website ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-contact"></span>
                        Kontakt
                    </h2>
                    <div class="db-contact-buttons">
                        <?php if ( $phone ) : ?>
                            <a href="tel:<?php echo esc_attr( $phone ); ?>" class="db-button db-button-call">
                                <span class="db-icon db-icon-phone"></span>
                                Zavolat
                            </a>
                        <?php endif; ?>
                        
                        <?php if ( $website ) : ?>
                            <a href="<?php echo esc_url( $website ); ?>" target="_blank" class="db-button db-button-web">
                                <span class="db-icon db-icon-website"></span>
                                Web
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Otevírací doba -->
            <?php if ( $attributes['showOpeningHours'] && $opening_hours ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-time"></span>
                        Otevírací doba
                    </h2>
                    <div class="db-opening-hours">
                        <?php 
                        $hours_data = json_decode( $opening_hours, true );
                        if ( is_array( $hours_data ) ) :
                            foreach ( $hours_data as $day => $hours ) :
                                $open = $hours['open'] ?? '';
                                $close = $hours['close'] ?? '';
                                $closed = $hours['closed'] ?? false;
                        ?>
                            <div class="db-day-row">
                                <span class="db-day-name"><?php echo esc_html( $day ); ?></span>
                                <?php if ( $closed ) : ?>
                                    <span class="db-day-status db-status-closed">Zavřeno</span>
                                <?php else : ?>
                                    <span class="db-day-hours"><?php echo esc_html( $open ); ?> - <?php echo esc_html( $close ); ?></span>
                                <?php endif; ?>
                            </div>
                        <?php 
                            endforeach;
                        endif;
                        ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Popis -->
            <?php if ( $post->post_content ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-description"></span>
                        <?php echo esc_html( isset( $translations['translations']['common']['description'] ) ? $translations['translations']['common']['description'] : 'Popis' ); ?>
                    </h2>
                    <div class="db-content">
                        <?php echo wp_kses_post( $post->post_content ); ?>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Mapa -->
            <?php if ( $attributes['showMap'] && $lat && $lng ) : ?>
                <div class="db-section">
                    <h2 class="db-section-title">
                        <span class="db-icon db-icon-map"></span>
                        <?php echo esc_html( isset( $translations['translations']['common']['map'] ) ? $translations['translations']['common']['map'] : 'Mapa' ); ?>
                    </h2>
                    <div class="db-map-container" 
                         data-lat="<?php echo esc_attr( $lat ); ?>" 
                         data-lng="<?php echo esc_attr( $lng ); ?>" 
                         data-title="<?php echo esc_attr( $post->post_title ); ?>">
                        <div class="db-map-loading"><?php echo esc_html( isset( $translations['translations']['map']['map_loading'] ) ? $translations['translations']['map']['map_loading'] : 'Načítání mapy...' ); ?></div>
                    </div>
                </div>
            <?php endif; ?>
        </div>
        <?php
        return ob_get_clean();
    }
}
