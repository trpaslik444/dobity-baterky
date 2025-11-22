<?php
/**
 * Vytvoří nový CSV soubor od zadaného řádku
 * 
 * Použití:
 *   php scripts/split-csv-from-row.php input.csv output.csv 6001
 */

if ($argc < 4) {
    fwrite(STDERR, "Použití: php split-csv-from-row.php <input.csv> <output.csv> <start_row>\n");
    fwrite(STDERR, "  start_row: od kterého řádku začít (1 = první řádek dat, 2 = druhý řádek dat, atd.)\n");
    exit(1);
}

$inputFile = $argv[1];
$outputFile = $argv[2];
$startRow = (int)$argv[3];

if (!file_exists($inputFile)) {
    fwrite(STDERR, "❌ CHYBA: Vstupní soubor '$inputFile' neexistuje.\n");
    exit(2);
}

if ($startRow < 1) {
    fwrite(STDERR, "❌ CHYBA: start_row musí být >= 1\n");
    exit(3);
}

$inputHandle = fopen($inputFile, 'r');
if (!$inputHandle) {
    fwrite(STDERR, "❌ CHYBA: Nelze otevřít vstupní soubor.\n");
    exit(4);
}

$outputHandle = fopen($outputFile, 'w');
if (!$outputHandle) {
    fclose($inputHandle);
    fwrite(STDERR, "❌ CHYBA: Nelze vytvořit výstupní soubor.\n");
    exit(5);
}

// Zkopírovat hlavičku
$header = fgetcsv($inputHandle, 0, ',', '"', '\\');
if ($header === false) {
    fclose($inputHandle);
    fclose($outputHandle);
    fwrite(STDERR, "❌ CHYBA: Nelze načíst hlavičku CSV.\n");
    exit(6);
}

fputcsv($outputHandle, $header, ',', '"', '\\');

// Přeskočit řádky před start_row
$currentRow = 1;
$skipped = 0;
$copied = 0;

while (($data = fgetcsv($inputHandle, 0, ',', '"', '\\')) !== false) {
    if ($currentRow < $startRow) {
        $skipped++;
    } else {
        fputcsv($outputHandle, $data, ',', '"', '\\');
        $copied++;
    }
    $currentRow++;
}

fclose($inputHandle);
fclose($outputHandle);

echo "✅ Hotovo!\n";
echo "   Přeskočeno řádků: $skipped\n";
echo "   Zkopírováno řádků: $copied\n";
echo "   Výstupní soubor: $outputFile\n";

exit(0);

