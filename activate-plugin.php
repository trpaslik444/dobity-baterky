<?php
/**
 * Aktivace pluginu Dobitý Baterky
 */

// Načíst WordPress
require_once('wp-load.php');

echo "<h1>Aktivace pluginu Dobitý Baterky</h1>\n";

// Zkontrolovat, zda je plugin načten
if (class_exists('DB\CPT')) {
    echo "<p style='color: green;'>✅ Plugin je načten</p>\n";
} else {
    echo "<p style='color: red;'>❌ Plugin není načten</p>\n";
}

// Zkontrolovat aktivní pluginy
$active_plugins = get_option('active_plugins', array());
echo "<p><strong>Aktivní pluginy:</strong> " . count($active_plugins) . "</p>\n";

// Zkontrolovat, zda je plugin aktivní
$plugin_file = 'dobity-baterky/dobity-baterky.php';
if (in_array($plugin_file, $active_plugins)) {
    echo "<p style='color: green;'>✅ Plugin je aktivní</p>\n";
} else {
    echo "<p style='color: orange;'>⚠️ Plugin není aktivní, aktivuji...</p>\n";
    
    // Aktivovat plugin
    $active_plugins[] = $plugin_file;
    update_option('active_plugins', $active_plugins);
    
    echo "<p style='color: green;'>✅ Plugin aktivován</p>\n";
}

// Zkontrolovat znovu
$active_plugins = get_option('active_plugins', array());
if (in_array($plugin_file, $active_plugins)) {
    echo "<p style='color: green;'>✅ Plugin je nyní aktivní</p>\n";
} else {
    echo "<p style='color: red;'>❌ Plugin se nepodařilo aktivovat</p>\n";
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
