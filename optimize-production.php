<?php
/**
 * Script pro optimalizaci produkčního prostředí
 * Spustit: php optimize-production.php
 */

// Načtení WordPress
require_once dirname(__FILE__) . '/../../../wp-load.php';

use DB\Database_Optimizer;

echo "=== Optimalizace produkčního prostředí ===\n\n";

// 1. Vytvoření databázových indexů
echo "1. Vytváření databázových indexů...\n";
$result = Database_Optimizer::create_indexes();

if (!empty($result['errors'])) {
    echo "Chyby při vytváření indexů:\n";
    foreach ($result['errors'] as $error) {
        echo "  - $error\n";
    }
} else {
    echo "✓ Vytvořeno {$result['indexes_created']} indexů\n";
}

// 2. Vyčištění starého cache
echo "\n2. Čištění starého cache...\n";
$deleted = Database_Optimizer::cleanup_old_cache();
echo "✓ Smazáno $deleted záznamů z cache\n";

// 3. Statistiky výkonu
echo "\n3. Statistiky výkonu:\n";
$stats = Database_Optimizer::get_performance_stats();
echo "  - Cache záznamy: {$stats['cache_records']}\n";
echo "  - Cache velikost: {$stats['cache_size']} MB\n";
echo "  - Meta záznamy: {$stats['meta_records']}\n";

// 4. Vymazání všech cache
echo "\n4. Vymazání všech cache...\n";
wp_cache_flush();
echo "✓ Všechny cache vymazány\n";

// 5. Optimalizace databáze
echo "\n5. Optimalizace databáze...\n";
global $wpdb;
$wpdb->query("OPTIMIZE TABLE {$wpdb->posts}");
$wpdb->query("OPTIMIZE TABLE {$wpdb->postmeta}");
echo "✓ Databáze optimalizována\n";

echo "\n=== Optimalizace dokončena ===\n";
echo "Doporučení:\n";
echo "- Monitorujte výkon pomocí: wp db optimize stats\n";
echo "- Pravidelně čistěte cache: wp db optimize cleanup\n";
echo "- V případě problémů zkontrolujte logy\n";
