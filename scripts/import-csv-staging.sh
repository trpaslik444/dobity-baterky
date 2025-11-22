#!/usr/bin/env bash
#
# Wrapper skript pro import CSV na staging server
#
# Použití:
#   ./scripts/import-csv-staging.sh [cesta/k/souboru.csv]
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

echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📤 IMPORT CSV NA STAGING"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo "📄 CSV soubor: $CSV_FILE"
echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
echo ""

# Spustit expect skript
export STAGING_PASS
"$SCRIPT_DIR/import-csv-staging.expect" "$CSV_FILE"

exit_code=$?

if [ $exit_code -eq 0 ]; then
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "✅ IMPORT DOKONČEN"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
else
    echo ""
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
    echo "❌ IMPORT SKONČIL S CHYBOU (exit code: $exit_code)"
    echo "━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━━"
fi

exit $exit_code

