<?php
require_once('wp-load.php');

echo "=== VYČIŠTĚNÍ DUPLICITNÍCH PLUGINŮ ===\n\n";

// Získat aktivní pluginy
$active_plugins = get_option('active_plugins', array());
echo "Původní aktivní pluginy:\n";
foreach ($active_plugins as $plugin) {
    echo "- {$plugin}\n";
}

// Najít všechny pluginy s "dobity-baterky" v názvu
$dobity_plugins = array();
foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'dobity-baterky') !== false) {
        $dobity_plugins[] = $plugin;
    }
}

echo "\nDobitý Baterky pluginy:\n";
foreach ($dobity_plugins as $plugin) {
    echo "- {$plugin}\n";
}

// Nechat jen jeden plugin (ten správný)
$correct_plugin = 'dobity-baterky/dobity-baterky.php';
$new_active_plugins = array();

foreach ($active_plugins as $plugin) {
    if (strpos($plugin, 'dobity-baterky') !== false) {
        if ($plugin === $correct_plugin) {
            $new_active_plugins[] = $plugin;
            echo "✅ Zachovávám: {$plugin}\n";
        } else {
            echo "⚠️ Odstraňuji: {$plugin}\n";
        }
    } else {
        $new_active_plugins[] = $plugin;
    }
}

// Aktualizovat databázi
update_option('active_plugins', $new_active_plugins);

echo "\nNové aktivní pluginy:\n";
foreach ($new_active_plugins as $plugin) {
    echo "- {$plugin}\n";
}

// Zkontrolovat, zda je plugin aktivní
if (in_array($correct_plugin, $new_active_plugins)) {
    echo "\n✅ Plugin Dobitý Baterky je aktivní\n";
} else {
    echo "\n❌ Plugin Dobitý Baterky není aktivní\n";
}

// Zkontrolovat, zda jsou třídy dostupné
if (class_exists('DB\CPT')) {
    echo "✅ Třída CPT je dostupná\n";
} else {
    echo "❌ Třída CPT není dostupná\n";
}

if (class_exists('DB\Admin\Nearby_Queue_Admin')) {
    echo "✅ Třída Nearby_Queue_Admin je dostupná\n";
} else {
    echo "❌ Třída Nearby_Queue_Admin není dostupná\n";
}

echo "\n=== VYČIŠTĚNÍ DOKONČENO ===\n";
?>
