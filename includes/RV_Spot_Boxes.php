<?php
/**
 * Metaboxy pro RV místa (rv_spot)
 * @package DobityBaterky
 */

namespace DB;

/**
 * Správa metaboxů pro RV místa
 */
class RV_Spot_Boxes {
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
     * Inicializace hooků
     */
    public function init() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 2 );
    }

    /**
     * Přidání metaboxu
     */
    public function add_meta_boxes() {
        add_meta_box(
            'rv_spot_details',
            __( 'RV místo – Detaily', 'dobity-baterky' ),
            array( $this, 'render_meta_box' ),
            'rv_spot',
            'normal',
            'high'
        );
    }

    /**
     * Render metaboxu
     */
    public function render_meta_box( $post ) {
        wp_nonce_field( 'rv_spot_save', 'rv_spot_nonce' );
        $address  = get_post_meta( $post->ID, '_rv_address', true );
        $lat      = get_post_meta( $post->ID, '_rv_lat', true );
        $lng      = get_post_meta( $post->ID, '_rv_lng', true );
        $services = get_post_meta( $post->ID, '_rv_services', true );
        $price    = get_post_meta( $post->ID, '_rv_price', true );
        if ( ! is_array( $services ) ) $services = array();
        $services_options = array(
            'voda'      => __( 'Voda', 'dobity-baterky' ),
            'elektrina' => __( 'Elektřina', 'dobity-baterky' ),
            'wc'        => __( 'WC', 'dobity-baterky' ),
            'sprcha'    => __( 'Sprcha', 'dobity-baterky' ),
            'vylevka'   => __( 'Výlevka', 'dobity-baterky' ),
        );
        // Typ RV stání (taxonomy)
        $selected_types = wp_get_post_terms( $post->ID, 'rv_type', array('fields'=>'ids') );
        $terms = get_terms( array('taxonomy'=>'rv_type','hide_empty'=>false) );
        ?>
        <table class="form-table">
            <tr>
                <th><label for="_rv_address"><?php esc_html_e( 'Adresa', 'dobity-baterky' ); ?></label></th>
                <td><input type="text" name="_rv_address" id="_rv_address" value="<?php echo esc_attr( $address ); ?>" class="regular-text" /></td>
                <td rowspan="6" style="vertical-align:top;min-width:350px;">
                    <div id="db-admin-map-rv" style="width:350px;height:400px;margin-left:20px;"></div>
                    <?php if (isset($_GET['db_latlng_error'])): ?>
                        <div style="color:red;font-weight:bold;">Vyplňte platné souřadnice (Latitude a Longitude)!</div>
                    <?php endif; ?>
                </td>
            </tr>
            <tr>
                <th><label for="_rv_lat"><?php esc_html_e( 'Latitude', 'dobity-baterky' ); ?></label></th>
                <td><input type="number" step="any" name="_rv_lat" id="_rv_lat" value="<?php echo esc_attr( $lat ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="_rv_lng"><?php esc_html_e( 'Longitude', 'dobity-baterky' ); ?></label></th>
                <td><input type="number" step="any" name="_rv_lng" id="_rv_lng" value="<?php echo esc_attr( $lng ); ?>" /></td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Typ stání', 'dobity-baterky' ); ?></th>
                <td>
                    <select name="rv_type[]" multiple style="min-width:180px;">
                        <?php foreach ( $terms as $term ) : ?>
                            <option value="<?php echo esc_attr($term->term_id); ?>" <?php echo in_array($term->term_id, $selected_types) ? 'selected' : ''; ?>><?php echo esc_html($term->name); ?></option>
                        <?php endforeach; ?>
                    </select>
                    <br><small><?php esc_html_e('Držte Ctrl/Cmd pro výběr více typů.', 'dobity-baterky'); ?></small>
                </td>
            </tr>
            <tr>
                <th><?php esc_html_e( 'Služby', 'dobity-baterky' ); ?></th>
                <td>
                    <?php foreach ( $services_options as $key => $label ) : ?>
                        <label><input type="checkbox" name="_rv_services[]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, $services ) ); ?> /> <?php echo esc_html( $label ); ?></label><br />
                    <?php endforeach; ?>
                </td>
            </tr>
            <tr>
                <th><label for="_rv_price"><?php esc_html_e( 'Cena', 'dobity-baterky' ); ?></label></th>
                <td><input type="text" name="_rv_price" id="_rv_price" value="<?php echo esc_attr( $price ); ?>" class="regular-text" /></td>
            </tr>
        </table>
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var latInput = document.getElementById('_rv_lat');
            var lngInput = document.getElementById('_rv_lng');
            if (!latInput || !lngInput) return;
            var map = L.map('db-admin-map-rv').setView([
                latInput.value ? parseFloat(latInput.value) : 50.08,
                lngInput.value ? parseFloat(lngInput.value) : 14.42
            ], 13);
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', { attribution: '&copy; OpenStreetMap' }).addTo(map);
            var marker = L.marker([
                latInput.value ? parseFloat(latInput.value) : 50.08,
                lngInput.value ? parseFloat(lngInput.value) : 14.42
            ], {draggable:true}).addTo(map);
            setTimeout(function() { map.invalidateSize(); }, 200);
            marker.on('dragend', function(e) {
                var pos = marker.getLatLng();
                latInput.value = pos.lat.toFixed(7);
                lngInput.value = pos.lng.toFixed(7);
            });
            function updateMarker() {
                var lat = parseFloat(latInput.value);
                var lng = parseFloat(lngInput.value);
                if (!isNaN(lat) && !isNaN(lng)) {
                    marker.setLatLng([lat, lng]);
                    map.setView([lat, lng]);
                }
            }
            latInput.addEventListener('change', updateMarker);
            lngInput.addEventListener('change', updateMarker);
            map.on('click', function(e) {
                marker.setLatLng(e.latlng);
                latInput.value = e.latlng.lat.toFixed(7);
                lngInput.value = e.latlng.lng.toFixed(7);
            });
        });
        </script>
        <?php
    }

    /**
     * Uložení hodnot z metaboxu
     */
    public function save_meta_boxes( $post_id, $post ) {
        if ( ! isset( $_POST['rv_spot_nonce'] ) || ! wp_verify_nonce( $_POST['rv_spot_nonce'], 'rv_spot_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( $post->post_type !== 'rv_spot' ) {
            return;
        }
        if ( isset( $_POST['_rv_address'] ) ) {
            update_post_meta( $post_id, '_rv_address', sanitize_text_field( $_POST['_rv_address'] ) );
        }
        if ( isset( $_POST['_rv_lat'] ) ) {
            update_post_meta( $post_id, '_rv_lat', floatval( $_POST['_rv_lat'] ) );
        }
        if ( isset( $_POST['_rv_lng'] ) ) {
            update_post_meta( $post_id, '_rv_lng', floatval( $_POST['_rv_lng'] ) );
        }
        // Typ RV stání (taxonomy)
        if ( isset($_POST['rv_type']) && is_array($_POST['rv_type']) ) {
            $type_ids = array_map('intval', $_POST['rv_type']);
            wp_set_post_terms( $post_id, $type_ids, 'rv_type', false );
        } else {
            wp_set_post_terms( $post_id, array(), 'rv_type', false );
        }
        $allowed_services = array( 'voda', 'elektrina', 'wc', 'sprcha', 'vylevka' );
        if ( isset( $_POST['_rv_services'] ) && is_array( $_POST['_rv_services'] ) ) {
            $services = array_map( 'sanitize_text_field', $_POST['_rv_services'] );
            $services = array_intersect( $services, $allowed_services );
            update_post_meta( $post_id, '_rv_services', $services );
        } else {
            delete_post_meta( $post_id, '_rv_services' );
        }
        if ( isset( $_POST['_rv_price'] ) ) {
            update_post_meta( $post_id, '_rv_price', sanitize_text_field( $_POST['_rv_price'] ) );
        }
        // Povinné souřadnice
        if (empty($_POST['_rv_lat']) || empty($_POST['_rv_lng']) || !is_numeric($_POST['_rv_lat']) || !is_numeric($_POST['_rv_lng'])) {
            add_filter('redirect_post_location', function($location) {
                return add_query_arg('db_latlng_error', 1, $location);
            });
            return;
        }
    }
} 