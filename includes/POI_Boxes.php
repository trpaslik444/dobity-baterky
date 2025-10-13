<?php
/**
 * Metaboxy pro POI (Body zájmu)
 * @package DobityBaterky
 */

namespace DB;

class POI_Boxes {
    private static $instance = null;

    public static function get_instance() {
        if ( self::$instance === null ) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function init() {
        add_action( 'add_meta_boxes', array( $this, 'add_meta_boxes' ) );
        add_action( 'save_post', array( $this, 'save_meta_boxes' ), 10, 2 );
    }

    public function add_meta_boxes() {
        add_meta_box(
            'poi_details',
            __( 'POI – Detaily', 'dobity-baterky' ),
            array( $this, 'render_meta_box' ),
            'poi',
            'normal',
            'high'
        );
    }

    public function render_meta_box( $post ) {
        wp_nonce_field( 'poi_save', 'poi_nonce' );
        $address  = get_post_meta( $post->ID, '_poi_address', true );
        $lat      = get_post_meta( $post->ID, '_poi_lat', true );
        $lng      = get_post_meta( $post->ID, '_poi_lng', true );

        // Google Places / TripAdvisor API identifikátory
        $legacy_place_id = get_post_meta( $post->ID, '_poi_place_id', true );
        $google_place_id = get_post_meta( $post->ID, '_poi_google_place_id', true );
        if ( empty( $google_place_id ) && ! empty( $legacy_place_id ) ) {
            $google_place_id = $legacy_place_id;
        }
        $tripadvisor_location_id = get_post_meta( $post->ID, '_poi_tripadvisor_location_id', true );
        $preferred_source = get_post_meta( $post->ID, '_poi_primary_external_source', true );

        $google_cache_expires = get_post_meta( $post->ID, '_poi_google_cache_expires', true );
        $tripadvisor_cache_expires = get_post_meta( $post->ID, '_poi_tripadvisor_cache_expires', true );

        // Google Places API data
        $place_id = $google_place_id;
        $phone = get_post_meta( $post->ID, '_poi_phone', true );
        $website = get_post_meta( $post->ID, '_poi_website', true );
        $rating = get_post_meta( $post->ID, '_poi_rating', true );
        $user_rating_count = get_post_meta( $post->ID, '_poi_user_rating_count', true );
        $price_level = get_post_meta( $post->ID, '_poi_price_level', true );
        $opening_hours = get_post_meta( $post->ID, '_poi_opening_hours', true );
        $photos = get_post_meta( $post->ID, '_poi_photos', true );
        $photo_url = get_post_meta( $post->ID, '_poi_photo_url', true );
        $photo_suggested_filename = get_post_meta( $post->ID, '_poi_photo_suggested_filename', true );
        $photo_license = get_post_meta( $post->ID, '_poi_photo_license', true );
        $place_source = get_post_meta( $post->ID, '_poi_place_source', true );
        $icon = get_post_meta( $post->ID, '_poi_icon', true );
        $icon_background_color = get_post_meta( $post->ID, '_poi_icon_background_color', true );
        $icon_mask_uri = get_post_meta( $post->ID, '_poi_icon_mask_uri', true );
        
        // Parsování otevíracích hodin
        $opening_hours_data = $opening_hours ? json_decode($opening_hours, true) : null;
        $photos_data = $photos ? json_decode($photos, true) : null;
        
        ?>
        <div id="poi-admin-metabox-wrap">
            <div style="margin-bottom:1em;">
                <label style="font-weight:bold;">Způsob zadání:</label>
                <label style="margin-left:1em;"><input type="radio" name="poi_admin_mode" value="auto" checked> Automaticky (Google API)</label>
                <label style="margin-left:1em;"><input type="radio" name="poi_admin_mode" value="manual"> Manuálně</label>
            </div>
            <div id="poi-admin-auto-section">
                <div style="margin-bottom:1em;">
                    <label for="poi_admin_search_input">Hledat podle názvu podniku nebo GPS souřadnic:</label><br>
                    <input type="text" id="poi_admin_search_input" style="width:60%;" placeholder="Ty kávo! nebo 50.0705583N, 14.4059925E">
                    <button type="button" id="poi_admin_search_btn" class="button">Vyhledat podniky</button>
                </div>
                <div id="poi_admin_results"></div>
            </div>
            <div id="poi-admin-manual-section" style="display:none;">
                <!-- Manuální pole budou níže, pouze je zobrazíme/skryjeme -->
            </div>
        </div>
        <div id="poi-admin-fields">
        <div style="display: flex; gap: 20px;">
            <div style="flex: 1;">
        <table class="form-table">
            <tr>
                <th><label for="_db_recommended"><?php esc_html_e( 'DB doporučuje', 'dobity-baterky' ); ?></label></th>
                <td>
                    <?php $db_recommended = get_post_meta( $post->ID, '_db_recommended', true ) === '1'; ?>
                    <label><input type="checkbox" name="_db_recommended" id="_db_recommended" value="1" <?php checked($db_recommended); ?> /> Zobrazit jako doporučené (logo DB)</label>
                </td>
            </tr>
            <tr>
                <th><label for="_poi_address"><?php esc_html_e( 'Adresa', 'dobity-baterky' ); ?></label></th>
                <td><input type="text" name="_poi_address" id="_poi_address" value="<?php echo esc_attr( $address ); ?>" class="regular-text" /></td>
            </tr>
            <tr>
                <th><label for="_poi_lat"><?php esc_html_e( 'Latitude', 'dobity-baterky' ); ?></label></th>
                <td><input type="number" step="any" name="_poi_lat" id="_poi_lat" value="<?php echo esc_attr( $lat ); ?>" /></td>
            </tr>
            <tr>
                <th><label for="_poi_lng"><?php esc_html_e( 'Longitude', 'dobity-baterky' ); ?></label></th>
                <td><input type="number" step="any" name="_poi_lng" id="_poi_lng" value="<?php echo esc_attr( $lng ); ?>" /></td>
            </tr>
                    
                    <tr>
                        <th><label for="_poi_google_place_id"><?php esc_html_e( 'Google Place ID', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <input type="text" name="_poi_google_place_id" id="_poi_google_place_id" value="<?php echo esc_attr( $google_place_id ); ?>" class="regular-text" placeholder="ChIJ..." />
                            <p class="description"><?php esc_html_e( 'Slouží pro automatické doplnění kontaktů a fotek z Google Places.', 'dobity-baterky' ); ?></p>
                            <?php if ( $google_cache_expires ) : ?>
                                <p class="description" style="color:#2271b1;">
                                    <?php
                                    printf(
                                        /* translators: %s: datum expirace */
                                        esc_html__( 'Aktuálně uložená data expirují %s (Google povoluje maximálně 30 dní).', 'dobity-baterky' ),
                                        esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $google_cache_expires ) ) )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_tripadvisor_location_id"><?php esc_html_e( 'Tripadvisor Location ID', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <input type="text" name="_poi_tripadvisor_location_id" id="_poi_tripadvisor_location_id" value="<?php echo esc_attr( $tripadvisor_location_id ); ?>" class="regular-text" placeholder="123456" />
                            <p class="description"><?php esc_html_e( 'Používá se jako záložní zdroj dat (bezplatná úroveň Content API).', 'dobity-baterky' ); ?></p>
                            <?php if ( $tripadvisor_cache_expires ) : ?>
                                <p class="description" style="color:#2271b1;">
                                    <?php
                                    printf(
                                        /* translators: %s: datum expirace */
                                        esc_html__( 'Uložená data expirují %s (Tripadvisor povoluje maximálně 24 hodin).', 'dobity-baterky' ),
                                        esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), intval( $tripadvisor_cache_expires ) ) )
                                    );
                                    ?>
                                </p>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_primary_external_source"><?php esc_html_e( 'Preferovaný zdroj dat', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <select name="_poi_primary_external_source" id="_poi_primary_external_source">
                                <option value="google_places" <?php selected( $preferred_source ?: 'google_places', 'google_places' ); ?>><?php esc_html_e( 'Google Places (primární)', 'dobity-baterky' ); ?></option>
                                <option value="tripadvisor" <?php selected( $preferred_source, 'tripadvisor' ); ?>><?php esc_html_e( 'Tripadvisor', 'dobity-baterky' ); ?></option>
                            </select>
                            <p class="description"><?php esc_html_e( 'Primární zdroj je volán jako první, druhý pouze při chybě nebo vyčerpání limitu.', 'dobity-baterky' ); ?></p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_url"><?php esc_html_e( 'URL fotografie', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_photo_url" id="_poi_photo_url" value="<?php echo esc_attr( $photo_url ); ?>" class="regular-text" placeholder="https://..." /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_suggested_filename"><?php esc_html_e( 'Doporučený název fotografie', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_photo_suggested_filename" id="_poi_photo_suggested_filename" value="<?php echo esc_attr( $photo_suggested_filename ); ?>" class="regular-text" placeholder="Moje foto.jpg" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_license"><?php esc_html_e( 'Licence fotografie', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_photo_license" id="_poi_photo_license" value="<?php echo esc_attr( $photo_license ); ?>" class="regular-text" placeholder="CC BY-SA (viz zdroj)" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_place_source"><?php esc_html_e( 'Zdroj místa (URL)', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_place_source" id="_poi_place_source" value="<?php echo esc_attr( $place_source ); ?>" class="regular-text" placeholder="https://..." /></td>
                    </tr>
                    
                    <tr>
                        <th><label for="_poi_phone"><?php esc_html_e( 'Telefon', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_phone" id="_poi_phone" value="<?php echo esc_attr( $phone ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_website"><?php esc_html_e( 'Webové stránky', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_website" id="_poi_website" value="<?php echo esc_attr( $website ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_rating"><?php esc_html_e( 'Hodnocení', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <input type="number" step="0.1" min="0" max="5" name="_poi_rating" id="_poi_rating" value="<?php echo esc_attr( $rating ); ?>" style="width: 80px;" />
                            <?php if ($user_rating_count): ?>
                                <span style="color: #666; margin-left: 10px;">(<?php echo esc_html($user_rating_count); ?> hodnocení)</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_price_level"><?php esc_html_e( 'Cenová úroveň', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <select name="_poi_price_level" id="_poi_price_level">
                                <option value="">Neznámé</option>
                                <option value="INEXPENSIVE" <?php selected($price_level, 'INEXPENSIVE'); ?>>Levné</option>
                                <option value="MODERATE" <?php selected($price_level, 'MODERATE'); ?>>Střední</option>
                                <option value="EXPENSIVE" <?php selected($price_level, 'EXPENSIVE'); ?>>Drahé</option>
                                <option value="VERY_EXPENSIVE" <?php selected($price_level, 'VERY_EXPENSIVE'); ?>>Velmi drahé</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_url"><?php esc_html_e( 'URL fotografie', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_photo_url" id="_poi_photo_url" value="<?php echo esc_attr( $photo_url ); ?>" class="regular-text" placeholder="https://..." /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_suggested_filename"><?php esc_html_e( 'Doporučený název fotografie', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_photo_suggested_filename" id="_poi_photo_suggested_filename" value="<?php echo esc_attr( $photo_suggested_filename ); ?>" class="regular-text" placeholder="Moje foto.jpg" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_license"><?php esc_html_e( 'Licence fotografie', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_photo_license" id="_poi_photo_license" value="<?php echo esc_attr( $photo_license ); ?>" class="regular-text" placeholder="CC BY-SA (viz zdroj)" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_photo_author"><?php esc_html_e( 'Autor fotografie / Atribuce', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_photo_author" id="_poi_photo_author" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_photo_author', true ) ); ?>" class="regular-text" placeholder="Jméno autora / Atribuce" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_place_source"><?php esc_html_e( 'Zdroj místa (URL)', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_place_source" id="_poi_place_source" value="<?php echo esc_attr( $place_source ); ?>" class="regular-text" placeholder="https://..." /></td>
                    </tr>
                    
                    <!-- Univerzální pole pro všechny typy POI -->
                    <tr>
                        <th><label for="_poi_url"><?php esc_html_e( 'Google Maps URL', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_url" id="_poi_url" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_url', true ) ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_vicinity"><?php esc_html_e( 'Krátká adresa', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_vicinity" id="_poi_vicinity" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_vicinity', true ) ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_business_status"><?php esc_html_e( 'Stav podniku', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <select name="_poi_business_status" id="_poi_business_status">
                                <option value="">Neznámé</option>
                                <option value="OPERATIONAL" <?php selected(get_post_meta( $post->ID, '_poi_business_status', true ), 'OPERATIONAL'); ?>>Funguje</option>
                                <option value="CLOSED_TEMPORARILY" <?php selected(get_post_meta( $post->ID, '_poi_business_status', true ), 'CLOSED_TEMPORARILY'); ?>>Dočasně zavřeno</option>
                                <option value="CLOSED_PERMANENTLY" <?php selected(get_post_meta( $post->ID, '_poi_business_status', true ), 'CLOSED_PERMANENTLY'); ?>>Trvale zavřeno</option>
                            </select>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_types"><?php esc_html_e( 'Typy místa', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_types" id="_poi_types" value="<?php 
                            $types = get_post_meta( $post->ID, '_poi_types', true );
                            if (is_array($types)) {
                                echo esc_attr( implode(', ', $types) );
                            } else {
                                echo esc_attr( $types );
                            }
                        ?>" class="regular-text" placeholder="restaurant, cafe, store" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_main_type"><?php esc_html_e( 'Hlavní typ', 'dobity-baterky' ); ?></label></th>
                        <td>
                            <select name="_poi_main_type" id="_poi_main_type">
                                <option value="">Automaticky určeno</option>
                                <option value="restaurant" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'restaurant'); ?>>Restaurace</option>
                                <option value="cafe" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'cafe'); ?>>Kavárna</option>
                                <option value="bar" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'bar'); ?>>Bar</option>
                                <option value="store" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'store'); ?>>Obchod</option>
                                <option value="museum" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'museum'); ?>>Muzeum</option>
                                <option value="hotel" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'hotel'); ?>>Hotel</option>
                                <option value="park" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'park'); ?>>Park</option>
                                <option value="hospital" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'hospital'); ?>>Nemocnice</option>
                                <option value="other" <?php selected(get_post_meta( $post->ID, '_poi_main_type', true ), 'other'); ?>>Jiné</option>
                            </select>
                            <p class="description">Hlavní typ se automaticky určí z Google API, ale můžete ho změnit.</p>
                        </td>
                    </tr>
                    <tr>
                        <th><label for="_poi_utc_offset"><?php esc_html_e( 'Časové pásmo (UTC offset)', 'dobity-baterky' ); ?></label></th>
                        <td><input type="text" name="_poi_utc_offset" id="_poi_utc_offset" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_utc_offset', true ) ); ?>" class="regular-text" placeholder="+1" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_icon"><?php esc_html_e( 'Google ikona', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_icon" id="_poi_icon" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_icon', true ) ); ?>" class="regular-text" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_icon_background_color"><?php esc_html_e( 'Barva pozadí ikony', 'dobity-baterky' ); ?></label></th>
                        <td><input type="color" name="_poi_icon_background_color" id="_poi_icon_background_color" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_icon_background_color', true ) ); ?>" /></td>
                    </tr>
                    <tr>
                        <th><label for="_poi_icon_mask_uri"><?php esc_html_e( 'Google ikona mask', 'dobity-baterky' ); ?></label></th>
                        <td><input type="url" name="_poi_icon_mask_uri" id="_poi_icon_mask_uri" value="<?php echo esc_attr( get_post_meta( $post->ID, '_poi_icon_mask_uri', true ) ); ?>" class="regular-text" /></td>
                    </tr>
                    
                    <!-- Otevírací doba -->
                    <tr>
                        <th><label for="_poi_opening_hours"><?php esc_html_e( 'Otevírací doba (JSON)', 'dobity-baterky' ); ?></label></th>
                        <td><textarea name="_poi_opening_hours" id="_poi_opening_hours" rows="4" class="large-text"><?php echo esc_textarea( get_post_meta( $post->ID, '_poi_opening_hours', true ) ); ?></textarea></td>
                    </tr>
                    
                    <!-- Recenze -->
                    <tr>
                        <th><label for="_poi_reviews"><?php esc_html_e( 'Recenze (JSON)', 'dobity-baterky' ); ?></label></th>
                        <td><textarea name="_poi_reviews" id="_poi_reviews" rows="4" class="large-text"><?php echo esc_textarea( get_post_meta( $post->ID, '_poi_reviews', true ) ); ?></textarea></td>
                    </tr>
                    
                    <!-- Restaurační služby (pouze pro restaurace) -->
                    <tr class="restaurant-fields" style="display: none;">
                        <th><?php esc_html_e( 'Restaurační služby', 'dobity-baterky' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="_poi_delivery" value="1" <?php checked(get_post_meta( $post->ID, '_poi_delivery', true ), '1'); ?> /> Rozvoz</label><br>
                            <label><input type="checkbox" name="_poi_dine_in" value="1" <?php checked(get_post_meta( $post->ID, '_poi_dine_in', true ), '1'); ?> /> Stravování v místě</label><br>
                            <label><input type="checkbox" name="_poi_takeout" value="1" <?php checked(get_post_meta( $post->ID, '_poi_takeout', true ), '1'); ?> /> S sebou</label><br>
                            <label><input type="checkbox" name="_poi_serves_beer" value="1" <?php checked(get_post_meta( $post->ID, '_poi_serves_beer', true ), '1'); ?> /> Pivo</label><br>
                            <label><input type="checkbox" name="_poi_serves_wine" value="1" <?php checked(get_post_meta( $post->ID, '_poi_serves_wine', true ), '1'); ?> /> Víno</label><br>
                            <label><input type="checkbox" name="_poi_serves_breakfast" value="1" <?php checked(get_post_meta( $post->ID, '_poi_serves_breakfast', true ), '1'); ?> /> Snídaně</label><br>
                            <label><input type="checkbox" name="_poi_serves_lunch" value="1" <?php checked(get_post_meta( $post->ID, '_poi_serves_lunch', true ), '1'); ?> /> Oběd</label><br>
                            <label><input type="checkbox" name="_poi_serves_dinner" value="1" <?php checked(get_post_meta( $post->ID, '_poi_serves_dinner', true ), '1'); ?> /> Večeře</label>
                        </td>
                    </tr>
                    
                    <!-- Přístupnost -->
                    <tr>
                        <th><?php esc_html_e( 'Přístupnost', 'dobity-baterky' ); ?></th>
                        <td>
                            <label><input type="checkbox" name="_poi_wheelchair_accessible_entrance" value="1" <?php checked(get_post_meta( $post->ID, '_poi_wheelchair_accessible_entrance', true ), '1'); ?> /> Bezbariérový vstup</label><br>
                            <label><input type="checkbox" name="_poi_curbside_pickup" value="1" <?php checked(get_post_meta( $post->ID, '_poi_curbside_pickup', true ), '1'); ?> /> Curbside pickup</label><br>
                            <label><input type="checkbox" name="_poi_reservable" value="1" <?php checked(get_post_meta( $post->ID, '_poi_reservable', true ), '1'); ?> /> Možnost rezervace</label>
                        </td>
                    </tr>
        </table>
            </div>
            
            <div style="flex: 1;">
                <div id="db-admin-map-poi" style="width:350px;height:300px;margin-bottom:20px;"></div>
                
                <?php if ($icon): ?>
                <div style="margin-bottom: 15px;">
                    <h4>Google ikona:</h4>
                    <img src="<?php echo esc_url($icon); ?>" alt="Place icon" style="max-width: 100px; height: auto;" />
                    <?php if ($icon_background_color): ?>
                        <div style="margin-top: 5px;">
                            <span style="color: #666;">Barva pozadí:</span>
                            <span style="display: inline-block; width: 20px; height: 20px; background-color: <?php echo esc_attr($icon_background_color); ?>; border: 1px solid #ccc; margin-left: 5px;"></span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <?php if ($opening_hours_data): ?>
                <div style="margin-bottom: 15px;">
                    <h4>Otevírací hodiny:</h4>
                    <div style="font-size: 12px; color: #666;">
                        <?php if (isset($opening_hours_data['weekdayDescriptions'])): ?>
                            <?php foreach ($opening_hours_data['weekdayDescriptions'] as $day): ?>
                                <div><?php echo esc_html($day); ?></div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <?php if ($photos_data && is_array($photos_data)): ?>
                <div style="margin-bottom: 15px;">
                    <h4>Fotografie (<?php echo count($photos_data); ?>):</h4>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <?php foreach (array_slice($photos_data, 0, 3) as $photo): ?>
                            <?php if (isset($photo['name'])): ?>
                                <div style="text-align: center;">
                                    <div style="width: 80px; height: 80px; background: #f0f0f0; border: 1px solid #ddd; display: flex; align-items: center; justify-content: center; font-size: 10px; color: #666;">
                                        Foto
                                    </div>
                                    <div style="font-size: 10px; color: #666; margin-top: 5px;">
                                        <?php echo esc_html($photo['widthPx'] ?? ''); ?>x<?php echo esc_html($photo['heightPx'] ?? ''); ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if (isset($_GET['db_latlng_error'])): ?>
            <div style="color:red;font-weight:bold;">Vyplňte platné souřadnice (Latitude a Longitude)!</div>
        <?php endif; ?>
        
        <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
        <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
        <script>
        document.addEventListener('DOMContentLoaded', function() {
            var latInput = document.getElementById('_poi_lat');
            var lngInput = document.getElementById('_poi_lng');
            if (!latInput || !lngInput) return;
            var map = L.map('db-admin-map-poi').setView([
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

            // Přepínání režimu
            var radios = document.querySelectorAll('input[name="poi_admin_mode"]');
            var autoSection = document.getElementById('poi-admin-auto-section');
            var manualSection = document.getElementById('poi-admin-manual-section');
            var fields = document.getElementById('poi-admin-fields');
            radios.forEach(function(radio) {
                radio.addEventListener('change', function() {
                    if (this.value === 'auto') {
                        autoSection.style.display = '';
                        fields.style.display = '';
                    } else {
                        autoSection.style.display = 'none';
                        fields.style.display = '';
                    }
                });
            });
            // Vyhledávání podniků
            document.getElementById('poi_admin_search_btn').onclick = async function() {
                                    var searchInput = document.getElementById('poi_admin_search_input').value.trim();
                var gps = '';
                var isGps = false;

                if (searchInput.includes('N,') || searchInput.includes('S,') || searchInput.includes('E,') || searchInput.includes('W,')) {
                    gps = searchInput;
                    isGps = true;
                } else {
                    // Pokud není GPS, zkusíme vyhledat podle názvu
                    gps = searchInput;
                    isGps = false;
                }



                if (!gps) { alert('Zadejte název podniku nebo GPS souřadnice!'); return; }

                var btn = this;
                btn.disabled = true;
                btn.textContent = 'Hledám...';

                try {
                    var url = '/wp-json/db/v1/google-places-search';
                    var params = {
                        input: gps,
                        radius: 500,
                        maxResults: 5
                    };
                    if (isGps) {
                        var match = gps.match(/(\d+\.\d+)([NS]),\s*(\d+\.\d+)([EW])/);
                        if (match) {
                            params.lat = parseFloat(match[1]);
                            params.lng = parseFloat(match[3]);
                            if (match[2] === 'S') params.lat = -params.lat;
                            if (match[4] === 'W') params.lng = -params.lng;
                        }
                    }

                    var resp = await fetch(url, {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo wp_create_nonce('wp_rest'); ?>'
                        },
                        body: JSON.stringify(params)
                    });
                    var data = await resp.json();
                } catch (e) {
                    console.error('[POI DEBUG] Fetch error:', e);
                    alert('Chyba při komunikaci s API: ' + e.message);
                    btn.disabled = false;
                    btn.textContent = 'Vyhledat podniky';
                    return;
                }
                btn.disabled = false;
                btn.textContent = 'Vyhledat podniky';
                var resultsDiv = document.getElementById('poi_admin_results');
                if (!data.places || !data.places.length) {
                    resultsDiv.innerHTML = '<div style="color:red;">Nenalezeno žádné místo.</div>';
                    return;
                }
                resultsDiv.innerHTML = data.places.map(function(place, i) {
                    return `<div style='margin-bottom:0.5em;cursor:pointer;border:1px solid #eee;padding:0.5em;border-radius:0.5em;' onclick='window.poiAdminSelectPlace(${JSON.stringify(place)})'>
                        <b style='color:#049FE8;'>${place.displayName?.text||'Neznámé místo'}</b><br>
                        <span style='font-size:0.95em;color:#888;'>${place.formattedAddress||''}</span><br>
                        <span style='font-size:0.85em;color:#aaa;'>${place.types?.join(', ')||''}</span>
                    </div>`;
                }).join('');
            };
            // Funkce pro výběr místa
            window.poiAdminSelectPlace = async function(place) {
                try {
                    // Načtení detailů
                    var resp = await fetch('/wp-json/db/v1/google-place-details', {
                        method: 'POST',
                        headers: { 
                            'Content-Type': 'application/json',
                            'X-WP-Nonce': '<?php echo wp_create_nonce("wp_rest"); ?>'
                        },
                        body: JSON.stringify({ placeId: place.placeId })
                    });
                    if (!resp.ok) {
                        var errorText = await resp.text();
                        console.error('[POI DEBUG] Place Details Error:', errorText);
                        throw new Error('HTTP ' + resp.status + ': ' + errorText);
                    }
                    
                    var data = await resp.json();
                    
                    // Předvyplnění polí
                    if (data.displayName && data.displayName.text) {
                        var addressField = document.getElementById('_poi_address');
                        if (addressField) addressField.value = data.formattedAddress || '';
                    }
                    
                    if (data.location) {
                        var latField = document.getElementById('_poi_lat');
                        var lngField = document.getElementById('_poi_lng');
                        if (latField) latField.value = data.location.latitude;
                        if (lngField) lngField.value = data.location.longitude;
                    }
                    
                    if (data.displayName && data.displayName.text) {
                        // Zkusit různé možnosti pro pole názvu
                        var titleField = document.getElementById('post_title') || 
                                        document.getElementById('title') || 
                                        document.querySelector('input[name="post_title"]') ||
                                        document.querySelector('input[name="title"]') ||
                                        document.querySelector('input[type="text"][placeholder*="title" i]') ||
                                        document.querySelector('input[type="text"][placeholder*="název" i]') ||
                                        document.querySelector('.editor-post-title__input') ||
                                        document.querySelector('[data-testid="post-title"]') ||
                                        document.querySelector('.editor-post-title') ||
                                        document.querySelector('h1.editor-post-title__input') ||
                                        document.querySelector('.block-editor-post-title__input');
                        
                        if (titleField) {
                            titleField.value = data.displayName.text;
                        } else {
                            // Pro Block Editor - zkusit najít pole názvu různými způsoby
                            var titleBlock = document.querySelector('.editor-post-title') || 
                                           document.querySelector('[data-testid="post-title"]') ||
                                           document.querySelector('.block-editor-post-title') ||
                                           document.querySelector('h1.editor-post-title__input') ||
                                           document.querySelector('.block-editor-post-title__input') ||
                                           document.querySelector('.editor-post-title__input') ||
                                           document.querySelector('[contenteditable="true"]') ||
                                           document.querySelector('[data-testid="post-title-input"]');
                            
                            if (titleBlock) {
                                // Pro Block Editor musíme nastavit text content
                                titleBlock.textContent = data.displayName.text;
                            } else {
                                // Zkusit najít pole podle placeholder nebo label
                                var titleInput = document.querySelector('input[placeholder*="title" i]') ||
                                               document.querySelector('input[placeholder*="název" i]') ||
                                               document.querySelector('input[placeholder*="Title" i]') ||
                                               document.querySelector('input[placeholder*="Name" i]');
                                
                                if (titleInput) {
                                    titleInput.value = data.displayName.text;
                                } else {
                                    // Zkusit najít pole pomocí "Rename" tlačítka
                                    var renameButton = document.querySelector('[role="menuitem"] span[data-wp-c16t="true"]');
                                    if (renameButton && renameButton.textContent.includes('Rename')) {
                                        // Najít rodičovský element a hledat input pole v jeho blízkosti
                                        var renameContainer = renameButton.closest('[role="menuitem"]');
                                        if (renameContainer) {
                                            // Hledat input pole v celém dokumentu, ale prioritně blízko rename tlačítka
                                            var nearbyInputs = document.querySelectorAll('input[type="text"], textarea, [contenteditable="true"]');
                                            var titleField = null;
                                            
                                            // Prioritně hledat pole s názvem nebo title
                                            for (var i = 0; i < nearbyInputs.length; i++) {
                                                var input = nearbyInputs[i];
                                                if (input.id && (input.id.includes('title') || input.id.includes('name'))) {
                                                    titleField = input;
                                                    break;
                                                }
                                                if (input.className && (input.className.includes('title') || input.className.includes('name'))) {
                                                    titleField = input;
                                                    break;
                                                }
                                            }
                                            
                                            if (titleField) {
                                                if (titleField.hasAttribute('contenteditable')) {
                                                    titleField.textContent = data.displayName.text;
                                                } else {
                                                    titleField.value = data.displayName.text;
                                                }
                                            } else {
                                                // Pole pro název nenalezeno
                                            }
                                        }
                                    } else {
                                        // Pole pro název nenalezeno
                                    }
                                }
                            }
                        }
                    } else {
                        // Chybí displayName v datech
                    }
                    
                    if (data.nationalPhoneNumber) {
                        var phoneField = document.getElementById('_poi_phone');
                        if (phoneField) phoneField.value = data.nationalPhoneNumber;
                    }
                    
                    if (data.websiteUri) {
                        var websiteField = document.getElementById('_poi_website');
                        if (websiteField) websiteField.value = data.websiteUri;
                    }
                    
                    if (data.rating) {
                        var ratingField = document.getElementById('_poi_rating');
                        if (ratingField) ratingField.value = data.rating;
                    }
                    
                    if (data.priceLevel) {
                        var priceField = document.getElementById('_poi_price_level');
                        if (priceField) priceField.value = data.priceLevel;
                    }
                    
                    // Nová pole
                    if (data.url) {
                        var urlField = document.getElementById('_poi_url');
                        if (urlField) urlField.value = data.url;
                    }
                    
                    if (data.vicinity) {
                        var vicinityField = document.getElementById('_poi_vicinity');
                        if (vicinityField) vicinityField.value = data.vicinity;
                    }
                    
                    if (data.businessStatus) {
                        var businessStatusField = document.getElementById('_poi_business_status');
                        if (businessStatusField) businessStatusField.value = data.businessStatus;
                    }
                    
                    // Google ikony
                    if (data.iconUri) {
                        var iconField = document.getElementById('_poi_icon');
                        if (iconField) iconField.value = data.iconUri;
                    }
                    
                    if (data.iconBackgroundColor) {
                        var iconBgColorField = document.getElementById('_poi_icon_background_color');
                        if (iconBgColorField) iconBgColorField.value = data.iconBackgroundColor;
                    }
                    
                    if (data.iconMaskUri) {
                        var iconMaskField = document.getElementById('_poi_icon_mask_uri');
                        if (iconMaskField) iconMaskField.value = data.iconMaskUri;
                    }
                    
                    // Restaurační služby
                    if (data.delivery !== undefined) {
                        var deliveryField = document.querySelector('input[name="_poi_delivery"]');
                        if (deliveryField) deliveryField.checked = data.delivery;
                    }
                    
                    if (data.dineIn !== undefined) {
                        var dineInField = document.querySelector('input[name="_poi_dine_in"]');
                        if (dineInField) dineInField.checked = data.dineIn;
                    }
                    
                    if (data.takeout !== undefined) {
                        var takeoutField = document.querySelector('input[name="_poi_takeout"]');
                        if (takeoutField) takeoutField.checked = data.takeout;
                    }
                    
                    if (data.servesBeer !== undefined) {
                        var servesBeerField = document.querySelector('input[name="_poi_serves_beer"]');
                        if (servesBeerField) servesBeerField.checked = data.servesBeer;
                    }
                    
                    if (data.servesWine !== undefined) {
                        var servesWineField = document.querySelector('input[name="_poi_serves_wine"]');
                        if (servesWineField) servesWineField.checked = data.servesWine;
                    }
                    
                    if (data.servesBreakfast !== undefined) {
                        var servesBreakfastField = document.querySelector('input[name="_poi_serves_breakfast"]');
                        if (servesBreakfastField) servesBreakfastField.checked = data.servesBreakfast;
                    }
                    
                    if (data.servesLunch !== undefined) {
                        var servesLunchField = document.querySelector('input[name="_poi_serves_lunch"]');
                        if (servesLunchField) servesLunchField.checked = data.servesLunch;
                    }
                    
                    if (data.servesDinner !== undefined) {
                        var servesDinnerField = document.querySelector('input[name="_poi_serves_dinner"]');
                        if (servesDinnerField) servesDinnerField.checked = data.servesDinner;
                    }
                    
                    // Přístupnost
                    if (data.wheelchairAccessibleEntrance !== undefined) {
                        var wheelchairField = document.querySelector('input[name="_poi_wheelchair_accessible_entrance"]');
                        if (wheelchairField) wheelchairField.checked = data.wheelchairAccessibleEntrance;
                    }
                    
                    if (data.curbsidePickup !== undefined) {
                        var curbsideField = document.querySelector('input[name="_poi_curbside_pickup"]');
                        if (curbsideField) curbsideField.checked = data.curbsidePickup;
                    }
                    
                    if (data.reservable !== undefined) {
                        var reservableField = document.querySelector('input[name="_poi_reservable"]');
                        if (reservableField) reservableField.checked = data.reservable;
                    }
                    
                    // Nová univerzální pole
                    if (data.types && Array.isArray(data.types)) {
                        var typesField = document.getElementById('_poi_types');
                        if (typesField) typesField.value = data.types.join(', ');
                        
                        // Synchronizace s taxonomií "Typy POI" v pravém sloupci
                        var poiTypesTaxonomy = document.querySelector('input[name="tax_input[poi_type][]"]');
                        if (poiTypesTaxonomy) {
                            // Vyčistit existující typy
                            var existingTags = document.querySelectorAll('.tagchecklist li');
                            existingTags.forEach(function(tag) {
                                var removeLink = tag.querySelector('.ntdelbutton');
                                if (removeLink) removeLink.click();
                            });
                            
                            // Přidat nové typy z Google API
                            data.types.forEach(function(type) {
                                // Přeložit typy do češtiny pro lepší UX
                                var typeTranslations = {
                                    'restaurant': 'restaurace',
                                    'cafe': 'kavárna',
                                    'bar': 'bar',
                                    'bakery': 'pekařství',
                                    'store': 'obchod',
                                    'shopping_mall': 'nákupní centrum',
                                    'supermarket': 'supermarket',
                                    'museum': 'muzeum',
                                    'art_gallery': 'galerie',
                                    'theater': 'divadlo',
                                    'cinema': 'kino',
                                    'gym': 'posilovna',
                                    'stadium': 'stadion',
                                    'park': 'park',
                                    'airport': 'letiště',
                                    'train_station': 'nádraží',
                                    'bus_station': 'autobusové nádraží',
                                    'hospital': 'nemocnice',
                                    'pharmacy': 'lékárna',
                                    'doctor': 'lékař',
                                    'hotel': 'hotel',
                                    'lodging': 'ubytování',
                                    'amusement_park': 'zábavní park',
                                    'aquarium': 'akvárium',
                                    'zoo': 'zoo'
                                };
                                
                                var displayType = typeTranslations[type] || type;
                                
                                // Vytvořit nový tag programově
                                createPoiType(displayType, type);
                            });
                        }
                        
                        // Zobraz/skryj restaurační pole podle typu
                        var restaurantFields = document.querySelectorAll('.restaurant-fields');
                        var isRestaurant = data.types.some(function(type) {
                            return ['restaurant', 'food', 'cafe', 'bar', 'bakery'].includes(type);
                        });
                        
                        restaurantFields.forEach(function(field) {
                            field.style.display = isRestaurant ? 'table-row' : 'none';
                        });
                        
                        // Automatické určení hlavního typu
                        var mainTypeField = document.getElementById('_poi_main_type');
                        if (mainTypeField && data.types && Array.isArray(data.types)) {
                            // Priorita typů pro určení hlavního typu
                            var typePriority = [
                                'restaurant', 'cafe', 'bar', 'bakery', 'food',
                                'museum', 'art_gallery', 'theater', 'cinema',
                                'hotel', 'lodging',
                                'store', 'shopping_mall', 'supermarket',
                                'gym', 'stadium', 'park',
                                'airport', 'train_station', 'bus_station',
                                'hospital', 'pharmacy', 'doctor',
                                'amusement_park', 'aquarium', 'zoo'
                            ];
                            
                            var mainType = '';
                            for (var i = 0; i < typePriority.length; i++) {
                                if (data.types.includes(typePriority[i])) {
                                    mainType = typePriority[i];
                                    break;
                                }
                            }
                            
                            if (mainType) {
                                mainTypeField.value = mainType;
                            } else if (data.types.length > 0) {
                                mainTypeField.value = 'other';
                            }
                        }
                    }
                    
                    // Vyslat event pro Block Editor
                    try {
                        window.dispatchEvent(new CustomEvent('poi:placeSelected', {
                            detail: data
                        }));
                    } catch (error) {
                        console.error('[POI DEBUG] Chyba při vysílání eventu:', error);
                    }
                    
                    alert('Pole byla předvyplněna z Google API. Zkontrolujte a uložte.');
                } catch (error) {
                    console.error('[POI DEBUG] Place Details Error:', error);
                    alert('Chyba při načítání detailů: ' + error.message);
                }
            };
            
            // Funkce pro vytváření typů POI
            function createPoiType(displayName, googleType) {
                // Nejdříve zkusit najít existující term
                var existingTerms = document.querySelectorAll('.tagchecklist li');
                var termExists = false;
                existingTerms.forEach(function(term) {
                    var termText = term.querySelector('.tagchecklist span').textContent;
                    if (termText === displayName) {
                        termExists = true;
                    }
                });
                
                if (!termExists) {
                    // Vytvořit term programově přes AJAX
                    fetch(ajaxurl, {
                        method: 'POST',
                        headers: {
                            'Content-Type': 'application/x-www-form-urlencoded',
                        },
                        body: 'action=create_poi_type&display_name=' + encodeURIComponent(displayName) + '&google_type=' + encodeURIComponent(googleType) + '&post_id=<?php echo $post->ID; ?>&nonce=<?php echo wp_create_nonce("create_poi_type"); ?>'
                    })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            // Přidat term do UI
                            var tagInput = document.querySelector('input[name="new-tag-poi_type"]');
                            if (tagInput) {
                                tagInput.value = displayName;
                                var addButton = document.querySelector('.tagadd');
                                if (addButton) addButton.click();
                            }
                        }
                    })
                    .catch(error => {
                        console.error('Chyba při vytváření typu POI:', error);
                    });
                }
            }
        });
        </script>
        <?php
    }

    public function save_meta_boxes( $post_id, $post ) {
        if ( ! isset( $_POST['poi_nonce'] ) || ! wp_verify_nonce( $_POST['poi_nonce'], 'poi_save' ) ) {
            return;
        }
        if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
            return;
        }
        if ( ! current_user_can( 'edit_post', $post_id ) ) {
            return;
        }
        if ( $post->post_type !== 'poi' ) {
            return;
        }
        
        // Základní metadata
        if ( isset( $_POST['_poi_address'] ) ) {
            update_post_meta( $post_id, '_poi_address', sanitize_text_field( $_POST['_poi_address'] ) );
        }
        if ( isset( $_POST['_poi_lat'] ) ) {
            update_post_meta( $post_id, '_poi_lat', sanitize_text_field( $_POST['_poi_lat'] ) );
        }
        if ( isset( $_POST['_poi_lng'] ) ) {
            update_post_meta( $post_id, '_poi_lng', sanitize_text_field( $_POST['_poi_lng'] ) );
        }
        
        // Google Places API metadata
        if ( isset( $_POST['_poi_google_place_id'] ) ) {
            $new_google_id = sanitize_text_field( $_POST['_poi_google_place_id'] );
            $old_google_id = get_post_meta( $post_id, '_poi_google_place_id', true );
            update_post_meta( $post_id, '_poi_google_place_id', $new_google_id );
            if ( $new_google_id !== $old_google_id ) {
                delete_post_meta( $post_id, '_poi_google_cache' );
                delete_post_meta( $post_id, '_poi_google_cache_expires' );
            }
        }
        if ( isset( $_POST['_poi_tripadvisor_location_id'] ) ) {
            $new_tripadvisor_id = sanitize_text_field( $_POST['_poi_tripadvisor_location_id'] );
            $old_tripadvisor_id = get_post_meta( $post_id, '_poi_tripadvisor_location_id', true );
            update_post_meta( $post_id, '_poi_tripadvisor_location_id', $new_tripadvisor_id );
            if ( $new_tripadvisor_id !== $old_tripadvisor_id ) {
                delete_post_meta( $post_id, '_poi_tripadvisor_cache' );
                delete_post_meta( $post_id, '_poi_tripadvisor_cache_expires' );
            }
        }
        if ( isset( $_POST['_poi_primary_external_source'] ) ) {
            $preferred = sanitize_text_field( $_POST['_poi_primary_external_source'] );
            update_post_meta( $post_id, '_poi_primary_external_source', in_array( $preferred, array( 'google_places', 'tripadvisor' ), true ) ? $preferred : 'google_places' );
        }

        if ( isset( $_POST['_poi_phone'] ) ) {
            update_post_meta( $post_id, '_poi_phone', sanitize_text_field( $_POST['_poi_phone'] ) );
        }
        if ( isset( $_POST['_poi_website'] ) ) {
            update_post_meta( $post_id, '_poi_website', esc_url_raw( $_POST['_poi_website'] ) );
        }
        if ( isset( $_POST['_poi_rating'] ) ) {
            update_post_meta( $post_id, '_poi_rating', floatval( $_POST['_poi_rating'] ) );
        }
        if ( isset( $_POST['_poi_price_level'] ) ) {
            update_post_meta( $post_id, '_poi_price_level', sanitize_text_field( $_POST['_poi_price_level'] ) );
        }
        if ( isset( $_POST['_poi_photo_url'] ) ) {
            update_post_meta( $post_id, '_poi_photo_url', esc_url_raw( $_POST['_poi_photo_url'] ) );
        }
        if ( isset( $_POST['_poi_photo_suggested_filename'] ) ) {
            update_post_meta( $post_id, '_poi_photo_suggested_filename', sanitize_text_field( $_POST['_poi_photo_suggested_filename'] ) );
        }
        if ( isset( $_POST['_poi_photo_license'] ) ) {
            update_post_meta( $post_id, '_poi_photo_license', sanitize_text_field( $_POST['_poi_photo_license'] ) );
        }
        if ( isset( $_POST['_poi_place_source'] ) ) {
            update_post_meta( $post_id, '_poi_place_source', esc_url_raw( $_POST['_poi_place_source'] ) );
        }
        if ( isset( $_POST['_poi_photo_author'] ) ) {
            update_post_meta( $post_id, '_poi_photo_author', sanitize_text_field( $_POST['_poi_photo_author'] ) );
        }
        
        // Nová pole z Google Places API
        if ( isset( $_POST['_poi_url'] ) ) {
            update_post_meta( $post_id, '_poi_url', esc_url_raw( $_POST['_poi_url'] ) );
        }
        if ( isset( $_POST['_poi_vicinity'] ) ) {
            update_post_meta( $post_id, '_poi_vicinity', sanitize_text_field( $_POST['_poi_vicinity'] ) );
        }
        if ( isset( $_POST['_poi_business_status'] ) ) {
            update_post_meta( $post_id, '_poi_business_status', sanitize_text_field( $_POST['_poi_business_status'] ) );
        }
        
        // Univerzální pole pro všechny typy POI
        if ( isset( $_POST['_poi_types'] ) ) {
            // Uložit typy jako array
            $types = sanitize_text_field( $_POST['_poi_types'] );
            if (!empty($types)) {
                $types_array = array_map('trim', explode(',', $types));
                update_post_meta( $post_id, '_poi_types', $types_array );
            }
        }
        if ( isset( $_POST['_poi_main_type'] ) ) {
            update_post_meta( $post_id, '_poi_main_type', sanitize_text_field( $_POST['_poi_main_type'] ) );
            
            // Přidat hlavní typ do taxonomie, pokud není prázdný
            $main_type = sanitize_text_field( $_POST['_poi_main_type'] );
            if (!empty($main_type) && $main_type !== 'other') {
                // Překlad hlavního typu do češtiny
                $type_translations = array(
                    'restaurant' => 'restaurace',
                    'cafe' => 'kavárna',
                    'bar' => 'bar',
                    'bakery' => 'pekařství',
                    'store' => 'obchod',
                    'shopping_mall' => 'nákupní centrum',
                    'supermarket' => 'supermarket',
                    'museum' => 'muzeum',
                    'art_gallery' => 'galerie',
                    'theater' => 'divadlo',
                    'cinema' => 'kino',
                    'gym' => 'posilovna',
                    'stadium' => 'stadion',
                    'park' => 'park',
                    'airport' => 'letiště',
                    'train_station' => 'nádraží',
                    'bus_station' => 'autobusové nádraží',
                    'hospital' => 'nemocnice',
                    'pharmacy' => 'lékárna',
                    'doctor' => 'lékař',
                    'hotel' => 'hotel',
                    'lodging' => 'ubytování',
                    'amusement_park' => 'zábavní park',
                    'aquarium' => 'akvárium',
                    'zoo' => 'zoo'
                );
                
                $display_main_type = isset($type_translations[$main_type]) ? $type_translations[$main_type] : $main_type;
                
                // Vytvořit nebo najít term pro hlavní typ
                $existing_term = get_term_by('name', $display_main_type, 'poi_type');
                $term_id = null;
                
                if (!$existing_term) {
                    // Vytvořit nový term
                    $term_result = wp_insert_term($display_main_type, 'poi_type');
                    if (!is_wp_error($term_result)) {
                        $term_id = is_array($term_result) ? $term_result['term_id'] : $term_result;
                        // Uložit původní typ jako meta
                        update_term_meta($term_id, 'google_type', $main_type);
                        
                        // Nové typy POI budou mít prázdný pin - ikona se přidá manuálně v admin panelu
                    }
                } else {
                    $term_id = $existing_term->term_id;
                    
                    // Existující typy POI si zachovají své ikony - Google ikony se nepoužívají
                }
                
                // Přiřadit hlavní typ k příspěvku
                if ($term_id) {
                    wp_set_post_terms($post_id, array($display_main_type), 'poi_type', true);
                }
            }
        }
        if ( isset( $_POST['_poi_utc_offset'] ) ) {
            update_post_meta( $post_id, '_poi_utc_offset', sanitize_text_field( $_POST['_poi_utc_offset'] ) );
        }
        if ( isset( $_POST['_poi_icon'] ) ) {
            update_post_meta( $post_id, '_poi_icon', esc_url_raw( $_POST['_poi_icon'] ) );
        }
        if ( isset( $_POST['_poi_icon_background_color'] ) ) {
            update_post_meta( $post_id, '_poi_icon_background_color', sanitize_hex_color( $_POST['_poi_icon_background_color'] ) );
        }
        if ( isset( $_POST['_poi_icon_mask_uri'] ) ) {
            update_post_meta( $post_id, '_poi_icon_mask_uri', esc_url_raw( $_POST['_poi_icon_mask_uri'] ) );
        }
        if ( isset( $_POST['_poi_opening_hours'] ) ) {
            update_post_meta( $post_id, '_poi_opening_hours', sanitize_textarea_field( $_POST['_poi_opening_hours'] ) );
        }
        if ( isset( $_POST['_poi_reviews'] ) ) {
            update_post_meta( $post_id, '_poi_reviews', sanitize_textarea_field( $_POST['_poi_reviews'] ) );
        }
        
        // Restaurační služby
        update_post_meta( $post_id, '_poi_delivery', isset( $_POST['_poi_delivery'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_dine_in', isset( $_POST['_poi_dine_in'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_takeout', isset( $_POST['_poi_takeout'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_serves_beer', isset( $_POST['_poi_serves_beer'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_serves_wine', isset( $_POST['_poi_serves_wine'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_serves_breakfast', isset( $_POST['_poi_serves_breakfast'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_serves_lunch', isset( $_POST['_poi_serves_lunch'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_serves_dinner', isset( $_POST['_poi_serves_dinner'] ) ? '1' : '0' );
        
        // Přístupnost
        // DB doporučuje (uložení)
        update_post_meta( $post_id, '_db_recommended', isset( $_POST['_db_recommended'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_wheelchair_accessible_entrance', isset( $_POST['_poi_wheelchair_accessible_entrance'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_curbside_pickup', isset( $_POST['_poi_curbside_pickup'] ) ? '1' : '0' );
        update_post_meta( $post_id, '_poi_reservable', isset( $_POST['_poi_reservable'] ) ? '1' : '0' );
        

    }
    
    /**
     * Uloží Google ikonu jako soubor a nastaví ji jako základní ikonu pro typ POI
     */
    private function save_google_icon_as_svg($icon_url, $term_id, $term_name) {
        // Stáhnout ikonu z Google API
        $response = wp_remote_get($icon_url);
        if (is_wp_error($response)) {
            error_log('[POI DEBUG] Chyba při stahování ikony: ' . $response->get_error_message());
            return false;
        }
        
        $icon_content = wp_remote_retrieve_body($response);
        if (empty($icon_content)) {
            error_log('[POI DEBUG] Prázdný obsah ikony');
            return false;
        }
        
        // Zjistit typ souboru podle URL nebo obsahu
        $file_extension = 'png'; // Google většinou vrací PNG
        if (strpos($icon_url, '.svg') !== false) {
            $file_extension = 'svg';
        }
        
        // Vytvořit název souboru pro ikonu
        $icon_slug = sanitize_title($term_name) . '-google.' . $file_extension;
        $icon_filename = $icon_slug;
        $icon_path = DB_PLUGIN_DIR . 'assets/icons/' . $icon_filename;
        
        // Zajistit, že adresář pro ikony existuje
        $icons_dir = DB_PLUGIN_DIR . 'assets/icons/';
        if (!file_exists($icons_dir)) {
            wp_mkdir_p($icons_dir);
        }
        
        // Uložit ikonu jako soubor
        $result = file_put_contents($icon_path, $icon_content);
        if ($result === false) {
            error_log('[POI DEBUG] Chyba při ukládání ikony do souboru');
            return false;
        }
        
        // Nastavit ikonu jako základní pro typ POI
        update_term_meta($term_id, 'icon_slug', $icon_slug);
        update_term_meta($term_id, 'color_hex', '#FFFFFF'); // Bílá barva ikony
        update_term_meta($term_id, 'is_google_icon', '1'); // Označit jako Google ikonu
        
        error_log('[POI DEBUG] Ikona uložena jako: ' . $icon_filename . ' pro typ: ' . $term_name);
        return true;
    }
}

// AJAX handler pro nahrávání Google fotky
add_action('wp_ajax_upload_google_photo', 'db_upload_google_photo');
function db_upload_google_photo() {
    try {
        // Kontrola oprávnění
        if (!current_user_can('upload_files')) {
            wp_send_json_error('Nedostatečná oprávnění pro nahrávání souborů');
            return;
        }
        
        // Kontrola nonce
        if (!wp_verify_nonce($_POST['nonce'], 'upload_google_photo')) {
            wp_send_json_error('Security check failed');
            return;
        }
        
        $photo_url = sanitize_url($_POST['photo_url']);
        $post_title = sanitize_text_field($_POST['post_title']);
        $post_id = intval($_POST['post_id'] ?? 0);
        
        if (empty($photo_url)) {
            wp_send_json_error('Chybí URL fotky');
            return;
        }
        
        error_log('[POI DEBUG] Pokus o nahrání fotky: ' . $photo_url);
        
        // Načíst potřebné WordPress funkce
        require_once ABSPATH . 'wp-admin/includes/file.php';
        require_once ABSPATH . 'wp-admin/includes/media.php';
        require_once ABSPATH . 'wp-admin/includes/image.php';
        
        // Použít media_sideload_image() - WordPress funkce pro nahrávání externích obrázků
        $tmp = media_sideload_image($photo_url, $post_id, $post_title, 'src');
        
        if (is_wp_error($tmp)) {
            error_log('[POI DEBUG] Chyba při nahrávání: ' . $tmp->get_error_message());
            wp_send_json_error('Chyba při nahrávání: ' . $tmp->get_error_message());
            return;
        }
        
        // Najít ID posledního přidaného attachmentu
        $attachments = get_posts(array(
            'numberposts' => 1,
            'post_type' => 'attachment',
            'post_parent' => $post_id,
            'orderby' => 'date',
            'order' => 'DESC',
        ));
        
        if (!empty($attachments)) {
            $attachment_id = $attachments[0]->ID;
            $attachment_url = wp_get_attachment_url($attachment_id);
            
            // Nastavit jako featured image
            set_post_thumbnail($post_id, $attachment_id);
            
            error_log('[POI DEBUG] Fotka úspěšně nahrána, ID: ' . $attachment_id);
            
            wp_send_json_success(array(
                'attachment_id' => $attachment_id,
                'url' => $attachment_url
            ));
        } else {
            wp_send_json_error('Nepodařilo se najít nahraný obrázek');
        }
        
    } catch (Exception $e) {
        error_log('[POI DEBUG] Exception: ' . $e->getMessage());
        wp_send_json_error('Exception: ' . $e->getMessage());
    }
} 

// AJAX handler pro aktualizaci názvu příspěvku
add_action('wp_ajax_update_post_title', 'db_update_post_title');
function db_update_post_title() {
    // Kontrola oprávnění
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Nedostatečná oprávnění');
        return;
    }
    
    // Kontrola nonce
    if (!wp_verify_nonce($_POST['nonce'], 'update_post_title')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $post_id = intval($_POST['post_id']);
    $title = sanitize_text_field($_POST['title']);
    
    if (empty($post_id) || empty($title)) {
        wp_send_json_error('Chybí post_id nebo title');
        return;
    }
    
    // Kontrola, zda příspěvek existuje a uživatel ho může editovat
    $post = get_post($post_id);
    if (!$post || !current_user_can('edit_post', $post_id)) {
        wp_send_json_error('Příspěvek neexistuje nebo nemáte oprávnění k editaci');
        return;
    }
    
    // Aktualizovat název příspěvku
    $result = wp_update_post(array(
        'ID' => $post_id,
        'post_title' => $title
    ));
    
    if (is_wp_error($result)) {
        wp_send_json_error('Chyba při aktualizaci: ' . $result->get_error_message());
    } else {
        wp_send_json_success(array(
            'post_id' => $post_id,
            'title' => $title
        ));
    }
} 

// Načtení skriptu pro Block Editor integraci
add_action('enqueue_block_editor_assets', function() {
    // Načíst pouze pro POI post type
    global $post_type;
    if ($post_type !== 'poi') {
        return;
    }

    wp_enqueue_script(
        'poi-google-fill',
        plugin_dir_url(__FILE__) . '../assets/poi-google-fill.js',
        array('wp-data', 'wp-api-fetch'), // důležité závislosti!
        '1.0.0',
        true
    );

    wp_localize_script(
        'poi-google-fill',
        'POI_Google',
        array(
            'nonce' => wp_create_nonce('wp_rest'),
            'apiKey' => get_option('db_google_api_key'),
        )
    );
}); 

// REST endpoint pro nahrávání fotky bude registrován v hlavní třídě

// AJAX handler pro vytváření typů POI
add_action('wp_ajax_create_poi_type', 'db_create_poi_type');
function db_create_poi_type() {
    // Kontrola oprávnění
    if (!current_user_can('edit_posts')) {
        wp_send_json_error('Nedostatečná oprávnění');
        return;
    }
    
    // Kontrola nonce
    if (!wp_verify_nonce($_POST['nonce'], 'create_poi_type')) {
        wp_send_json_error('Security check failed');
        return;
    }
    
    $display_name = sanitize_text_field($_POST['display_name']);
    $google_type = sanitize_text_field($_POST['google_type']);
    $post_id = intval($_POST['post_id']);
    
    if (empty($display_name)) {
        wp_send_json_error('Chybí display_name');
        return;
    }
    
    // Vytvořit nebo najít term
    $term = term_exists($display_name, 'poi_type');
    if (!$term) {
        $term = wp_insert_term($display_name, 'poi_type');
        }
    
    if (is_wp_error($term)) {
        wp_send_json_error('Chyba při vytváření typu: ' . $term->get_error_message());
        return;
    }
    
    $term_id = is_array($term) ? $term['term_id'] : $term;
    
    // Uložit Google typ jako meta
    update_term_meta($term_id, 'google_type', $google_type);
    
    // Přiřadit term k příspěvku
    wp_set_object_terms($post_id, $term_id, 'poi_type', true);
    
    wp_send_json_success(array(
        'term_id' => $term_id,
        'display_name' => $display_name,
        'google_type' => $google_type
    ));
} 