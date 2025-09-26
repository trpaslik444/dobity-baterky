#!/usr/bin/env bash

# Načte proměnné z .env (pokud existuje) do aktuálního shellu.

ENV_FILE="$(cd "$(dirname "${BASH_SOURCE[0]}")"/.. && pwd)/.env"

if [ ! -f "$ENV_FILE" ]; then
  echo "No .env file found at $ENV_FILE" >&2
  return 0 2>/dev/null || exit 0
fi

set -a
. "$ENV_FILE"
set +a

echo ".env loaded from $ENV_FILE"
