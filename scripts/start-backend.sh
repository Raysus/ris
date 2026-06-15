#!/usr/bin/env bash
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
# shellcheck source=/dev/null
source "$ROOT/scripts/dev-env.sh"
cd "$ROOT/backend"
exec "$ROOT/scripts/php-local.sh" -S 127.0.0.1:8000 -t public public/index.php "$@"
