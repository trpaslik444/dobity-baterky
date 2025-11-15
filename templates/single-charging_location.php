<?php
/**
 * Single template: charging_location
 */
if (!defined('ABSPATH')) exit;
get_header();

$post_id   = get_the_ID();
$title     = get_the_title();
$lat       = get_post_meta($post_id, '_db_lat', true);
$lng       = get_post_meta($post_id, '_db_lng', true);
$address   = get_post_meta($post_id, '_db_address', true);
$operator  = get_post_meta($post_id, '_operator', true);
$rest_nonce = wp_create_nonce('wp_rest');

$connectors = get_post_meta($post_id, '_connectors', true);
if (!is_array($connectors)) {
    $connectors = array();
}

$connector_total = 0;
$max_power = 0;
foreach ($connectors as $connector) {
    $connector_total += intval($connector['quantity'] ?? 0);
    $power_kw = floatval($connector['power_kw'] ?? ($connector['power'] ?? 0));
    if ($power_kw > $max_power) {
        $max_power = $power_kw;
    }
}

$featured = '';
if (has_post_thumbnail($post_id)) {
    $featured = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');
}
if (!$featured) $featured = get_post_meta($post_id, '_featured_image_url', true);
if (!$featured) $featured = get_post_meta($post_id, '_ocm_image_url', true);

$opening_hours = get_post_meta($post_id, '_db_opening_hours', true);
if (empty($opening_hours)) {
    $opening_hours = get_post_meta($post_id, '_opening_hours', true);
}
$price_info = get_post_meta($post_id, '_db_price', true);
$rating = get_post_meta($post_id, '_db_rating', true);
$phone = get_post_meta($post_id, '_db_phone', true);
if (empty($phone)) {
    $phone = get_post_meta($post_id, '_phone', true);
}
$website = get_post_meta($post_id, '_db_website', true);
if (empty($website)) {
    $website = get_post_meta($post_id, '_website', true);
}
$email = get_post_meta($post_id, '_db_email', true);
$evse_meta = get_post_meta($post_id, '_db_evse_total', true);
$evse_total = $evse_meta ? intval($evse_meta) : ($connector_total ?: null);

$amenities_raw = get_post_meta($post_id, '_db_amenities', true);
$amenities = array();
if (!empty($amenities_raw)) {
    if (is_string($amenities_raw)) {
        $decoded = json_decode($amenities_raw, true);
        if (json_last_error() === JSON_ERROR_NONE && is_array($decoded)) {
            $amenities = $decoded;
        }
    } elseif (is_array($amenities_raw)) {
        $amenities = $amenities_raw;
    }
}

$description_preview = '';
if (has_excerpt($post_id)) {
    $description_preview = wp_strip_all_tags(get_the_excerpt($post_id));
} else {
    $description_preview = wp_trim_words(wp_strip_all_tags(get_post_field('post_content', $post_id)), 40, '…');
}

$lic_id   = get_post_meta($post_id, '_license_id', true);
$lic_name = get_post_meta($post_id, '_license_name', true);
$lic_url  = get_post_meta($post_id, '_license_url', true);
$attrib   = get_post_meta($post_id, '_attribution', true);
?>

<main class="db-detail" data-db-feedback="single.charging_location">
    <div class="db-detail-grid">
        <section class="db-detail-left">
            <div class="db-detail-hero">
                <div class="db-detail-hero-info">
                    <div class="db-detail-chip-row">
                        <span class="db-detail-chip db-detail-chip--type">Nabíjecí místo</span>
                        <?php if (!empty($operator)) : ?>
                            <span class="db-detail-chip db-detail-chip--operator">Operátor: <?php echo esc_html($operator); ?></span>
                        <?php endif; ?>
                        <?php if (!empty($rating)) : ?>
                            <span class="db-detail-chip db-detail-chip--rating">★ <?php echo esc_html(number_format_i18n(floatval($rating), 1)); ?></span>
                        <?php endif; ?>
                    </div>
                    <h1 class="db-detail-title"><?php echo esc_html($title); ?></h1>
                    <?php if (!empty($address)) : ?>
                        <div class="db-detail-meta"><i class="db-icon-location"></i><span><?php echo esc_html($address); ?></span></div>
                    <?php endif; ?>
                    <?php if (is_numeric($lat) && is_numeric($lng)) : ?>
                        <div class="db-detail-meta"><i class="db-icon-coordinates"></i><span><?php echo esc_html(number_format_i18n($lat, 6)); ?>, <?php echo esc_html(number_format_i18n($lng, 6)); ?></span></div>
                    <?php endif; ?>
                    <div class="db-detail-actions">
                        <?php if (is_numeric($lat) && is_numeric($lng)) : ?>
                            <div class="db-nav-dropdown">
                                <button type="button" class="db-detail-button" id="db-nav-btn">
                                    <i class="db-icon-navigation"></i><span>Navigovat</span>
                                </button>
                                <div id="db-nav-menu" class="db-nav-menu">
                                    <a class="db-nav-item" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&amp;destination=<?php echo rawurlencode($lat . ',' . $lng); ?>">Google Maps</a>
                                    <a class="db-nav-item" target="_blank" rel="noopener" href="https://maps.apple.com/?daddr=<?php echo rawurlencode($lat . ',' . $lng); ?>">Apple Maps</a>
                                    <a class="db-nav-item" target="_blank" rel="noopener" href="https://mapy.cz/zakladni?source=coor&amp;id=<?php echo rawurlencode($lng); ?>,<?php echo rawurlencode($lat); ?>&amp;x=<?php echo rawurlencode($lng); ?>&amp;y=<?php echo rawurlencode($lat); ?>&amp;z=16">Mapy.cz</a>
                                </div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
                <div class="db-detail-hero-media">
                    <?php if (!empty($featured)) : ?>
                        <img src="<?php echo esc_url($featured); ?>" alt="<?php echo esc_attr($title); ?>" />
                    <?php else : ?>
                        <div class="db-detail-hero-placeholder">
                            <span>⚡</span>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

            <div class="db-detail-left-scroll">
                <div class="db-detail-section">
                    <h2>Rychlý přehled</h2>
                    <div class="db-detail-stats-grid">
                        <div class="db-detail-stat">
                            <span class="label">Max. výkon</span>
                            <span class="value"><?php echo $max_power > 0 ? esc_html(number_format_i18n($max_power, 0)) . ' kW' : '–'; ?></span>
                        </div>
                        <div class="db-detail-stat">
                            <span class="label">Konektory</span>
                            <span class="value"><?php echo esc_html($connector_total ?: count($connectors)); ?></span>
                        </div>
                        <?php if (!empty($evse_total)) : ?>
                            <div class="db-detail-stat">
                                <span class="label">Stání</span>
                                <span class="value"><?php echo esc_html($evse_total); ?></span>
                            </div>
                        <?php endif; ?>
                        <?php if (!empty($price_info)) : ?>
                            <div class="db-detail-stat">
                                <span class="label">Ceník</span>
                                <span class="value"><?php echo esc_html($price_info); ?></span>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="db-detail-section">
                    <h2>Konektory</h2>
                    <?php if (!empty($connectors)) : ?>
                        <div class="db-detail-connector-grid">
                            <?php foreach ($connectors as $connector) :
                                $type = $connector['type'] ?? '';
                                $power = $connector['power_kw'] ?? ($connector['power'] ?? '');
                                $quantity = $connector['quantity'] ?? '';
                                $current = $connector['current_type'] ?? '';
                                ?>
                                <div class="db-detail-connector-card">
                                    <div class="db-detail-connector-header">
                                        <span class="connector-type"><?php echo esc_html($type); ?></span>
                                        <?php if (!empty($power)) : ?>
                                            <span class="connector-power"><?php echo esc_html(number_format_i18n(floatval($power), 0)); ?> kW</span>
                                        <?php endif; ?>
                                    </div>
                                    <div class="db-detail-connector-meta">
                                        <?php if (!empty($current)) : ?>
                                            <span><?php echo esc_html($current); ?></span>
                                        <?php endif; ?>
                                        <?php if (!empty($quantity)) : ?>
                                            <span><?php echo esc_html(intval($quantity)); ?> ks</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php else : ?>
                        <p class="db-detail-empty">Detail konektorů není dostupný.</p>
                    <?php endif; ?>
                </div>

                <?php if (!empty($opening_hours) || !empty($description_preview) || !empty($amenities) || !empty($price_info)) : ?>
                    <div class="db-detail-section">
                        <h2>Informace pro návštěvníky</h2>
                        <div class="db-detail-info-grid">
                            <?php if (!empty($opening_hours)) : ?>
                                <div>
                                    <span class="label">Otevírací doba</span>
                                    <p class="value value-multiline"><?php echo is_array($opening_hours) ? esc_html(implode(', ', $opening_hours)) : esc_html($opening_hours); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($description_preview)) : ?>
                                <div>
                                    <span class="label">Popis</span>
                                    <p class="value value-multiline"><?php echo esc_html($description_preview); ?></p>
                                </div>
                            <?php endif; ?>
                            <?php if (!empty($amenities)) : ?>
                                <div>
                                    <span class="label">Služby v areálu</span>
                                    <div class="db-detail-tags">
                                        <?php foreach ($amenities as $amenity) :
                                            if (is_array($amenity)) {
                                                $amenity = $amenity['name'] ?? '';
                                            }
                                            if (empty($amenity)) continue;
                                            ?>
                                            <span class="db-detail-tag"><?php echo esc_html($amenity); ?></span>
                                        <?php endforeach; ?>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php
                // Zobrazit plný obsah postu (pokud existuje)
                // Toto umožňuje editorům přidat detailní texty, shortcodes atd.
                $post_content = get_post_field('post_content', $post_id);
                if (!empty($post_content) && trim($post_content) !== '') :
                    ?>
                    <div class="db-detail-section db-detail-content">
                        <h2>Detailní informace</h2>
                        <div class="db-detail-content-body">
                            <?php
                            // Použít the_content() pro správné zpracování shortcodes a formátování
                            // Musíme nastavit globální $post pro správné fungování the_content()
                            global $post;
                            $original_post = $post;
                            $post = get_post($post_id);
                            setup_postdata($post);
                            the_content();
                            wp_reset_postdata();
                            $post = $original_post;
                            ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($phone) || !empty($website) || !empty($email)) : ?>
                    <div class="db-detail-section">
                        <h2>Kontakt</h2>
                        <div class="db-detail-contact">
                            <?php if (!empty($phone)) : ?>
                                <a class="db-detail-contact-item" href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', $phone)); ?>">
                                    <i class="db-icon-phone"></i><span><?php echo esc_html($phone); ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($website)) : ?>
                                <a class="db-detail-contact-item" target="_blank" rel="noopener" href="<?php echo esc_url($website); ?>">
                                    <i class="db-icon-link"></i><span><?php echo esc_html(preg_replace('/^https?:\/\//', '', $website)); ?></span>
                                </a>
                            <?php endif; ?>
                            <?php if (!empty($email)) : ?>
                                <a class="db-detail-contact-item" href="mailto:<?php echo esc_attr($email); ?>">
                                    <i class="db-icon-mail"></i><span><?php echo esc_html($email); ?></span>
                                </a>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if ($lic_id || $lic_name || $lic_url || $attrib) : ?>
                    <div class="db-detail-section db-detail-license">
                        <h2>Licence dat</h2>
                        <div class="db-detail-license-box">
                            <div class="db-detail-license-row">
                                <?php if ($lic_name || $lic_id) : ?>
                                    <span><?php echo esc_html($lic_name ?: $lic_id); ?></span>
                                <?php endif; ?>
                                <?php if ($lic_url) : ?>
                                    <a href="<?php echo esc_url($lic_url); ?>" target="_blank" rel="noopener">Odkaz</a>
                                <?php endif; ?>
                            </div>
                            <?php if ($attrib) : ?>
                                <p class="db-detail-license-note"><?php echo esc_html($attrib); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </section>

        <section class="db-detail-right">
            <div class="db-detail-map-card">
                <div class="db-detail-map-header">
                    <h2>Mapa a dostupnost</h2>
                    <p>Zobrazení izochron a zajímavých míst v okolí</p>
                </div>
                <div id="db-detail-map"
                     data-lat="<?php echo esc_attr($lat); ?>"
                     data-lng="<?php echo esc_attr($lng); ?>"
                     data-title="<?php echo esc_attr($title); ?>"></div>
            </div>
            <div class="db-detail-nearby">
                <div class="db-detail-nearby-header">
                    <h2>V okolí</h2>
                    <p>Pěší vzdálenosti k zajímavým místům</p>
                </div>
                <div id="db-detail-nearby-list" class="db-detail-nearby-list">
                    <div class="db-detail-placeholder">Načítám okolní body…</div>
                </div>
            </div>
        </section>
    </div>
</main>

<link rel="stylesheet" href="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.css'); ?>">
<script>
window.DBDetail = <?php echo wp_json_encode(array(
    'postId'    => (int) $post_id,
    'title'     => $title,
    'lat'       => $lat !== '' ? (float) $lat : null,
    'lng'       => $lng !== '' ? (float) $lng : null,
    'restNonce' => $rest_nonce,
    'restUrl'   => esc_url_raw(rest_url('db/v1/')),
)); ?>;
</script>
<script src="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.js'); ?>"></script>

<?php get_footer(); ?>

