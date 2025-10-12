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

# Zkus načíst .env, pokud ještě nebyl načten
if [ -z "${STAGING_PASS:-}" ]; then
  if [ -f "$PROJECT_ROOT/.env" ]; then
    # Načti .env bezpečně
    set -a
    # shellcheck disable=SC1090
    . "$PROJECT_ROOT/.env"
    set +a
  fi
fi

# Pokud stále chybí, zeptej se interaktivně (pokud je TTY)
if [ -z "${STAGING_PASS:-}" ]; then
  if [ -t 0 ]; then
    read -r -s -p "Zadej STAGING_PASS (passphrase pro ~/.ssh/id_ed25519_wpcom): " STAGING_PASS
    echo ""
  fi
fi

if [ -z "${STAGING_PASS:-}" ]; then
  echo "ERROR: Nastav proměnnou STAGING_PASS s passphrase/heslem pro klíč ondraplas-default (nebo doplň do .env)." >&2
  exit 1
fi

echo "🚀 Nasazuji na staging..."
"$SCRIPT_DIR/deploy-staging.expect" "$BUILD_DIR"

echo "✅ Hotovo. Ověř staging na https://staging-f576-dobitybaterky.wpcomstaging.com/"
