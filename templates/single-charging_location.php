<?php
/**
 * Single template: charging_location
 */
if (!defined('ABSPATH')) exit;
get_header();

$post_id = get_the_ID();
$lat = get_post_meta($post_id, '_db_lat', true);
$lng = get_post_meta($post_id, '_db_lng', true);
$address = get_post_meta($post_id, '_db_address', true);
$operator = get_post_meta($post_id, '_operator', true);
$connectors = get_post_meta($post_id, '_connectors', true);
if (!is_array($connectors)) $connectors = array();
$featured = '';
if (has_post_thumbnail($post_id)) {
    $featured = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');
}
if (!$featured) $featured = get_post_meta($post_id, '_featured_image_url', true);
if (!$featured) $featured = get_post_meta($post_id, '_ocm_image_url', true);

// max power
$max_power = 0;
foreach ($connectors as $c) { $p = floatval($c['power_kw'] ?? 0); if ($p > $max_power) $max_power = $p; }

?>
<main class="db-single db-single-charger" data-db-feedback="single.charging_location">
    <section class="db-hero">
        <div class="db-hero-text">
            <h1><?php echo esc_html(get_the_title()); ?></h1>
            <div class="db-hero-sub">
                <span class="db-badge db-badge-charger">Nabíječka</span>
                <?php if ($operator): ?><span class="db-info">Operátor: <?php echo esc_html($operator); ?></span><?php endif; ?>
                <?php if ($max_power > 0): ?><span class="db-info">Max <?php echo esc_html( number_format_i18n($max_power, 0) ); ?> kW</span><?php endif; ?>
            </div>
            <div class="db-hero-meta">
                <?php if ($address): ?><div class="db-meta-item"><i class="db-icon-location"></i><?php echo esc_html($address); ?></div><?php endif; ?>
                <?php if (is_numeric($lat) && is_numeric($lng)): ?><div class="db-meta-item"><i class="db-icon-coordinates"></i><?php echo esc_html($lat); ?>, <?php echo esc_html($lng); ?></div><?php endif; ?>
            </div>
            <div class="db-hero-actions">
                <?php if (is_numeric($lat) && is_numeric($lng)): ?>
                    <div class="db-nav-dropdown">
                        <button type="button" class="db-btn db-btn-primary" id="db-nav-btn">
                            <i class="db-icon-navigation"></i>Navigovat
                        </button>
                        <div id="db-nav-menu" class="db-nav-menu">
                            <a class="db-nav-item" target="_blank" rel="noopener" href="https://www.google.com/maps/dir/?api=1&destination=<?php echo rawurlencode($lat . ',' . $lng); ?>">
                                <i class="db-icon-google"></i>Google Maps
                            </a>
                            <a class="db-nav-item" target="_blank" rel="noopener" href="https://maps.apple.com/?daddr=<?php echo rawurlencode($lat . ',' . $lng); ?>">
                                <i class="db-icon-apple"></i>Apple Maps
                            </a>
                            <a class="db-nav-item" target="_blank" rel="noopener" href="https://mapy.cz/zakladni?source=coor&id=<?php echo rawurlencode($lng); ?>,<?php echo rawurlencode($lat); ?>&x=<?php echo rawurlencode($lng); ?>&y=<?php echo rawurlencode($lat); ?>&z=16">
                                <i class="db-icon-mapy"></i>Mapy.cz
                            </a>
                        </div>
                    </div>
                <?php endif; ?>
                <a class="db-btn db-btn-outline" href="#connectors">Konektory</a>
            </div>
        </div>
        <div class="db-hero-media">
            <?php if ($featured): ?>
                <img src="<?php echo esc_url($featured); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" />
            <?php else: ?>
                <div class="db-placeholder-image">
                    <i class="db-icon-charger"></i>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section id="connectors" class="db-section" data-db-feedback="single.charging_location.connectors">
        <h2><i class="db-icon-connector"></i>Konektory</h2>
        <?php if (!empty($connectors)): ?>
        <div class="db-connectors-grid">
            <?php foreach ($connectors as $c): ?>
                <div class="db-connector-card">
                    <div class="db-connector-header">
                        <h3><?php echo esc_html($c['type'] ?? ''); ?></h3>
                        <?php if (!empty($c['power_kw'])): ?>
                            <span class="db-power-badge"><?php echo esc_html( number_format_i18n(floatval($c['power_kw']), 0) ); ?> kW</span>
                        <?php endif; ?>
                    </div>
                    <div class="db-connector-details">
                        <?php if (!empty($c['quantity'])): ?><div class="db-detail-item"><span>Počet:</span> <?php echo esc_html(intval($c['quantity'])); ?></div><?php endif; ?>
                        <?php if (!empty($c['current_type'])): ?><div class="db-detail-item"><span>Typ proudu:</span> <?php echo esc_html($c['current_type']); ?></div><?php endif; ?>
                        <?php if (!empty($c['status'])): ?><div class="db-detail-item"><span>Stav:</span> <span class="db-status-<?php echo esc_attr(strtolower($c['status'])); ?>"><?php echo esc_html($c['status']); ?></span></div><?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="db-empty-state">
                <i class="db-icon-info"></i>
                <p>Bez detailu konektorů.</p>
            </div>
        <?php endif; ?>
    </section>

    <section class="db-section">
        <h2><i class="db-icon-description"></i>Popis</h2>
        <div class="db-content"><?php the_content(); ?></div>
    </section>

    <?php if (is_numeric($lat) && is_numeric($lng)): ?>
    <section class="db-section">
        <h2><i class="db-icon-map"></i>Mapa</h2>
        <div id="db-single-map" class="db-map-container" 
             data-lat="<?php echo esc_attr($lat); ?>" 
             data-lng="<?php echo esc_attr($lng); ?>" 
             data-title="<?php echo esc_attr(get_the_title()); ?>"></div>
    </section>
    <?php endif; ?>

    <?php
    // Licence & atribuce
    $lic_id = get_post_meta($post_id, '_license_id', true);
    $lic_name = get_post_meta($post_id, '_license_name', true);
    $lic_url = get_post_meta($post_id, '_license_url', true);
    $attrib = get_post_meta($post_id, '_attribution', true);
    if ($lic_id || $lic_name || $lic_url || $attrib): ?>
    <section class="db-section db-license-section">
        <div class="db-license-box">
            <div class="db-license-header">
                <i class="db-icon-license"></i>
                <span>Licence dat</span>
            </div>
            <div class="db-license-content">
                <?php if ($lic_name): ?>
                    <span><?php echo esc_html($lic_name); ?></span>
                <?php elseif ($lic_id): ?>
                    <span><?php echo esc_html($lic_id); ?></span>
                <?php endif; ?>
                <?php if ($lic_url): ?>
                    – <a href="<?php echo esc_url($lic_url); ?>" target="_blank" rel="noopener">odkaz</a>
                <?php endif; ?>
            </div>
            <?php if ($attrib): ?>
                <div class="db-attribution"><?php echo esc_html($attrib); ?></div>
            <?php endif; ?>
        </div>
    </section>
    <?php endif; ?>
</main>

<link rel="stylesheet" href="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.css'); ?>">

<script src="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.js'); ?>"></script>

<?php get_footer(); ?>

