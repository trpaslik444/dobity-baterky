#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

if ! command -v php >/dev/null 2>&1; then
  echo "ERROR: PHP není dostupné v PATH." >&2
  exit 1
fi

echo "🔧 Spouštím build-simple.php"
php "$PROJECT_ROOT/build-simple.php"

BUILD_DIR="$PROJECT_ROOT/build/dobity-baterky"

if [ ! -d "$BUILD_DIR" ]; then
  echo "ERROR: Build složka $BUILD_DIR neexistuje." >&2
  exit 1
fi

if [ -z "${PROD_PASS:-}" ]; then
  echo "ERROR: Nastav proměnnou PROD_PASS s passphrase/heslem pro klíč ondraplas-default." >&2
  exit 1
fi

echo "🚀 Nasazuji na produkci..."
"$SCRIPT_DIR/deploy-production.expect" "$BUILD_DIR"

echo "✅ Hotovo. Zkontroluj https://dobitybaterky.cz/ a aktivuj plugin v administraci, pokud je vypnutý."
