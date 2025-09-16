<?php
/**
 * Single template: poi
 */
if (!defined('ABSPATH')) exit;
get_header();

$post_id = get_the_ID();
$lat = get_post_meta($post_id, '_poi_lat', true);
$lng = get_post_meta($post_id, '_poi_lng', true);
$address = get_post_meta($post_id, '_poi_address', true);
$phone = get_post_meta($post_id, '_poi_phone', true);
$website = get_post_meta($post_id, '_poi_website', true);
$rating = get_post_meta($post_id, '_poi_rating', true);
$user_count = get_post_meta($post_id, '_poi_user_rating_count', true);
$price_level = get_post_meta($post_id, '_poi_price_level', true);
$opening_hours_json = get_post_meta($post_id, '_poi_opening_hours', true);
$opening_hours = $opening_hours_json ? json_decode($opening_hours_json, true) : null;
$types = wp_get_post_terms($post_id, 'poi_type', array('fields'=>'names'));

$featured = '';
if (has_post_thumbnail($post_id)) $featured = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');

?>
<main class="db-single db-single-poi" data-db-feedback="single.poi">
    <section class="db-hero">
        <div class="db-hero-text">
            <h1><?php echo esc_html(get_the_title()); ?></h1>
            <div class="db-hero-sub">
                <span class="db-badge db-badge-poi">POI</span>
                <?php if (!empty($types)): ?>
                    <span class="db-info"><?php echo esc_html(implode(' • ', $types)); ?></span>
                <?php endif; ?>
                <?php if ($rating): ?><span class="db-info"><i class="db-icon-star"></i><?php echo esc_html(number_format_i18n(floatval($rating), 1)); ?><?php if ($user_count){ echo ' ('.esc_html(intval($user_count)).')'; } ?></span><?php endif; ?>
                <?php if ($price_level): ?><span class="db-info"><i class="db-icon-price"></i><?php echo esc_html($price_level); ?></span><?php endif; ?>
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
                <?php if ($phone): ?><a class="db-btn db-btn-outline" href="tel:<?php echo esc_attr(preg_replace('/\s+/', '', $phone)); ?>"><i class="db-icon-phone"></i>Zavolat</a><?php endif; ?>
                <?php if ($website): ?><a class="db-btn db-btn-outline" target="_blank" href="<?php echo esc_url($website); ?>"><i class="db-icon-website"></i>Web</a><?php endif; ?>
            </div>
        </div>
        <div class="db-hero-media">
            <?php if ($featured): ?>
                <img src="<?php echo esc_url($featured); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" />
            <?php else: ?>
                <div class="db-placeholder-image">
                    <i class="db-icon-poi"></i>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <?php if ($opening_hours && isset($opening_hours['weekdayDescriptions']) && is_array($opening_hours['weekdayDescriptions'])): ?>
    <section class="db-section" data-db-feedback="single.poi.content">
        <h2><i class="db-icon-clock"></i>Otevírací doba</h2>
        <div class="db-opening-hours">
            <?php foreach ($opening_hours['weekdayDescriptions'] as $day): ?>
                <div class="db-oh-row"><?php echo esc_html($day); ?></div>
            <?php endforeach; ?>
        </div>
    </section>
    <?php endif; ?>

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
</main>

<link rel="stylesheet" href="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.css'); ?>">

<script src="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.js'); ?>"></script>

<?php get_footer(); ?>

