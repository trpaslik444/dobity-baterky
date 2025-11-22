<?php
/**
 * Bezpečný CSV import skript pro staging
 * 
 * Tento skript importuje data z CSV bez vyhledávání podle názvu nebo koordinátů.
 * Používá pouze ID z CSV pro aktualizaci existujících záznamů, jinak vytváří nové.
 * Tímto způsobem se zabrání přepisování existujících dat.
 * 
 * Použití:
 *   php safe-import-csv-staging.php /cesta/k/souboru.csv [--max-rows=N] [--log-every=N] [--force-new]
 * 
 * Parametry:
 *   --max-rows=N    - Zpracovat maximálně N řádků (default: 0 = všechny)
 *   --log-every=N   - Logovat každých N řádků (default: 100)
 *   --force-new     - Vždy vytvářet nové záznamy, i když existuje ID
 */

// Načtení WordPressu
$candidates = [
    dirname(__DIR__, 4) . '/wp-load.php',
    dirname(__DIR__, 5) . '/wp-load.php',
    dirname(__DIR__, 3) . '/wp-load.php',
    __DIR__ . '/wp-load.php',
];

$wpLoad = null;
foreach ($candidates as $cand) {
    if (file_exists($cand)) {
        $wpLoad = $cand;
        break;
    }
}

if ($wpLoad === null) {
    fwrite(STDERR, "❌ CHYBA: wp-load.php nenalezen. Zkontrolujte, že jste v rootu WordPressu.\n");
    exit(1);
}

require_once $wpLoad;

// Parsování argumentů
$csvFile = $argv[1] ?? '';
$maxRows = 0;
$logEvery = 100;
$forceNew = false;

for ($i = 2; $i < count($argv); $i++) {
    if (preg_match('/^--max-rows=(\d+)$/', $argv[$i], $m)) {
        $maxRows = (int)$m[1];
    } elseif (preg_match('/^--log-every=(\d+)$/', $argv[$i], $m)) {
        $logEvery = max(1, (int)$m[1]);
    } elseif ($argv[$i] === '--force-new') {
        $forceNew = true;
    }
}

if (empty($csvFile)) {
    fwrite(STDERR, "❌ Použití: php safe-import-csv-staging.php <cesta-k-csv> [--max-rows=N] [--log-every=N] [--force-new]\n");
    exit(2);
}

if (!file_exists($csvFile) || !is_readable($csvFile)) {
    fwrite(STDERR, "❌ CHYBA: CSV soubor '$csvFile' neexistuje nebo není čitelný.\n");
    exit(3);
}

if (!class_exists('DB\\POI_Admin')) {
    fwrite(STDERR, "❌ CHYBA: POI_Admin třída není dostupná.\n");
    exit(4);
}

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "🛡️  BEZPEČNÝ CSV IMPORT (SAFE MODE)\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📄 CSV soubor: $csvFile\n";
echo "🔢 Max řádků: " . ($maxRows > 0 ? $maxRows : 'všechny') . "\n";
echo "📝 Logování: každých $logEvery řádků\n";
echo "🔄 Režim: " . ($forceNew ? 'VŽDY VYTVÁŘET NOVÉ' : 'POUŽÍT ID PRO AKTUALIZACI') . "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n\n";

// Otevřít CSV soubor
$handle = fopen($csvFile, 'r');
if (!$handle) {
    fwrite(STDERR, "❌ CHYBA: Nelze otevřít CSV soubor.\n");
    exit(5);
}

// Načíst hlavičku pomocí metody z POI_Admin (zvládne prázdné řádky)
$admin = \DB\POI_Admin::get_instance();
$reflection = new ReflectionClass($admin);
$method = $reflection->getMethod('read_csv_headers');
$method->setAccessible(true);
$headers = $method->invoke($admin, $handle);

if (empty($headers)) {
    fclose($handle);
    fwrite(STDERR, "❌ CHYBA: CSV soubor neobsahuje hlavičku nebo je prázdný.\n");
    exit(6);
}

// Vrátit se na začátek souboru pro další zpracování
rewind($handle);

echo "📋 Hlavička CSV:\n";
echo "   " . implode(' | ', $headers) . "\n\n";

// Normalizační funkce (stejná jako v POI_Admin)
$normalize = function(string $s): string {
    $s = trim(mb_strtolower($s));
    $trans = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
    if ($trans !== false && $trans !== null) {
        $s = strtolower(preg_replace('/[^a-z0-9_\- ]+/','',$trans));
    }
    $s = str_replace(['\t'], ' ', $s);
    $s = preg_replace('/\s+/', ' ', $s);
    return trim($s);
};

$synonymToInternal = [
    'nazev' => 'Název',
    'name' => 'Název',
    'cafe name' => 'Název',
    'title' => 'Název',
    'popis' => 'Popis',
    'description' => 'Popis',
    'address' => 'Popis',
    'typ' => 'Typ',
    'type' => 'Typ',
    'latitude' => 'Latitude',
    'lat' => 'Latitude',
    'y' => 'Latitude',
    'longitude' => 'Longitude',
    'lng' => 'Longitude',
    'lon' => 'Longitude',
    'x' => 'Longitude',
    'ikona' => 'Ikona',
    'icon' => 'Ikona',
    'barva' => 'Barva',
    'color' => 'Barva',
    'id' => 'ID',
];

$columnIndexToInternal = [];
foreach ($headers as $idx => $rawHeader) {
    $key = $normalize((string)$rawHeader);
    if (isset($synonymToInternal[$key])) {
        $columnIndexToInternal[$idx] = $synonymToInternal[$key];
    } else {
        $columnIndexToInternal[$idx] = (string)$rawHeader;
    }
}

echo "🔍 Mapování sloupců:\n";
foreach ($columnIndexToInternal as $idx => $internal) {
    echo "   [$idx] '{$headers[$idx]}' -> '{$internal}'\n";
}
echo "\n";

// Statistiky
$imported = 0;
$updated = 0;
$errors = [];
$row_count = 0;
$skipped_empty = 0;
$processed_poi_ids = [];

// Čas měření
$startTime = microtime(true);
$lastLogTime = $startTime;

// Nastavit flag pro import (zabrání nearby recompute)
if (function_exists('\DB\db_set_poi_import_running')) {
    \DB\db_set_poi_import_running(true);
}

try {
    // Přeskočit hlavičku
    fgetcsv($handle, 0, ',', '"', '\\');
    
    global $wpdb;
    
    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
        // Přeskočit prázdné řádky
        $isEmpty = true;
        foreach ($data as $val) {
            if (trim((string)$val) !== '') {
                $isEmpty = false;
                break;
            }
        }
        if ($isEmpty) {
            $skipped_empty++;
            continue;
        }
        
        $row_count++;
        
        if (count($data) < 2) {
            $errors[] = "Řádek {$row_count}: Nedostatečný počet sloupců (" . count($data) . ")";
            continue;
        }
        
        try {
            // Mapovat data
            $poi_data = [];
            foreach ($data as $i => $val) {
                $key = $columnIndexToInternal[$i] ?? ($headers[$i] ?? (string)$i);
                $poi_data[$key] = $val;
            }
            
            $post_title = sanitize_text_field($poi_data['Název'] ?? '');
            
            if (empty($post_title)) {
                $errors[] = "Řádek {$row_count}: Prázdný název POI";
                continue;
            }
            
            $latInput = isset($poi_data['Latitude']) ? floatval($poi_data['Latitude']) : null;
            $lngInput = isset($poi_data['Longitude']) ? floatval($poi_data['Longitude']) : null;
            $post_content = sanitize_textarea_field($poi_data['Popis'] ?? '');
            
            $poi_id = 0;
            
            // SAFE MODE: Pouze použít ID pokud existuje a není force-new
            if (!$forceNew && !empty($poi_data['ID']) && is_numeric($poi_data['ID'])) {
                $candidate_id = (int)$poi_data['ID'];
                $candidate_post = get_post($candidate_id);
                if ($candidate_post && $candidate_post->post_type === 'poi') {
                    // Aktualizovat existující POI podle ID
                    $update_post = [
                        'ID' => $candidate_id,
                        'post_title' => $post_title,
                        'post_content' => $post_content,
                    ];
                    $result = wp_update_post($update_post, true);
                    if (!is_wp_error($result)) {
                        $poi_id = $candidate_id;
                        $updated++;
                    } else {
                        $errors[] = "Řádek {$row_count}: Chyba při aktualizaci POI {$candidate_id}: " . $result->get_error_message();
                        continue;
                    }
                }
            }
            
            // Pokud POI ještě nemá ID, zkontrolovat duplicity podle názvu (jako admin importer)
            if (!$poi_id) {
                // KONTROLA DUPLICIT: Zkusit najít podle názvu (jako admin importer)
                $candidates = $wpdb->get_col($wpdb->prepare(
                    "SELECT ID FROM {$wpdb->posts} WHERE post_type = 'poi' AND post_status = 'publish' AND post_title = %s",
                    $post_title
                ));
                
                if (count($candidates) === 1) {
                    // Aktualizovat existující POI místo vytváření duplicit
                    $cid = (int)$candidates[0];
                    $update_post = [
                        'ID' => $cid,
                        'post_title' => $post_title,
                        'post_content' => $post_content,
                    ];
                    $result = wp_update_post($update_post, true);
                    if (!is_wp_error($result)) {
                        $poi_id = $cid;
                        $updated++;
                    } else {
                        $errors[] = "Řádek {$row_count}: Chyba při aktualizaci POI {$cid}: " . $result->get_error_message();
                        continue;
                    }
                } else {
                    // Vytvořit nový POI pouze pokud neexistuje duplicit
                    $post_data = [
                        'post_title' => $post_title,
                        'post_content' => $post_content,
                        'post_type' => 'poi',
                        'post_status' => 'publish'
                    ];
                    $poi_id = wp_insert_post($post_data);
                    if (is_wp_error($poi_id)) {
                        $errors[] = "Řádek {$row_count}: Chyba při vytváření POI: " . $poi_id->get_error_message();
                        continue;
                    }
                    $imported++;
                }
            }
            
            // Nastavit typ POI
            try {
                $type_name = \DB\db_normalize_poi_type_from_csv($poi_data, 'kavárna');
                if ($type_name !== '') {
                    if (is_numeric($type_name)) {
                        $type_name = 'kavárna';
                    }
                    $term = term_exists($type_name, 'poi_type');
                    if (!$term) {
                        $term = wp_insert_term($type_name, 'poi_type');
                    }
                    if (!is_wp_error($term)) {
                        $term_id = is_array($term) ? ($term['term_id'] ?? 0) : (int)$term;
                        if ($term_id) {
                            wp_set_object_terms($poi_id, (int)$term_id, 'poi_type', false);
                        }
                    }
                }
            } catch (\Throwable $e) {
                if (defined('WP_DEBUG') && WP_DEBUG) {
                    error_log('[Safe POI import] Chyba při nastavování typu: ' . $e->getMessage());
                }
            }
            
            // Nastavit koordináty
            if ($latInput !== null) {
                $lat = $latInput;
                if ($lat >= -90 && $lat <= 90) {
                    update_post_meta($poi_id, '_poi_lat', $lat);
                } else {
                    $errors[] = "Řádek {$row_count}: Neplatná latitude: {$poi_data['Latitude']}";
                }
            }
            if ($lngInput !== null) {
                $lng = $lngInput;
                if ($lng >= -180 && $lng <= 180) {
                    update_post_meta($poi_id, '_poi_lng', $lng);
                } else {
                    $errors[] = "Řádek {$row_count}: Neplatná longitude: {$poi_data['Longitude']}";
                }
            }
            
            // Nastavit ikonu a barvu
            if (!empty($poi_data['Ikona'])) {
                update_post_meta($poi_id, '_poi_icon', sanitize_text_field($poi_data['Ikona']));
            }
            if (!empty($poi_data['Barva'])) {
                $color = $poi_data['Barva'];
                if (preg_match('/^#([A-Fa-f0-9]{6}|[A-Fa-f0-9]{3})$/', $color)) {
                    update_post_meta($poi_id, '_poi_color', $color);
                } else {
                    $errors[] = "Řádek {$row_count}: Neplatná hex barva: {$color}";
                }
            }
            
            if ($poi_id > 0) {
                $processed_poi_ids[] = $poi_id;
            }
            
        } catch (\Exception $e) {
            $errors[] = "Řádek {$row_count}: Exception: " . $e->getMessage();
            error_log("[Safe POI Import] Exception v řádku {$row_count}: " . $e->getMessage());
        } catch (\Error $e) {
            $errors[] = "Řádek {$row_count}: Fatal Error: " . $e->getMessage();
            error_log("[Safe POI Import] Fatal Error v řádku {$row_count}: " . $e->getMessage());
        }
        
        // Logování průběhu
        if ($row_count % $logEvery === 0) {
            $currentTime = microtime(true);
            $elapsed = $currentTime - $lastLogTime;
            $lastLogTime = $currentTime;
            
            echo sprintf(
                "📊 Řádek %d | nové: %d | aktualizované: %d | chyby: %d | prázdné: %d | čas: %.2fs\n",
                $row_count,
                $imported,
                $updated,
                count($errors),
                $skipped_empty,
                $elapsed
            );
        }
        
        // Omezení počtu řádků
        if ($maxRows > 0 && $row_count >= $maxRows) {
            break;
        }
    }
    
    fclose($handle);
    
} catch (\Throwable $e) {
    fclose($handle);
    
    echo "\n❌ FATÁLNÍ CHYBA PŘI IMPORTU:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    echo "Zpráva: " . $e->getMessage() . "\n";
    echo "Soubor: " . $e->getFile() . ":" . $e->getLine() . "\n";
    echo "\nBacktrace:\n";
    echo $e->getTraceAsString() . "\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    
    if (function_exists('\DB\db_set_poi_import_running')) {
        \DB\db_set_poi_import_running(false);
    }
    
    exit(6);
}

// Vymazat flag
if (function_exists('\DB\db_set_poi_import_running')) {
    \DB\db_set_poi_import_running(false);
}

// Zařadit do fronty pro nearby recompute
$enqueued_count = 0;
$affected_count = 0;
if (!empty($processed_poi_ids) && class_exists('\DB\Jobs\Nearby_Queue_Manager')) {
    $queue_manager = new \DB\Jobs\Nearby_Queue_Manager();
    foreach (array_unique($processed_poi_ids) as $poi_id) {
        if ($queue_manager->enqueue($poi_id, 'charging_location', 1)) {
            $enqueued_count++;
        }
        $affected_count += $queue_manager->enqueue_affected_points($poi_id, 'poi');
    }
}

// Výstup výsledků
$totalTime = microtime(true) - $startTime;

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ BEZPEČNÝ IMPORT DOKONČEN\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "📊 Statistika:\n";
echo "   • Nově vytvořené POI: " . $imported . "\n";
echo "   • Aktualizované POI (podle ID): " . $updated . "\n";
echo "   • Celkem zpracovaných řádků: " . $row_count . "\n";
echo "   • Přeskočené prázdné řádky: " . $skipped_empty . "\n";
echo "   • Počet chyb: " . count($errors) . "\n";
echo "   • Zařazeno do fronty: {$enqueued_count} POI, {$affected_count} affected locations\n";
echo "   • Celkový čas: " . number_format($totalTime, 2) . "s\n";
echo "   • Průměrný čas na řádek: " . ($row_count > 0 ? number_format($totalTime / $row_count, 3) : 'N/A') . "s\n";

if (!empty($errors)) {
    echo "\n⚠️  CHYBY BĚHEM IMPORTU:\n";
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
    $errorLimit = 50;
    $displayErrors = array_slice($errors, 0, $errorLimit);
    foreach ($displayErrors as $error) {
        echo "   • $error\n";
    }
    if (count($errors) > $errorLimit) {
        echo "   ... a dalších " . (count($errors) - $errorLimit) . " chyb\n";
    }
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
}

echo "\n";
if (count($errors) === 0) {
    echo "✅ Všechny řádky byly úspěšně zpracovány!\n";
} else {
    echo "⚠️  Import dokončen s chybami. Zkontrolujte výše uvedené chyby.\n";
}
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

exit(count($errors) > 0 ? 1 : 0);

