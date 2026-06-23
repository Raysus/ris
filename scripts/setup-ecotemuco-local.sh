#!/usr/bin/env bash
# Instala RIS ECOTEMUCO en LAN (Docker) — sin demo Siresa.
# Uso: bash scripts/setup-ecotemuco-local.sh
#      RESET_DB=1 bash scripts/setup-ecotemuco-local.sh   # borra volúmenes y reinstala
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
COMPOSE="docker compose -f docker-compose.lan.yml"
LAN_IP="$(hostname -I 2>/dev/null | awk '{print $1}')"

echo "=== ECOTEMUCO — instalación local (LAN) ==="
echo "IP detectada: ${LAN_IP:-desconocida}"
echo ""

if ! command -v docker >/dev/null 2>&1 || ! docker compose version >/dev/null 2>&1; then
  echo "Instale Docker primero (ver docs/GUIA_INSTALACION_LABORATORIO.md §3)." >&2
  exit 1
fi

cd "$BACKEND"

if [ ! -f .env ]; then
  cp .env.lan.example .env
  echo "→ Creado .env desde .env.lan.example"
fi

if [ -n "${LAN_IP:-}" ]; then
  bash "$ROOT/scripts/apply-lan-secrets.sh" "$LAN_IP"
else
  bash "$ROOT/scripts/apply-lan-secrets.sh"
fi

set_kv() {
  local key="$1" val="$2"
  if grep -q "^${key}=" .env; then
    sed -i "s|^${key}=.*|${key}=${val}|" .env
  else
    echo "${key}=${val}" >> .env
  fi
}

set_kv 'DB_AUTO_SEED' 'true'
set_kv 'DB_SEED_CLASS' 'EcotemucoLabSeeder'
set_kv 'RIS_CLOUD_ROLE' 'local'

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "→ Generando APP_KEY..."
  APP_KEY="$($COMPOSE run --rm api php artisan key:generate --show)"
  set_kv 'APP_KEY' "$APP_KEY"
fi

if grep -q '^DB_PASSWORD=cambie-esta-clave' .env; then
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
  set_kv 'DB_PASSWORD' "$DB_PASS"
  echo "→ DB_PASSWORD generado"
fi

if [ "${RESET_DB:-0}" = "1" ]; then
  echo "→ RESET_DB=1: eliminando contenedores y volumen PostgreSQL..."
  $COMPOSE down -v || true
fi

echo "→ Construyendo y levantando stack (puede tardar varios minutos)..."
$COMPOSE up -d --build

echo "→ Esperando API..."
for i in $(seq 1 30); do
  if curl -sf http://127.0.0.1/api/health >/dev/null 2>&1; then
    break
  fi
  sleep 3
done

if ! curl -sf http://127.0.0.1/api/health >/dev/null 2>&1; then
  echo "API no responde. Revise: $COMPOSE logs api" >&2
  exit 1
fi

set_kv 'DB_AUTO_SEED' 'false'
$COMPOSE up -d

echo ""
echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  ECOTEMUCO local listo                                       ║"
echo "╚══════════════════════════════════════════════════════════════╝"
echo ""
echo "  URL:      http://${LAN_IP:-127.0.0.1}"
echo "  Login:    admin / admin"
echo "  Health:   curl -s http://127.0.0.1/api/health"
echo ""
echo "  Siguiente paso en el navegador:"
echo "    1. Seleccionar ECOTEMUCO en la barra superior"
echo "    2. Administración → Sync Nube → Catálogo desde nube"
echo ""
echo "  Documentación: docs/DESPLIEGUE_ECOTEMUCO_LOCAL.md"
