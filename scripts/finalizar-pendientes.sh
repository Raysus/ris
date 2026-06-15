#!/usr/bin/env bash
# Aplica lo que requiere sudo en UNA sola ejecución (contraseña una vez).
# Uso: sudo bash scripts/finalizar-pendientes.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"

if [ "$(id -u)" -ne 0 ]; then
  echo "Ejecute con sudo:" >&2
  echo "  sudo bash $0" >&2
  exit 1
fi

REAL_USER="${SUDO_USER:-raul}"

echo "=== 1. Firewall LAN (80 + MWL 4242) ==="
bash "$ROOT/scripts/open-lab-firewall.sh"

echo ""
echo "=== 2. Recrear API/queue con .env actual (DB_AUTO_SEED=false) ==="
cd "$BACKEND"
docker compose -f docker-compose.lan.yml up -d api queue
docker compose -f docker-compose.lan.yml exec -T api php artisan config:clear
docker compose -f docker-compose.lan.yml exec -T api php artisan optimize

echo ""
echo "=== 3. Grupo docker (por si faltaba) ==="
usermod -aG docker "$REAL_USER" 2>/dev/null || true

echo ""
echo "Listo. ${REAL_USER} debe cerrar sesión y volver a entrar para usar 'docker' sin sudo."
echo "Luego: bash $ROOT/scripts/checklist-estacion.sh"
