<?php
/**
 * Vyčištění duplicitních pluginů z databáze
 */

// Načíst WordPress
require_once('wp-load.php');

echo "<h1>Vyčištění duplicitních pluginů</h1>\n";

// Získat aktivní pluginy
$active_plugins = get_option('active_plugins', array());
echo "<h2>Původní aktivní pluginy:</h2>\n";
echo "<ul>\n";
foreach ($active_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Najít všechny pluginy s "dobity-baterky" v názvu
$dobity_plugins = array();
foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'dobity-baterky') !== false) {
        $dobity_plugins[] = $plugin;
    }
}

echo "<h2>Dobitý Baterky pluginy:</h2>\n";
echo "<ul>\n";
foreach ($dobity_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Nechat jen jeden plugin (ten správný)
$correct_plugin = 'dobity-baterky/dobity-baterky.php';
$new_active_plugins = array();

foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'dobity-baterky') !== false) {
        // Pokud je to správný plugin, přidat ho
        if ($plugin === $correct_plugin) {
            $new_active_plugins[] = $plugin;
            echo "<p style='color: green;'>✅ Zachovávám: {$plugin}</p>\n";
        } else {
            echo "<p style='color: orange;'>⚠️ Odstraňuji: {$plugin}</p>\n";
        }
    } else {
        // Zachovat ostatní pluginy
        $new_active_plugins[] = $plugin;
    }
}

// Aktualizovat databázi
update_option('active_plugins', $new_active_plugins);

echo "<h2>Nové aktivní pluginy:</h2>\n";
echo "<ul>\n";
foreach ($new_active_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Zkontrolovat, zda je plugin aktivní
if (in_array($correct_plugin, $new_active_plugins)) {
    echo "<p style='color: green;'>✅ Plugin Dobitý Baterky je aktivní</p>\n";
} else {
    echo "<p style='color: red;'>❌ Plugin Dobitý Baterky není aktivní</p>\n";
}

// Zkontrolovat, zda jsou třídy dostupné
if (class_exists('DB\CPT')) {
    echo "<p style='color: green;'>✅ Třída CPT je dostupná</p>\n";
} else {
    echo "<p style='color: red;'>❌ Třída CPT není dostupná</p>\n";
}

if (class_exists('DB\Admin\Nearby_Queue_Admin')) {
    echo "<p style='color: green;'>✅ Třída Nearby_Queue_Admin je dostupná</p>\n";
} else {
    echo "<p style='color: red;'>❌ Třída Nearby_Queue_Admin není dostupná</p>\n";
}

echo "<h2>Vyčištění dokončeno</h2>\n";
echo "<p><a href='/wp-admin/plugins.php'>Zobrazit seznam pluginů</a></p>\n";
?>