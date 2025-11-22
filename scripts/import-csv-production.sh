#!/usr/bin/env bash
#
# Optimalizovaný produkční import wrapper
# Rozdělí CSV na optimální balíčky a importuje postupně
#
# Použití:
#   ./scripts/import-csv-production.sh [cesta/k/souboru.csv]
#

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Načtení environment proměnných
if [ -f "$PROJECT_ROOT/.env" ]; then
    source "$SCRIPT_DIR/load-env.sh"
fi

CSV_FILE="${1:-exported_pois_staging_complete.csv}"

# Rozšířit relativní cestu na absolutní
if [[ ! "$CSV_FILE" = /* ]]; then
    CSV_FILE="$PROJECT_ROOT/$CSV_FILE"
fi

if [ ! -f "$CSV_FILE" ]; then
    echo "❌ CHYBA: CSV soubor '$CSV_FILE' neexistuje." >&2
    exit 1
fi

if [ -z "${STAGING_PASS:-}" ]; then
    echo "❌ CHYBA: STAGING_PASS není nastaven." >&2
    echo "   Nastav ho v .env souboru nebo jako environment proměnnou:" >&2
    echo "   export STAGING_PASS='tvoje_heslo'" >&2
    exit 1
fi

# Optimální velikost balíčku (3000 řádků = ~3 minuty)
OPTIMAL_CHUNK_SIZE=3000

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "🚀 OPTIMALIZOVANÝ PRODUKČNÍ IMPORT"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📄 CSV soubor: $CSV_FILE"
echo "📦 Velikost balíčku: $OPTIMAL_CHUNK_SIZE řádků (optimalizováno)"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Spočítat řádky
TOTAL_ROWS=$(tail -n +2 "$CSV_FILE" | wc -l | xargs)
CHUNK_COUNT=$(( (TOTAL_ROWS + OPTIMAL_CHUNK_SIZE - 1) / OPTIMAL_CHUNK_SIZE ))

echo "📊 Analýza CSV:"
echo "   Celkem řádků (bez hlavičky): $TOTAL_ROWS"
echo "   Počet balíčků: $CHUNK_COUNT"
echo "   Velikost balíčku: $OPTIMAL_CHUNK_SIZE řádků"
echo "   Odhadovaný čas: ~$(( CHUNK_COUNT * 3 )) minut"
echo ""
read -p "Pokračovat s importem? (y/n) " -n 1 -r
echo
if [[ ! $REPLY =~ ^[Yy]$ ]]; then
    echo "Import zrušen."
    exit 0
fi

# Rozdělit na balíčky
echo ""
echo "🔧 Rozděluji CSV na balíčky..."
CHUNK_PREFIX="${CSV_FILE%.csv}_chunk"

php "$SCRIPT_DIR/split-csv-chunks.php" "$CSV_FILE" "$CHUNK_PREFIX" "$OPTIMAL_CHUNK_SIZE"

if [ $? -ne 0 ]; then
    echo "❌ CHYBA: Nepodařilo se rozdělit CSV na balíčky." >&2
    exit 3
fi

# Importovat každý balíček postupně
CHUNK_NUM=1
IMPORTED_CHUNKS=0
FAILED_CHUNKS=0

for chunk_file in "${CHUNK_PREFIX}"*.csv; do
    if [ ! -f "$chunk_file" ]; then
        continue
    fi
    
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "📦 Balíček $CHUNK_NUM/$CHUNK_COUNT: $(basename "$chunk_file")"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    
    export STAGING_PASS
    "$SCRIPT_DIR/import-csv-production.expect" "$chunk_file"
    
    EXIT_CODE=$?
    
    if [ $EXIT_CODE -eq 0 ]; then
        echo "✅ Balíček $CHUNK_NUM úspěšně spuštěn"
        IMPORTED_CHUNKS=$((IMPORTED_CHUNKS + 1))
        
        # Počkat chvíli před dalším balíčkem
        if [ $CHUNK_NUM -lt $CHUNK_COUNT ]; then
            echo "⏳ Čekám 10 sekund před dalším balíčkem..."
            sleep 10
        fi
    else
        echo "❌ Balíček $CHUNK_NUM selhal (exit code: $EXIT_CODE)"
        FAILED_CHUNKS=$((FAILED_CHUNKS + 1))
    fi
    
    CHUNK_NUM=$((CHUNK_NUM + 1))
done

echo ""
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "✅ IMPORT VŠECH BALÍČKŮ DOKONČEN"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "   Úspěšně spuštěno: $IMPORTED_CHUNKS/$CHUNK_COUNT balíčků"
echo "   Selhalo: $FAILED_CHUNKS balíčků"
echo ""
echo "📋 Balíčky běží v pozadí na staging serveru."
echo "   Sleduj průběh pomocí: tail -f /tmp/poi_import_*.log"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"

exit 0

