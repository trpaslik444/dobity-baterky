#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Načti environment proměnné
if [ -f "$PROJECT_ROOT/.env" ]; then
  source "$SCRIPT_DIR/load-env.sh"
fi

if [ -z "${STAGING_PASS:-}" ]; then
  echo "ERROR: Nastav proměnnou STAGING_PASS s passphrase/heslem pro klíč." >&2
  echo "Buď nastav proměnnou STAGING_PASS, nebo uprav .env soubor." >&2
  exit 1
fi

echo "🔍 Kontroluji loader.js a CACHE_BUST_TAG na staging serveru..."
echo ""

"$SCRIPT_DIR/check-staging-loader.expect"