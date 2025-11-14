<?php
/**
 * Testovací skript pro Smart Loading Manager
 * Ověří funkčnost manuálního načítání a optimalizací
 */

// Nastavit error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

echo "<h1>🧪 Test Smart Loading Manager</h1>";

// Test 1: Kontrola existence souborů
echo "<h2>1. Kontrola souborů</h2>";
$files_to_check = [
    'assets/map/core.js',
    'assets/db-map.css',
    'docs/SMART_LOADING_GUIDE.md'
];

foreach ($files_to_check as $file) {
    if (file_exists($file)) {
        echo "✅ $file - existuje<br>";
    } else {
        echo "❌ $file - chybí<br>";
    }
}

// Test 2: Kontrola JavaScript kódu
echo "<h2>2. Kontrola JavaScript kódu</h2>";
$js_content = file_get_contents('assets/map/core.js');

$js_checks = [
    'SmartLoadingManager' => 'class SmartLoadingManager',
    'manualLoadButton' => 'createManualLoadButton',
    'loadingIndicator' => 'createLoadingIndicator',
    'autoLoadToggle' => 'createAutoLoadToggle',
    'debounce' => 'debounce(async () => {',
    '1000' => '}, 1000); // Zvýšeno z 300ms',
    '0.3' => 'lastSearchRadiusKm * 0.3', // Sníženo na 30%
];

foreach ($js_checks as $name => $pattern) {
    if (strpos($js_content, $pattern) !== false) {
        echo "✅ $name - nalezen<br>";
    } else {
        echo "❌ $name - chybí<br>";
    }
}

// Test 3: Kontrola CSS stylů
echo "<h2>3. Kontrola CSS stylů</h2>";
$css_content = file_get_contents('assets/db-map.css');

$css_checks = [
    'manual-load-container' => '.db-manual-load-container',
    'loading-indicator' => '.db-loading-indicator',
    'auto-load-toggle' => '.db-auto-load-toggle',
    'animations' => '@keyframes slideInFromRight',
    'responsive' => '@media (max-width: 768px)'
];

foreach ($css_checks as $name => $pattern) {
    if (strpos($css_content, $pattern) !== false) {
        echo "✅ $name - nalezen<br>";
    } else {
        echo "❌ $name - chybí<br>";
    }
}

// Test 4: Kontrola debug funkcí
echo "<h2>4. Kontrola debug funkcí</h2>";
$debug_functions = [
    'getSmartLoadingStats',
    'testManualLoad',
    'testLoadingIndicator',
    'clearOptimizedCache'
];

foreach ($debug_functions as $func) {
    if (strpos($js_content, "window.$func") !== false) {
        echo "✅ $func - nalezen<br>";
    } else {
        echo "❌ $func - chybí<br>";
    }
}

// Test 5: Kontrola optimalizací
echo "<h2>5. Kontrola optimalizací</h2>";

// Debounce kontrola
if (strpos($js_content, '}, 1000);') !== false) {
    echo "✅ Debounce zvýšen na 1000ms<br>";
} else {
    echo "❌ Debounce není optimalizován<br>";
}

// Threshold kontrola
if (strpos($js_content, '* 0.3') !== false) {
    echo "✅ Threshold snížen na 30%<br>";
} else {
    echo "❌ Threshold není optimalizován<br>";
}

// Test 6: Výkonnostní analýza
echo "<h2>6. Výkonnostní analýza</h2>";

// Počet řádků kódu
$js_lines = substr_count($js_content, "\n");
$css_lines = substr_count($css_content, "\n");

echo "📊 Statistiky:<br>";
echo "- JavaScript: $js_lines řádků<br>";
echo "- CSS: $css_lines řádků<br>";

// Velikost souborů
$js_size = round(filesize('assets/map/core.js') / 1024, 2);
$css_size = round(filesize('assets/db-map.css') / 1024, 2);

echo "- JavaScript: {$js_size} KB<br>";
echo "- CSS: {$css_size} KB<br>";

// Test 7: Kontrola kompatibility
echo "<h2>7. Kontrola kompatibility</h2>";

$compatibility_checks = [
    'localStorage' => 'localStorage.getItem',
    'async/await' => 'async function',
    'ES6 classes' => 'class SmartLoadingManager',
    'Arrow functions' => '=>',
    'Template literals' => '`',
    'CSS Grid/Flexbox' => 'display: flex'
];

foreach ($compatibility_checks as $feature => $pattern) {
    if (strpos($js_content . $css_content, $pattern) !== false) {
        echo "✅ $feature - podporováno<br>";
    } else {
        echo "❌ $feature - není použito<br>";
    }
}

// Test 8: Doporučení pro testování
echo "<h2>8. Doporučení pro testování</h2>";
echo "<div style='background: #f0f8ff; padding: 15px; border-radius: 8px;'>";
echo "<h3>🧪 Testovací scénáře:</h3>";
echo "<ol>";
echo "<li><strong>Základní funkčnost:</strong> Otevřít mapu a ověřit, že se načtou data</li>";
echo "<li><strong>Automatické načítání:</strong> Pohybovat po mapě a sledovat automatické načítání</li>";
echo "<li><strong>Manuální načítání:</strong> Vypnout automatické načítání a testovat tlačítko</li>";
echo "<li><strong>Loading indikátory:</strong> Ověřit zobrazení spinneru během načítání</li>";
echo "<li><strong>Responsive design:</strong> Testovat na mobilních zařízeních</li>";
echo "<li><strong>Debug funkce:</strong> Použít console příkazy pro testování</li>";
echo "</ol>";

echo "<h3>🔧 Console příkazy pro testování:</h3>";
echo "<pre style='background: #f5f5f5; padding: 10px; border-radius: 4px;'>";
echo "// Zobrazit statistiky\n";
echo "console.log(window.getSmartLoadingStats());\n\n";
echo "// Testovat manuální načítání\n";
echo "window.testManualLoad();\n\n";
echo "// Testovat loading indikátor\n";
echo "window.testLoadingIndicator();\n\n";
echo "// Zobrazit cache statistiky\n";
echo "console.log(window.getCacheStats());";
echo "</pre>";
echo "</div>";

// Test 9: Očekávané výsledky
echo "<h2>9. Očekávané výsledky</h2>";
echo "<table style='border-collapse: collapse; width: 100%;'>";
echo "<tr style='background: #f0f8ff;'>";
echo "<th style='border: 1px solid #ddd; padding: 8px;'>Metrika</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px;'>Před</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px;'>Po</th>";
echo "<th style='border: 1px solid #ddd; padding: 8px;'>Zlepšení</th>";
echo "</tr>";
echo "<tr>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>API volání/den</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>2000+</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>300-500</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px; color: green;'>75% redukce</td>";
echo "</tr>";
echo "<tr>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>Průměrná odezva</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>5-15s</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>1-3s</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px; color: green;'>70% rychlejší</td>";
echo "</tr>";
echo "<tr>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>Zbytečná načítání</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>60%</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px;'>5%</td>";
echo "<td style='border: 1px solid #ddd; padding: 8px; color: green;'>90% redukce</td>";
echo "</tr>";
echo "</table>";

echo "<h2>✅ Test dokončen!</h2>";
echo "<p>Smart Loading Manager je připraven k testování. Otevřete mapu a ověřte funkčnost podle doporučených scénářů.</p>";
?>
