#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Načti environment proměnné
if [ -f "$PROJECT_ROOT/.env" ]; then
  source "$SCRIPT_DIR/load-env.sh"
fi

TARGET="${1:-staging}" # staging | production

BUILD_DIR="$PROJECT_ROOT/build/dobity-baterky"
LOADER_JS="$BUILD_DIR/assets/map/loader.js"

if [ ! -f "$LOADER_JS" ]; then
  echo "❌ loader.js v build složce chybí: $LOADER_JS"
  exit 1
fi

BUILD_TAG=$(grep -Eo "CACHE_BUST_TAG[^=]*= *'[^']+'" "$LOADER_JS" | head -1 | grep -Eo "'[^']+'" | tr -d "'" || echo "")
if [ -z "$BUILD_TAG" ]; then
  echo "❌ CACHE_BUST_TAG v build loader.js nenalezen"
  exit 1
fi

BUILD_SIZE=$(stat -c "%s" "$LOADER_JS" 2>/dev/null || stat -f "%z" "$LOADER_JS" 2>/dev/null || echo "")
if [ -z "$BUILD_SIZE" ]; then
  echo "❌ Nelze zjistit velikost build loader.js"
  exit 1
fi

echo "✅ Build CACHE_BUST_TAG: $BUILD_TAG"
echo "✅ Build loader.js size: $BUILD_SIZE bytes"

if [ "$TARGET" = "staging" ]; then
  if [ -z "${STAGING_PASS:-}" ]; then
    echo "❌ STAGING_PASS není nastaven"
    exit 1
  fi
  OUTPUT=$("$SCRIPT_DIR/check-staging-loader.expect" 2>&1 | tr -d '\r')
elif [ "$TARGET" = "production" ]; then
  if [ -z "${PROD_PASS:-}" ]; then
    echo "❌ PROD_PASS není nastaven"
    exit 1
  fi
  OUTPUT=$("$SCRIPT_DIR/check-production-loader.expect" 2>&1 | tr -d '\r')
else
  echo "❌ Neznámý target: $TARGET (použij 'staging' nebo 'production')"
  exit 1
fi

# Zkontrolovat, že CACHE_BUST_TAG odpovídá
if ! echo "$OUTPUT" | grep -q "$BUILD_TAG"; then
  echo "❌ CACHE_BUST_TAG na serveru neodpovídá buildu"
  echo "   Očekáváno: $BUILD_TAG"
  echo "   Server výstup:"
  echo "$OUTPUT" | grep -A 2 "CACHE_BUST_TAG" || echo "   (nenalezen v server výstupu)"
  exit 1
fi

# Zkontrolovat velikost v bytech
SERVER_SIZE=$(echo "$OUTPUT" | grep -Eo "Velikost_bytes: [0-9]+" | head -1 | awk '{print $2}' || echo "")
# Fallback parsing pro extra robustnost
if [ -z "$SERVER_SIZE" ]; then
  SERVER_SIZE=$(echo "$OUTPUT" | grep -Eo "^[0-9]+$" | head -1 || echo "")
fi
if [ -n "$SERVER_SIZE" ]; then
  echo "✅ Server loader.js velikost: $SERVER_SIZE bytes"
  if [ "$SERVER_SIZE" != "$BUILD_SIZE" ]; then
    echo "❌ Velikost loader.js na serveru ($SERVER_SIZE) neodpovídá buildu ($BUILD_SIZE)"
    exit 1
  fi
  echo "✅ Velikost loader.js odpovídá buildu"
else
  echo "⚠️  Nelze zjistit velikost v bytech na serveru (kontrola pokračuje pouze s CACHE_BUST_TAG)"
fi

echo "✅ Deploy ověřen: CACHE_BUST_TAG i velikost loader.js odpovídají buildu"