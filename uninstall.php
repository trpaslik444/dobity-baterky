<?php
/**
 * Odinstalační soubor pro plugin Dobitý Baterky
 * 
 * Tento soubor se spustí při odinstalaci pluginu z WordPress adminu
 * a smaže všechna data vytvořená pluginem.
 */

// Bezpečnostní kontrola
if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
    exit;
}

// Smazání všech meta dat pro charging_location
delete_post_meta_by_key( 'db_address' );
delete_post_meta_by_key( 'db_latitude' );
delete_post_meta_by_key( 'db_longitude' );
delete_post_meta_by_key( 'db_provider' );
delete_post_meta_by_key( 'db_charging_types' );
delete_post_meta_by_key( 'db_price_type' );
delete_post_meta_by_key( 'db_connector_count' );
delete_post_meta_by_key( 'db_max_power' );
delete_post_meta_by_key( 'db_connection_type' );

// Smazání všech meta dat pro POI
delete_post_meta_by_key( 'db_poi_address' );
delete_post_meta_by_key( 'db_poi_latitude' );
delete_post_meta_by_key( 'db_poi_longitude' );
delete_post_meta_by_key( 'db_poi_type' );
delete_post_meta_by_key( 'db_poi_icon' );
delete_post_meta_by_key( 'db_poi_color' );

// Smazání všech meta dat pro RV spot
delete_post_meta_by_key( 'db_rv_address' );
delete_post_meta_by_key( 'db_rv_latitude' );
delete_post_meta_by_key( 'db_rv_longitude' );
delete_post_meta_by_key( 'db_rv_type' );
delete_post_meta_by_key( 'db_rv_amenities' );

// Smazání všech postů typu charging_location
$charging_locations = get_posts( array(
    'post_type'      => 'charging_location',
    'numberposts'    => -1,
    'post_status'    => 'any',
) );

foreach ( $charging_locations as $location ) {
    wp_delete_post( $location->ID, true );
}

// Smazání všech postů typu poi
$pois = get_posts( array(
    'post_type'      => 'poi',
    'numberposts'    => -1,
    'post_status'    => 'any',
) );

foreach ( $pois as $poi ) {
    wp_delete_post( $poi->ID, true );
}

// Smazání všech postů typu rv_spot
$rv_spots = get_posts( array(
    'post_type'      => 'rv_spot',
    'numberposts'    => -1,
    'post_status'    => 'any',
) );

foreach ( $rv_spots as $spot ) {
    wp_delete_post( $spot->ID, true );
}

// Smazání všech postů typu spot_zone
$spot_zones = get_posts( array(
    'post_type'      => 'spot_zone',
    'numberposts'    => -1,
    'post_status'    => 'any',
) );

foreach ( $spot_zones as $zone ) {
    wp_delete_post( $zone->ID, true );
}

// Smazání custom taxonomií
$taxonomies = array( 'poi_type', 'rv_type', 'charging_type', 'provider' );
foreach ( $taxonomies as $taxonomy ) {
    $terms = get_terms( array(
        'taxonomy'   => $taxonomy,
        'hide_empty' => false,
    ) );
    
    foreach ( $terms as $term ) {
        wp_delete_term( $term->term_id, $taxonomy );
    }
}

// Smazání custom tabulek
global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}db_feedback" );

// Smazání options
delete_option( 'db_rewrite_flush_needed' );
delete_option( 'db_google_places_api_key' );
delete_option( 'db_map_default_lat' );
delete_option( 'db_map_default_lng' );
delete_option( 'db_map_default_zoom' );
delete_option( 'db_icon_registry' );
delete_option( 'db_color_scheme' );

// Smazání transients
delete_transient( 'db_map_data_cache' );
delete_transient( 'db_poi_icons_cache' );

// Flush rewrite rules
flush_rewrite_rules(); 