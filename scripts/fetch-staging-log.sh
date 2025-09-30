#!/usr/bin/env bash

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

# Usage:
#   ./scripts/fetch-staging-log.sh [remote_path] [local_path]
# Defaults:
#   remote_path = wp-content/debug.log
#   local_path  = ./debug.log

REMOTE_PATH="${1:-wp-content/debug.log}"
LOCAL_PATH="${2:-debug.log}"

if [ -z "${STAGING_PASS:-}" ]; then
  # Try to load from .env via helper
  if [ -f "$SCRIPT_DIR/load-env.sh" ]; then
    . "$SCRIPT_DIR/load-env.sh" >/dev/null 2>&1 || true
  fi
fi

if [ -z "${STAGING_PASS:-}" ]; then
  echo "ERROR: STAGING_PASS není nastaven. Spusť: source scripts/load-env.sh nebo exportuj STAGING_PASS." >&2
  exit 1
fi

TMP_DEST="$(mktemp -t staging-log.XXXX)"

"$SCRIPT_DIR/fetch-staging-log.expect" "$REMOTE_PATH" "$TMP_DEST"

mkdir -p "$(dirname "$LOCAL_PATH")"
mv -f "$TMP_DEST" "$LOCAL_PATH"

echo "✅ Staženo: $REMOTE_PATH -> $LOCAL_PATH"


