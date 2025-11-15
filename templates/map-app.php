<?php
/**
 * Template: Dobitý Baterky – samostatná mapová aplikace
 *
 * @package DobityBaterky
 */

if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

?><!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
    <meta charset="<?php bloginfo( 'charset' ); ?>">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo esc_html( get_bloginfo( 'name' ) ); ?> – <?php esc_html_e( 'Mapa nabíjecích míst', 'dobity-baterky' ); ?></title>
    <?php
    /**
     * Umožní pluginům vložit potřebná metadata / skripty (např. PWA manifest).
     */
    wp_head();
    ?>
</head>
<body <?php body_class( 'db-map-app' ); ?>>
<?php
/**
 * Hook pro vložení vlastního UI (menu, overlay apod.).
 */
do_action( 'db_map_app_before_app' );

// Detekce desktop/mobile pro zobrazení headeru a footeru
$is_desktop = ! wp_is_mobile();
?>
<?php if ( $is_desktop ) : ?>
    <?php get_header(); ?>
<?php endif; ?>
<div id="db-map-app" class="db-map-app">
    <div id="db-map" class="db-map-app__canvas" aria-live="polite"></div>
</div>
<?php do_action( 'db_map_app_after_app' ); ?>
<?php if ( $is_desktop ) : ?>
    <?php get_footer(); ?>
<?php else : ?>
    <?php wp_footer(); ?>
<?php endif; ?>
</body>
</html>

