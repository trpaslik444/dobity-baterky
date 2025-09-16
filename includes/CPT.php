<?php
/**
 * Třída pro Custom Post Type charging_location
 * 
 * @package DobityBaterky
 */

namespace DB;

/**
 * Custom Post Type pro nabíjecí lokality
 */
class CPT {

    /**
     * Instance třídy
     */
    private static $instance = null;

    /**
     * Získání instance třídy
     */
    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    /**
     * Konstruktor
     */
    public function __construct() {
        error_log('[CPT DEBUG] Konstruktor CPT spuštěn');
        add_action( 'init', array( $this, 'register_post_type' ) );
        // Přidání políčka pro výkon kW k typům nabíječek
        add_action( 'charger_type_add_form_fields', array( $this, 'charger_type_add_field' ) );
        add_action( 'charger_type_edit_form_fields', array( $this, 'charger_type_edit_field' ), 10, 2 );
        add_action( 'created_charger_type', array( $this, 'charger_type_save_field' ) );
        add_action( 'edited_charger_type', array( $this, 'charger_type_save_field' ) );
        
        // Přidání políček pro poskytovatele
        add_action( 'provider_add_form_fields', array( $this, 'provider_add_fields' ) );
        add_action( 'provider_edit_form_fields', array( $this, 'provider_edit_fields' ), 10, 2 );
        add_action( 'created_provider', array( $this, 'provider_save_fields' ) );
        add_action( 'edited_provider', array( $this, 'provider_save_fields' ) );
    }

    /**
     * Registrace Custom Post Type
     */
    public function register_post_type() {
        error_log('[CPT DEBUG] Registruji charging_location post type');
        
        $labels = array(
            'name'                  => _x( 'Nabíjecí lokality', 'Post type general name', 'dobity-baterky' ),
            'singular_name'         => _x( 'Nabíjecí lokalita', 'Post type singular name', 'dobity-baterky' ),
            'menu_name'             => _x( 'Nabíjecí lokality', 'Admin Menu text', 'dobity-baterky' ),
            'name_admin_bar'        => _x( 'Nabíjecí lokalita', 'Add New on Toolbar', 'dobity-baterky' ),
            'add_new'               => __( 'Přidat novou', 'dobity-baterky' ),
            'add_new_item'          => __( 'Přidat novou lokalitu', 'dobity-baterky' ),
            'new_item'              => __( 'Nová lokalita', 'dobity-baterky' ),
            'edit_item'             => __( 'Upravit lokalitu', 'dobity-baterky' ),
            'view_item'             => __( 'Zobrazit lokalitu', 'dobity-baterky' ),
            'all_items'             => __( 'Všechny lokality', 'dobity-baterky' ),
            'search_items'          => __( 'Hledat lokality', 'dobity-baterky' ),
            'parent_item_colon'     => __( 'Nadřazené lokality:', 'dobity-baterky' ),
            'not_found'             => __( 'Žádné lokality nenalezeny.', 'dobity-baterky' ),
            'not_found_in_trash'    => __( 'Žádné lokality v koši nenalezeny.', 'dobity-baterky' ),
            'featured_image'        => _x( 'Obrázek lokality', 'Overrides the "Featured Image" phrase', 'dobity-baterky' ),
            'set_featured_image'    => _x( 'Nastavit obrázek lokality', 'Overrides the "Set featured image" phrase', 'dobity-baterky' ),
            'remove_featured_image' => _x( 'Odebrat obrázek lokality', 'Overrides the "Remove featured image" phrase', 'dobity-baterky' ),
            'use_featured_image'    => _x( 'Použít jako obrázek lokality', 'Overrides the "Use as featured image" phrase', 'dobity-baterky' ),
            'archives'              => _x( 'Archiv lokality', 'The post type archive label used in nav menus', 'dobity-baterky' ),
            'insert_into_item'      => _x( 'Vložit do lokality', 'Overrides the "Insert into post" phrase', 'dobity-baterky' ),
            'uploaded_to_this_item' => _x( 'Nahráno do této lokality', 'Overrides the "Uploaded to this post" phrase', 'dobity-baterky' ),
            'filter_items_list'     => _x( 'Filtrovat seznam lokality', 'Screen reader text for the filter links', 'dobity-baterky' ),
            'items_list_navigation' => _x( 'Navigace seznamu lokality', 'Screen reader text for the pagination', 'dobity-baterky' ),
            'items_list'            => _x( 'Seznam lokality', 'Screen reader text for the items list', 'dobity-baterky' ),
        );

        $args = array(
            'labels'             => $labels,
            'public'             => true,
            'publicly_queryable' => true,
            'show_ui'            => true,
            'show_in_menu'       => true,
            'query_var'          => true,
            'rewrite'            => array( 'slug' => 'lokality' ),
            'capability_type'    => 'post',
            'has_archive'        => true,
            'hierarchical'       => false,
            'menu_position'      => 25,
            'menu_icon'          => 'dashicons-location',
            'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields' ),
            'show_in_rest'       => true,
        );

        register_post_type( 'charging_location', $args );
        error_log('[CPT DEBUG] charging_location post type zaregistrován');
        
        // Registrace taxonomie typů nabíječek
        $labels = array(
            'name'              => _x( 'Typy nabíječek', 'taxonomy general name', 'dobity-baterky' ),
            'singular_name'     => _x( 'Typ nabíječky', 'taxonomy singular name', 'dobity-baterky' ),
            'search_items'      => __( 'Hledat typy nabíječek', 'dobity-baterky' ),
            'all_items'         => __( 'Všechny typy nabíječek', 'dobity-baterky' ),
            'edit_item'         => __( 'Upravit typ nabíječky', 'dobity-baterky' ),
            'update_item'       => __( 'Aktualizovat typ nabíječky', 'dobity-baterky' ),
            'add_new_item'      => __( 'Přidat nový typ nabíječky', 'dobity-baterky' ),
            'new_item_name'     => __( 'Název nového typu nabíječky', 'dobity-baterky' ),
            'menu_name'         => __( 'Typy nabíječek', 'dobity-baterky' ),
        );
        register_taxonomy( 'charger_type', array( 'charging_location' ), array(
            'hierarchical'      => false,
            'labels'            => $labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'typ-nabijecky' ),
        ) );
        
        // Registrace taxonomie poskytovatelů
        $provider_labels = array(
            'name'              => _x( 'Poskytovatelé', 'taxonomy general name', 'dobity-baterky' ),
            'singular_name'     => _x( 'Poskytovatel', 'taxonomy singular name', 'dobity-baterky' ),
            'search_items'      => __( 'Hledat poskytovatele', 'dobity-baterky' ),
            'all_items'         => __( 'Všichni poskytovatelé', 'dobity-baterky' ),
            'edit_item'         => __( 'Upravit poskytovatele', 'dobity-baterky' ),
            'update_item'       => __( 'Aktualizovat poskytovatele', 'dobity-baterky' ),
            'add_new_item'      => __( 'Přidat nového poskytovatele', 'dobity-baterky' ),
            'new_item_name'     => __( 'Název nového poskytovatele', 'dobity-baterky' ),
            'menu_name'         => __( 'Poskytovatelé', 'dobity-baterky' ),
        );
        register_taxonomy( 'provider', array( 'charging_location' ), array(
            'hierarchical'      => false,
            'labels'            => $provider_labels,
            'show_ui'           => true,
            'show_admin_column' => true,
            'show_in_rest'      => true,
            'rewrite'           => array( 'slug' => 'poskytovatel' ),
        ) );
    }

    // Přidání polí pro OCM-style data při vytváření typu
    public function charger_type_add_field() {
        ?>
        <div class="form-field">
            <label for="charger_current_type">Typ proudu</label>
            <select name="charger_current_type" id="charger_current_type">
                <option value="">-- Vyberte typ proudu --</option>
                <option value="AC">AC (Střídavý proud)</option>
                <option value="DC">DC (Stejnosměrný proud)</option>
            </select>
            <p class="description">Typ proudu, který tento konektor podporuje.</p>
        </div>
        
        <div class="form-field">
            <label for="charger_icon">SVG ikona konektoru</label>
            <input type="text" name="charger_icon" id="charger_icon" class="regular-text" />
            <button type="button" class="button" id="upload_icon_button">Vybrat SVG ikonu</button>
            <p class="description">Název SVG souboru z assets/icons/ nebo URL k ikoně.</p>
        </div>
        
        <script>
        jQuery(document).ready(function($) {
            $('#upload_icon_button').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'Vybrat SVG ikonu konektoru',
                    multiple: false,
                    library: {
                        type: 'image'
                    }
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#charger_icon').val(image_url);
                });
            });
        });
        </script>
        <?php
    }

    // Pole pro OCM-style data při editaci typu
    public function charger_type_edit_field( $term, $taxonomy ) {
        $current_type = get_term_meta( $term->term_id, 'charger_current_type', true );
        $icon = get_term_meta( $term->term_id, 'charger_icon', true );
        ?>
        
        <tr class="form-field">
            <th scope="row"><label for="charger_current_type">Typ proudu</label></th>
            <td>
                <select name="charger_current_type" id="charger_current_type">
                    <option value="">-- Vyberte typ proudu --</option>
                    <option value="AC" <?php selected($current_type, 'AC'); ?>>AC (Střídavý proud)</option>
                    <option value="DC" <?php selected($current_type, 'DC'); ?>>DC (Stejnosměrný proud)</option>
                </select>
                <p class="description">Typ proudu, který tento konektor podporuje.</p>
            </td>
        </tr>
        
        <tr class="form-field">
            <th scope="row"><label for="charger_icon">SVG ikona konektoru</label></th>
            <td>
                <input type="text" name="charger_icon" id="charger_icon" value="<?php echo esc_attr($icon); ?>" class="regular-text" />
                <button type="button" class="button" id="upload_icon_button">Vybrat SVG ikonu</button>
                <?php if ($icon) : ?>
                    <br><br>
                    <img src="<?php echo esc_url($icon); ?>" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd;" />
                <?php endif; ?>
                <p class="description">Název SVG souboru z assets/icons/ nebo URL k ikoně.</p>
            </td>
        </tr>
        
        <script>
        jQuery(document).ready(function($) {
            $('#upload_icon_button').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'Vybrat SVG ikonu konektoru',
                    multiple: false,
                    library: {
                        type: 'image'
                    }
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#charger_icon').val(image_url);
                });
            });
        });
        </script>
        <?php
    }

    // Uložení OCM-style dat
    public function charger_type_save_field( $term_id ) {
        if ( isset( $_POST['charger_current_type'] ) ) {
            update_term_meta( $term_id, 'charger_current_type', sanitize_text_field($_POST['charger_current_type']) );
        }
        if ( isset( $_POST['charger_icon'] ) ) {
            update_term_meta( $term_id, 'charger_icon', esc_url_raw($_POST['charger_icon']) );
        }
    }
    
    // Přidání polí pro poskytovatele při vytváření
    public function provider_add_fields() {
        ?>
        <div class="form-field">
            <label for="provider_friendly_name">Friendly název</label>
            <input type="text" name="provider_friendly_name" id="provider_friendly_name" />
            <p class="description">Uživatelsky přívětivý název poskytovatele (např. "ČEZ" místo "ČEZ, a.s.")</p>
        </div>
        <div class="form-field">
            <label for="provider_logo">Logo poskytovatele</label>
            <input type="text" name="provider_logo" id="provider_logo" class="regular-text" />
            <button type="button" class="button" id="upload_logo_button">Vybrat obrázek</button>
            <p class="description">URL nebo ID obrázku loga poskytovatele</p>
        </div>
        <div class="form-field">
            <label for="provider_ios_app_url">iOS aplikace URL</label>
            <input type="url" name="provider_ios_app_url" id="provider_ios_app_url" class="regular-text" />
            <p class="description">Odkaz na iOS aplikaci v App Store</p>
        </div>
        <div class="form-field">
            <label for="provider_android_app_url">Android aplikace URL</label>
            <input type="url" name="provider_android_app_url" id="provider_android_app_url" class="regular-text" />
            <p class="description">Odkaz na Android aplikaci v Google Play</p>
        </div>
        <div class="form-field">
            <label for="provider_website">Webová stránka</label>
            <input type="url" name="provider_website" id="provider_website" class="regular-text" />
            <p class="description">Oficiální webová stránka poskytovatele</p>
        </div>
        <div class="form-field">
            <label for="provider_notes">Poznámky</label>
            <textarea name="provider_notes" id="provider_notes" rows="3" class="large-text"></textarea>
            <p class="description">Dodatečné informace o poskytovateli</p>
        </div>
        <div class="form-field">
            <label for="provider_source_url">Zdroj dat</label>
            <input type="url" name="provider_source_url" id="provider_source_url" class="regular-text" />
            <p class="description">URL zdroje dat (např. OCM, MPO)</p>
        </div>
        <script>
        jQuery(document).ready(function($) {
            $('#upload_logo_button').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'Vybrat logo poskytovatele',
                    multiple: false
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#provider_logo').val(image_url);
                });
            });
        });
        </script>
        <?php
    }

    // Pole pro poskytovatele při editaci
    public function provider_edit_fields( $term, $taxonomy ) {
        $friendly_name = get_term_meta( $term->term_id, 'provider_friendly_name', true );
        $logo = get_term_meta( $term->term_id, 'provider_logo', true );
        $ios_app_url = get_term_meta( $term->term_id, 'provider_ios_app_url', true );
        $android_app_url = get_term_meta( $term->term_id, 'provider_android_app_url', true );
        $website = get_term_meta( $term->term_id, 'provider_website', true );
        $notes = get_term_meta( $term->term_id, 'provider_notes', true );
        $source_url = get_term_meta( $term->term_id, 'provider_source_url', true );
        ?>
        <tr class="form-field">
            <th scope="row"><label for="provider_friendly_name">Friendly název</label></th>
            <td>
                <input type="text" name="provider_friendly_name" id="provider_friendly_name" value="<?php echo esc_attr($friendly_name); ?>" />
                <p class="description">Uživatelsky přívětivý název poskytovatele (např. "ČEZ" místo "ČEZ, a.s.")</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="provider_logo">Logo poskytovatele</label></th>
            <td>
                <input type="text" name="provider_logo" id="provider_logo" value="<?php echo esc_attr($logo); ?>" class="regular-text" />
                <button type="button" class="button" id="upload_logo_button">Vybrat obrázek</button>
                <?php if ($logo) : ?>
                    <br><br>
                    <img src="<?php echo esc_url($logo); ?>" style="max-width: 100px; max-height: 100px; border: 1px solid #ddd;" />
                <?php endif; ?>
                <p class="description">URL nebo ID obrázku loga poskytovatele</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="provider_ios_app_url">iOS aplikace URL</label></th>
            <td>
                <input type="url" name="provider_ios_app_url" id="provider_ios_app_url" value="<?php echo esc_attr($ios_app_url); ?>" class="regular-text" />
                <p class="description">Odkaz na iOS aplikaci v App Store</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="provider_android_app_url">Android aplikace URL</label></th>
            <td>
                <input type="url" name="provider_android_app_url" id="provider_android_app_url" value="<?php echo esc_attr($android_app_url); ?>" class="regular-text" />
                <p class="description">Odkaz na Android aplikaci v Google Play</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="provider_website">Webová stránka</label></th>
            <td>
                <input type="url" name="provider_website" id="provider_website" value="<?php echo esc_attr($website); ?>" class="regular-text" />
                <p class="description">Oficiální webová stránka poskytovatele</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="provider_notes">Poznámky</label></th>
            <td>
                <textarea name="provider_notes" id="provider_notes" rows="3" class="large-text"><?php echo esc_textarea($notes); ?></textarea>
                <p class="description">Dodatečné informace o poskytovateli</p>
            </td>
        </tr>
        <tr class="form-field">
            <th scope="row"><label for="provider_source_url">Zdroj dat</label></th>
            <td>
                <input type="url" name="provider_source_url" id="provider_source_url" value="<?php echo esc_attr($source_url); ?>" class="regular-text" />
                <p class="description">URL zdroje dat (např. OCM, MPO)</p>
            </td>
        </tr>
        <script>
        jQuery(document).ready(function($) {
            $('#upload_logo_button').click(function(e) {
                e.preventDefault();
                var image = wp.media({
                    title: 'Vybrat logo poskytovatele',
                    multiple: false
                }).open().on('select', function() {
                    var uploaded_image = image.state().get('selection').first();
                    var image_url = uploaded_image.toJSON().url;
                    $('#provider_logo').val(image_url);
                });
            });
        });
        </script>
        <?php
    }

    // Uložení hodnot pro poskytovatele
    public function provider_save_fields( $term_id ) {
        if ( isset( $_POST['provider_friendly_name'] ) ) {
            update_term_meta( $term_id, 'provider_friendly_name', sanitize_text_field($_POST['provider_friendly_name']) );
        }
        if ( isset( $_POST['provider_logo'] ) ) {
            update_term_meta( $term_id, 'provider_logo', esc_url_raw($_POST['provider_logo']) );
        }
        if ( isset( $_POST['provider_ios_app_url'] ) ) {
            update_term_meta( $term_id, 'provider_ios_app_url', esc_url_raw($_POST['provider_ios_app_url']) );
        }
        if ( isset( $_POST['provider_android_app_url'] ) ) {
            update_term_meta( $term_id, 'provider_android_app_url', esc_url_raw($_POST['provider_android_app_url']) );
        }
        if ( isset( $_POST['provider_website'] ) ) {
            update_term_meta( $term_id, 'provider_website', esc_url_raw($_POST['provider_website']) );
        }
        if ( isset( $_POST['provider_notes'] ) ) {
            update_term_meta( $term_id, 'provider_notes', sanitize_textarea_field($_POST['provider_notes']) );
        }
        if ( isset( $_POST['provider_source_url'] ) ) {
            update_term_meta( $term_id, 'provider_source_url', esc_url_raw($_POST['provider_source_url']) );
        }
    }
} 