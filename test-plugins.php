<?php
require_once('wp-load.php');

echo "<h1>Test pluginů</h1>\n";

// Získat aktivní pluginy
$active_plugins = get_option('active_plugins', array());
echo "<h2>Aktivní pluginy:</h2>\n";
echo "<ul>\n";
foreach ($active_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Zkontrolovat, zda je plugin aktivní
$correct_plugin = 'dobity-baterky/dobity-baterky.php';
if (in_array($correct_plugin, $active_plugins)) {
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

echo "<h2>Test dokončen</h2>\n";
?>