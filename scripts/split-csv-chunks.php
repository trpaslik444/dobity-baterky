<?php
/**
 * Rozdělí CSV soubor na balíčky po N řádcích
 * 
 * Použití:
 *   php scripts/split-csv-chunks.php input.csv output_prefix chunk_size
 * 
 * Příklad:
 *   php scripts/split-csv-chunks.php exported_pois_from_6001.csv exported_pois_part 5000
 *   Vytvoří: exported_pois_part1.csv, exported_pois_part2.csv, ...
 */

if ($argc < 4) {
    fwrite(STDERR, "Použití: php split-csv-chunks.php <input.csv> <output_prefix> <chunk_size>\n");
    fwrite(STDERR, "  chunk_size: počet řádků v každém balíčku\n");
    exit(1);
}

$inputFile = $argv[1];
$outputPrefix = $argv[2];
$chunkSize = (int)$argv[3];

if (!file_exists($inputFile)) {
    fwrite(STDERR, "❌ CHYBA: Vstupní soubor '$inputFile' neexistuje.\n");
    exit(2);
}

if ($chunkSize < 1) {
    fwrite(STDERR, "❌ CHYBA: chunk_size musí být >= 1\n");
    exit(3);
}

$inputHandle = fopen($inputFile, 'r');
if (!$inputHandle) {
    fwrite(STDERR, "❌ CHYBA: Nelze otevřít vstupní soubor.\n");
    exit(4);
}

// Načíst hlavičku
$header = fgetcsv($inputHandle, 0, ',', '"', '\\');
if ($header === false) {
    fclose($inputHandle);
    fwrite(STDERR, "❌ CHYBA: Nelze načíst hlavičku CSV.\n");
    exit(5);
}

$chunkNum = 1;
$currentChunk = [];
$totalRows = 0;
$totalChunks = 0;

while (($data = fgetcsv($inputHandle, 0, ',', '"', '\\')) !== false) {
    $currentChunk[] = $data;
    $totalRows++;
    
    if (count($currentChunk) >= $chunkSize) {
        // Zapsat balíček
        $outputFile = $outputPrefix . $chunkNum . '.csv';
        $outputHandle = fopen($outputFile, 'w');
        if (!$outputHandle) {
            fclose($inputHandle);
            fwrite(STDERR, "❌ CHYBA: Nelze vytvořit výstupní soubor '$outputFile'.\n");
            exit(6);
        }
        
        // Zapsat hlavičku
        fputcsv($outputHandle, $header, ',', '"', '\\');
        
        // Zapsat řádky
        foreach ($currentChunk as $row) {
            fputcsv($outputHandle, $row, ',', '"', '\\');
        }
        
        fclose($outputHandle);
        echo "✅ Vytvořen balíček $chunkNum: $outputFile (" . count($currentChunk) . " řádků)\n";
        
        $currentChunk = [];
        $chunkNum++;
        $totalChunks++;
    }
}

// Zapsat poslední balíček (pokud nějaký zbyl)
if (!empty($currentChunk)) {
    $outputFile = $outputPrefix . $chunkNum . '.csv';
    $outputHandle = fopen($outputFile, 'w');
    if (!$outputHandle) {
        fclose($inputHandle);
        fwrite(STDERR, "❌ CHYBA: Nelze vytvořit výstupní soubor '$outputFile'.\n");
        exit(6);
    }
    
    // Zapsat hlavičku
    fputcsv($outputHandle, $header, ',', '"', '\\');
    
    // Zapsat řádky
    foreach ($currentChunk as $row) {
        fputcsv($outputHandle, $row, ',', '"', '\\');
    }
    
    fclose($outputHandle);
    echo "✅ Vytvořen balíček $chunkNum: $outputFile (" . count($currentChunk) . " řádků)\n";
    $totalChunks++;
}

fclose($inputHandle);

echo "\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "✅ HOTOVO!\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";
echo "   Celkem řádků: $totalRows\n";
echo "   Vytvořeno balíčků: $totalChunks\n";
echo "   Velikost balíčku: $chunkSize řádků\n";
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━\n";

exit(0);

