<?php
/**
 * Vynucené vyčištění duplicitních pluginů
 */

// Načíst WordPress
require_once('wp-load.php');

echo "<h1>Vynucené vyčištění duplicitních pluginů</h1>\n";

// Získat všechny možnosti s "active_plugins"
global $wpdb;
$results = $wpdb->get_results("SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE '%active_plugins%'");

echo "<h2>Všechny active_plugins záznamy:</h2>\n";
foreach ($results as $result) {
    echo "<p><strong>{$result->option_name}:</strong> {$result->option_value}</p>\n";
}

// Smazat všechny duplicitní záznamy
$wpdb->query("DELETE FROM {$wpdb->options} WHERE option_name LIKE '%active_plugins%' AND option_name != 'active_plugins'");

// Nastavit správné aktivní pluginy
$correct_plugins = array(
    'dobity-baterky/dobity-baterky.php',
    'DB-chargers-NAP/ev-data-harvester.php'
);

update_option('active_plugins', $correct_plugins);

echo "<h2>Nastaveno správné aktivní pluginy:</h2>\n";
echo "<ul>\n";
foreach ($correct_plugins as $plugin) {
    echo "<li>{$plugin}</li>\n";
}
echo "</ul>\n";

// Zkontrolovat, zda jsou pluginy aktivní
$active_plugins = get_option('active_plugins', array());
if (in_array('dobity-baterky/dobity-baterky.php', $active_plugins)) {
    echo "<p style='color: green;'>✅ Plugin Dobitý Baterky je aktivní</p>\n";
} else {
    echo "<p style='color: red;'>❌ Plugin Dobitý Baterky není aktivní</p>\n";
}

if (in_array('DB-chargers-NAP/ev-data-harvester.php', $active_plugins)) {
    echo "<p style='color: green;'>✅ Plugin DB Chargers NAP je aktivní</p>\n";
} else {
    echo "<p style='color: red;'>❌ Plugin DB Chargers NAP není aktivní</p>\n";
}

echo "<h2>Vyčištění dokončeno</h2>\n";
echo "<p><a href='/wp-admin/plugins.php'>Zobrazit seznam pluginů</a></p>\n";
?>
