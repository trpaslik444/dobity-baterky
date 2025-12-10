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
     * Voláme wp_head() přímo zde, ne přes get_header(), aby to fungovalo
     * i na block/FSE tématech (jako TwentyTwentyFour), která nemají header.php.
     * Tím zajišťujeme, že assety (Leaflet, CSS, JS) se načtou.
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

// Header/footer neembedujeme - máme vlastní HTML strukturu
// wp_head() je už voláno výše, wp_footer() bude voláno na konci
// Pokud potřebujeme header/footer z WordPressu, můžeme je přidat přes CSS nebo jiný mechanismus

// Wrapper pro mapu - JS očekává .db-map-root, takže přidáme obě třídy pro kompatibilitu
?>
<div id="db-map-app" class="db-map-app db-map-root">
    <div id="db-map" class="db-map-app__canvas" aria-live="polite"></div>
</div>
<?php do_action( 'db_map_app_after_app' ); ?>

<?php
// Zavolat wp_footer() jednou na konci
// DŮLEŽITÉ: Voláme wp_footer() přímo, ne přes get_footer(), aby to fungovalo
// i na block/FSE tématech, která nemají footer.php
wp_footer();
?>
</body>
</html>
