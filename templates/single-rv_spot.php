<?php
/**
 * Single template: rv_spot
 */
if (!defined('ABSPATH')) exit;
get_header();

$post_id = get_the_ID();
$lat = get_post_meta($post_id, '_rv_lat', true);
$lng = get_post_meta($post_id, '_rv_lng', true);
$address = get_post_meta($post_id, '_rv_address', true);
$services = get_post_meta($post_id, '_rv_services', true);
if (!is_array($services)) $services = array();
$price = get_post_meta($post_id, '_rv_price', true);
$types = wp_get_post_terms($post_id, 'rv_type', array('fields'=>'names'));

$featured = '';
if (has_post_thumbnail($post_id)) $featured = wp_get_attachment_image_url(get_post_thumbnail_id($post_id), 'large');

?>
<main class="db-single db-single-rv" data-db-feedback="single.rv_spot">
    <section class="db-hero">
        <div class="db-hero-text">
            <h1><?php echo esc_html(get_the_title()); ?></h1>
            <div class="db-hero-sub">
                <span class="db-badge db-badge-rv">RV místo</span>
                <?php if (!empty($types)): ?>
                    <span class="db-info"><?php echo esc_html(implode(' • ', $types)); ?></span>
                <?php endif; ?>
                <?php if ($price): ?><span class="db-info"><i class="db-icon-price"></i>Cena: <?php echo esc_html($price); ?></span><?php endif; ?>
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
                <a class="db-btn db-btn-outline" href="#services">Služby</a>
            </div>
        </div>
        <div class="db-hero-media">
            <?php if ($featured): ?>
                <img src="<?php echo esc_url($featured); ?>" alt="<?php echo esc_attr(get_the_title()); ?>" />
            <?php else: ?>
                <div class="db-placeholder-image">
                    <i class="db-icon-rv"></i>
                </div>
            <?php endif; ?>
        </div>
    </section>

    <section id="services" class="db-section">
        <h2><i class="db-icon-services"></i>Služby</h2>
        <?php if (!empty($services)): ?>
        <div class="db-services-grid">
            <?php
            $labels = array(
                'voda' => 'Voda',
                'elektrina' => 'Elektřina',
                'wc' => 'WC',
                'sprcha' => 'Sprcha',
                'vylevka' => 'Výlevka',
            );
            foreach ($services as $s):
                $label = isset($labels[$s]) ? $labels[$s] : $s;
                $icon = $s;
            ?>
                <div class="db-service-card">
                    <div class="db-service-icon">
                        <i class="db-icon-<?php echo esc_attr($icon); ?>"></i>
                    </div>
                    <div class="db-service-label"><?php echo esc_html($label); ?></div>
                </div>
            <?php endforeach; ?>
        </div>
        <?php else: ?>
            <div class="db-empty-state">
                <i class="db-icon-info"></i>
                <p>Bez specifikovaných služeb.</p>
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
</main>

<link rel="stylesheet" href="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.css'); ?>">

<script src="<?php echo esc_url(DB_PLUGIN_URL . 'assets/single-templates.js'); ?>"></script>

<?php get_footer(); ?>

