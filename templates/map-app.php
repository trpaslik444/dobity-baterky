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

// Načíst header template pokud existuje (zobrazí se i na mobilu, ale lze skrýt pomocí CSS)
$header_template = locate_template( array( 'header.php' ) );
if ( $header_template ) {
    // Odstranit wp_head() callbacks před include, protože už jsme volali wp_head() výše
    global $wp_filter;
    $wp_head_callbacks_backup = isset( $wp_filter['wp_head'] ) ? $wp_filter['wp_head'] : null;
    remove_all_actions( 'wp_head' );
    
    ob_start();
    include $header_template;
    $header_output = ob_get_clean();
    
    // Obnovit wp_head callbacks
    if ( $wp_head_callbacks_backup !== null ) {
        $wp_filter['wp_head'] = $wp_head_callbacks_backup;
    }
    
    // Extrahovat pouze obsah mezi <body> tagy (bez samotných tagů)
    if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $header_output, $matches ) ) {
        echo $matches[1];
    } elseif ( preg_match( '/<body[^>]*>(.*)/is', $header_output, $matches ) ) {
        // Pokud není </body>, vezmeme vše po <body>
        echo $matches[1];
    } else {
        // Fallback: některá témata nemají body obsah v header.php – zkusíme běžné partialy
        $header_partials = array(
            'template-parts/header/site-header',
            'template-parts/header/header',
            'parts/header',
        );
        foreach ( $header_partials as $part ) {
            $candidate = locate_template( array( $part . '.php' ) );
            if ( $candidate ) {
                include $candidate;
                break;
            }
        }
    }
}

// Wrapper pro mapu - JS očekává .db-map-root, takže přidáme obě třídy pro kompatibilitu
?>
<div id="db-map-app" class="db-map-app db-map-root">
    <div id="db-map" class="db-map-app__canvas" aria-live="polite"></div>
</div>
<?php do_action( 'db_map_app_after_app' ); ?>

<?php
// Načíst footer template pokud existuje (zobrazí se i na mobilu, ale lze skrýt pomocí CSS)
$footer_template = locate_template( array( 'footer.php' ) );
if ( $footer_template ) {
    // Odstranit wp_footer() callbacks před include, protože ho zavoláme sami později
    global $wp_filter;
    $wp_footer_callbacks_backup = isset( $wp_filter['wp_footer'] ) ? $wp_filter['wp_footer'] : null;
    remove_all_actions( 'wp_footer' );
    
    ob_start();
    include $footer_template;
    $footer_output = ob_get_clean();
    
    // Obnovit wp_footer callbacks před voláním wp_footer() na konci
    if ( $wp_footer_callbacks_backup !== null ) {
        $wp_filter['wp_footer'] = $wp_footer_callbacks_backup;
    }
    
    // Extrahovat obsah před </body> tagem (bez samotného tagu)
    // A také odstranit případné volání wp_footer() z footer obsahu (PHP kód)
    if ( preg_match( '/(.*?)<\/body>/is', $footer_output, $matches ) ) {
        $footer_content = $matches[1];
        // Odstranit případné volání wp_footer() z footer obsahu (PHP kód)
        $footer_content = preg_replace( '/<\?php\s*wp_footer\(\);\s*\?>/i', '', $footer_content );
        echo $footer_content;
    } else {
        // Pokud není </body>, použijeme celý obsah, ale odstraníme wp_footer()
        $footer_content = preg_replace( '/<\?php\s*wp_footer\(\);\s*\?>/i', '', $footer_output );
        if ( trim( $footer_content ) !== '' ) {
            echo $footer_content;
        } else {
            // Fallback: pokus o načtení běžných footer partialů
            $footer_partials = array(
                'template-parts/footer/site-footer',
                'template-parts/footer/footer',
                'parts/footer',
            );
            foreach ( $footer_partials as $part ) {
                $candidate = locate_template( array( $part . '.php' ) );
                if ( $candidate ) {
                    include $candidate;
                    break;
                }
            }
        }
    }
}

// Zavolat wp_footer() jednou na konci
// DŮLEŽITÉ: Voláme wp_footer() přímo, ne přes get_footer(), aby to fungovalo
// i na block/FSE tématech, která nemají footer.php
// Callbacks byly obnoveny výše, takže se zavolají správně
wp_footer();
?>
</body>
</html>
