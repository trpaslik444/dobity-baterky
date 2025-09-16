<?php
/**
 * Script pro vyčištění nearby cache s novým efektivnějším přístupem
 * Spustit přes: https://vase-domena.cz/wp-content/plugins/dobity-baterky/clear-cache-new-approach.php
 */

// Načtení WordPress prostředí
require_once('../../../wp-load.php');

if (!current_user_can('manage_options')) {
    die('Nedostatečná oprávnění');
}

echo "<h2>Vyčištění Nearby Cache - Nový Efektivní Přístup</h2>";

// Získání všech postů s nearby cache
$meta_keys = ['_db_nearby_cache_poi_foot', '_db_nearby_cache_charger_foot'];
$cleared_count = 0;

foreach ($meta_keys as $meta_key) {
    echo "<h3>Vyčištění cache: {$meta_key}</h3>";
    
    // Najít všechny posty s touto meta
    $posts = get_posts([
        'post_type' => ['poi', 'charging_location', 'rv_spot'],
        'meta_key' => $meta_key,
        'posts_per_page' => -1,
        'fields' => 'ids'
    ]);
    
    echo "<p>Nalezeno " . count($posts) . " postů s cache</p>";
    
    foreach ($posts as $post_id) {
        delete_post_meta($post_id, $meta_key);
        $cleared_count++;
    }
    
    echo "<p>✅ Cache vyčištěna pro " . count($posts) . " postů</p>";
}

echo "<h3>Shrnutí</h3>";
echo "<p>✅ Celkem vyčištěno: {$cleared_count} cache záznamů</p>";
echo "<p>🔄 Nový přístup:</p>";
echo "<ul>";
echo "<li>Cache obsahuje pouze ID a vzdálenosti</li>";
echo "<li>Ikony a metadata se načítají dynamicky</li>";
echo "<li>Stejná logika jako pro mapu</li>";
echo "<li>Menší cache, vždy aktuální data</li>";
echo "</ul>";

echo "<p><strong>Nyní můžete otestovat nearby funkce - data se načtou s novým efektivnějším přístupem!</strong></p>";
?>
