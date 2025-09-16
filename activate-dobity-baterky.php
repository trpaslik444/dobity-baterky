<?php
require_once('wp-load.php');

echo "<h1>Aktivace pluginu Dobitý Baterky</h1>\n";

// Získat aktivní pluginy
$active_plugins = get_option('active_plugins', array());
echo "<h2>Původní aktivní pluginy:</h2>\n";
echo "<ul>\n";
foreach ($active_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Přidat plugin Dobitý Baterky
$dobity_plugin = 'dobity-baterky/dobity-baterky.php';
if (!in_array($dobity_plugin, $active_plugins)) {
    $active_plugins[] = $dobity_plugin;
    update_option('active_plugins', $active_plugins);
    echo "<p style='color: green;'>✅ Plugin Dobitý Baterky byl přidán</p>\n";
} else {
    echo "<p style='color: orange;'>⚠️ Plugin Dobitý Baterky už je aktivní</p>\n";
}

// Zkontrolovat znovu
$active_plugins = get_option('active_plugins', array());
echo "<h2>Nové aktivní pluginy:</h2>\n";
echo "<ul>\n";
foreach ($active_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Zkontrolovat, zda je plugin aktivní
if (in_array($dobity_plugin, $active_plugins)) {
    echo "<p style='color: green;'>✅ Plugin Dobitý Baterky je nyní aktivní</p>\n";
} else {
    echo "<p style='color: red;'>❌ Plugin Dobitý Baterky stále není aktivní</p>\n";
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

echo "<h2>Aktivace dokončena</h2>\n";
echo "<p><a href='/wp-admin/'>Zobrazit WordPress admin</a></p>\n";
?>
