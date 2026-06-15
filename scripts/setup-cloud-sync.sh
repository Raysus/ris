#!/usr/bin/env bash
# Configura sync lab → nube (CLOUD_SYNC_SECRET) y reinicia colas Docker.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/backend/.env"
COMPOSE="docker compose -f docker-compose.lan.yml"

if [ ! -f "$ENV_FILE" ]; then
  echo "No existe $ENV_FILE" >&2
  exit 1
fi

SECRET="${CLOUD_SYNC_SECRET:-}"
if [ -z "$SECRET" ]; then
  echo "Pegue CLOUD_SYNC_SECRET (mismo valor que api.healthticloud.cl en la nube):"
  read -rs SECRET
  echo
fi

if [ -z "$SECRET" ]; then
  echo "Error: secreto vacío. Pídalo al equipo de sistemas." >&2
  exit 1
fi

if grep -q '^CLOUD_SYNC_SECRET=' "$ENV_FILE"; then
  sed -i "s|^CLOUD_SYNC_SECRET=.*|CLOUD_SYNC_SECRET=${SECRET}|" "$ENV_FILE"
else
  echo "CLOUD_SYNC_SECRET=${SECRET}" >> "$ENV_FILE"
fi

grep -q '^RIS_CLOUD_ROLE=local' "$ENV_FILE" || echo "RIS_CLOUD_ROLE=local" >> "$ENV_FILE"

echo "→ Reiniciando api y queue..."
cd "$ROOT/backend"
if docker info >/dev/null 2>&1; then
  $COMPOSE up -d api queue
  $COMPOSE exec -T api php artisan config:clear
else
  sudo $COMPOSE up -d api queue
  sudo $COMPOSE exec -T api php artisan config:clear
fi

echo ""
echo "OK. En el RIS: Admin → Sync Nube → Enviar pendientes"
echo "Requiere sede concreta seleccionada (no «Todas mis sucursales»)."
