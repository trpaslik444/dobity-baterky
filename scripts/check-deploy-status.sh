#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

echo "🔍 Kontrola deploy stavu - Build vs Staging"
echo "=========================================="
echo ""

# Kontrola lokálního buildu
echo "📦 1. KONTROLA LOKÁLNÍHO BUILDU"
echo "--------------------------------"
BUILD_DIR="$PROJECT_ROOT/build/dobity-baterky"
LOADER_JS="$BUILD_DIR/assets/map/loader.js"

if [ ! -d "$BUILD_DIR" ]; then
  echo "❌ Build složka neexistuje: $BUILD_DIR"
  echo "   Spusť: php build-simple.php"
  echo ""
else
  echo "✅ Build složka existuje: $BUILD_DIR"
  
  if [ -f "$LOADER_JS" ]; then
    echo "✅ loader.js existuje v buildu"
    echo ""
    echo "   CACHE_BUST_TAG v build kopii:"
    grep "const CACHE_BUST_TAG" "$LOADER_JS" || echo "   ❌ CACHE_BUST_TAG nenalezen!"
    echo ""
    echo "   Datum modifikace build loader.js:"
    ls -lh "$LOADER_JS" | awk '{print "   ", $6, $7, $8, "-", $5}'
    echo ""
  else
    echo "❌ loader.js neexistuje v buildu: $LOADER_JS"
    echo ""
  fi
fi

# Kontrola zdrojového souboru
echo "📄 2. KONTROLA ZDROJOVÉHO LOADER.JS"
echo "--------------------------------"
SOURCE_LOADER="$PROJECT_ROOT/assets/map/loader.js"

if [ -f "$SOURCE_LOADER" ]; then
  echo "✅ Zdrojový loader.js existuje"
  echo ""
  echo "   CACHE_BUST_TAG ve zdrojovém souboru:"
  grep "const CACHE_BUST_TAG" "$SOURCE_LOADER" || echo "   ❌ CACHE_BUST_TAG nenalezen!"
  echo ""
else
  echo "❌ Zdrojový loader.js neexistuje: $SOURCE_LOADER"
  echo ""
fi

# Kontrola stagingu (pokud je nastaven STAGING_PASS)
echo "🌐 3. KONTROLA STAGINGU"
echo "--------------------------------"
if [ -f "$PROJECT_ROOT/.env" ]; then
  source "$SCRIPT_DIR/load-env.sh"
fi

if [ -z "${STAGING_PASS:-}" ]; then
  echo "⚠️  STAGING_PASS není nastaven - přeskočím kontrolu stagingu"
  echo "   Pro kontrolu stagingu spusť: ./scripts/check-staging-loader.sh"
  echo ""
else
  echo "Spouštím kontrolu stagingu..."
  echo ""
  "$SCRIPT_DIR/check-staging-loader.expect" || echo "❌ Chyba při kontrole stagingu"
  echo ""
fi

echo "=========================================="
echo "✅ Kontrola dokončena"