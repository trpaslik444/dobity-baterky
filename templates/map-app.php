<?php
/**
 * Template: Dobitý Baterky – samostatná mapová aplikace
 *
 * @package DobityBaterky
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

get_header();

do_action( 'db_map_app_before_app' );
?>
<div id="db-map-app" class="db-map-app">
    <div id="db-map" class="db-map-app__canvas" aria-live="polite"></div>
</div>
<?php do_action( 'db_map_app_after_app' ); ?>

<?php
get_footer();
