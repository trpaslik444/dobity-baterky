#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PROJECT_ROOT="$(cd "$SCRIPT_DIR/.." && pwd)"

# Načti environment proměnné
if [ -f "$PROJECT_ROOT/.env" ]; then
  source "$SCRIPT_DIR/load-env.sh"
fi

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

if [ -z "${STAGING_PASS:-}" ]; then
  echo "ERROR: Nastav proměnnou STAGING_PASS s passphrase/heslem pro klíč ondraplas-default." >&2
  exit 1
fi

echo "🚀 Nasazuji na staging s bezpečnou aktivací..."
"$SCRIPT_DIR/deploy-staging-safe.expect" "$BUILD_DIR"
