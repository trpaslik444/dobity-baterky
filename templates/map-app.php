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

// Pro desktop: vložit obsah headeru (bez duplicitní HTML struktury)
if ( $is_desktop ) {
    $header_template = locate_template( array( 'header.php' ) );
    if ( $header_template ) {
        // Dočasně odstranit wp_head akci, aby se nevolala znovu (už byla volána výše)
        global $wp_filter;
        $wp_head_callbacks = isset( $wp_filter['wp_head'] ) ? $wp_filter['wp_head'] : null;
        remove_all_actions( 'wp_head' );
        
        // Načíst header template a extrahovat pouze obsah body
        ob_start();
        include $header_template;
        $full_header = ob_get_clean();
        
        // Obnovit wp_head callbacks
        if ( $wp_head_callbacks !== null ) {
            $wp_filter['wp_head'] = $wp_head_callbacks;
        }
        
        // Extrahovat pouze obsah mezi <body> tagy (bez samotných tagů)
        if ( preg_match( '/<body[^>]*>(.*?)<\/body>/is', $full_header, $matches ) ) {
            echo $matches[1];
        } elseif ( preg_match( '/<body[^>]*>(.*)/is', $full_header, $matches ) ) {
            // Pokud není </body>, vezmeme vše po <body>
            echo $matches[1];
        }
    }
}
?>
<div id="db-map-app" class="db-map-app">
    <div id="db-map" class="db-map-app__canvas" aria-live="polite"></div>
</div>
<?php do_action( 'db_map_app_after_app' ); ?>
<?php
// Pro desktop: vložit obsah footeru (bez duplicitní HTML struktury)
if ( $is_desktop ) {
    $footer_template = locate_template( array( 'footer.php' ) );
    if ( $footer_template ) {
        // Uložit všechny wp_footer callbacks před jejich dočasným odstraněním
        // Musíme vytvořit hlubokou kopii, protože remove_all_actions modifikuje původní objekt
        global $wp_filter;
        $wp_footer_callbacks = null;
        if ( isset( $wp_filter['wp_footer'] ) && is_object( $wp_filter['wp_footer'] ) ) {
            // Vytvořit hlubokou kopii WP_Hook objektu pomocí clone
            $wp_footer_callbacks = clone $wp_filter['wp_footer'];
        }
        
        // Dočasně odstranit wp_footer akci, aby se nevolala při include footer.php
        // (zavoláme ji sami později pouze jednou)
        remove_all_actions( 'wp_footer' );
        
        // Načíst footer template a extrahovat pouze obsah před </body>
        ob_start();
        include $footer_template;
        $full_footer = ob_get_clean();
        
        // Obnovit wp_footer callbacks před voláním wp_footer()
        // Použít uloženou kopii, která nebyla modifikována remove_all_actions
        if ( $wp_footer_callbacks !== null ) {
            $wp_filter['wp_footer'] = $wp_footer_callbacks;
        }
        
        // Extrahovat obsah před </body> tagem (bez samotného tagu)
        // A také odstranit případné volání wp_footer() z footer obsahu (PHP kód)
        if ( preg_match( '/(.*?)<\/body>/is', $full_footer, $matches ) ) {
            $footer_content = $matches[1];
            // Odstranit případné volání wp_footer() z footer obsahu
            $footer_content = preg_replace( '/<\?php\s*wp_footer\(\);\s*\?>/i', '', $footer_content );
            echo $footer_content;
        } else {
            // Pokud není </body>, použijeme celý obsah, ale odstraníme wp_footer()
            $footer_content = preg_replace( '/<\?php\s*wp_footer\(\);\s*\?>/i', '', $full_footer );
            echo $footer_content;
        }
    }
    // Zavolat wp_footer() pouze jednou (callbacks byly obnoveny z kopie)
    wp_footer();
} else {
    wp_footer();
}
?>
</body>
</html>

