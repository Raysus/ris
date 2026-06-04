#!/usr/bin/env bash
# Reinstala dependencias PHP de Laravel (corrige vendor incompleto / deep-copy).
set -euo pipefail
cd "$(dirname "$0")/../backend"

if ! command -v composer >/dev/null 2>&1; then
  echo "Instale Composer: sudo apt install composer" >&2
  exit 1
fi

echo "→ composer install en $(pwd)"
composer install --no-interaction

if [[ ! -f vendor/myclabs/deep-copy/src/DeepCopy/deep_copy.php ]]; then
  echo "Error: vendor sigue incompleto." >&2
  exit 1
fi

php artisan --version
echo "OK: dependencias listas."
