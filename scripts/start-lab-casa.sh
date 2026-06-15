#!/usr/bin/env bash
# Arranque del RIS en modo laboratorio (casa) sin Docker.
# Replica la guía GUIA_INSTALACION_LABORATORIO: nginx + API + PostgreSQL.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
LAN_IP="$(hostname -I | awk '{print $1}')"
PORT="${RIS_LAB_PORT:-8080}"

mkdir -p /home/raul/.local/nginx-run/{logs,run}
mkdir -p /home/raul/.local/pgsql/run

# PostgreSQL local
export PATH="/home/raul/.local/pgsql/usr/lib/postgresql/18/bin:${PATH}"
export LD_LIBRARY_PATH="/home/raul/.local/pgsql/usr/lib/x86_64-linux-gnu:${LD_LIBRARY_PATH:-}"
if ! pg_isready -h 127.0.0.1 -p 5432 >/dev/null 2>&1; then
  pg_ctl -D /home/raul/.local/pgsql/data -l /home/raul/.local/pgsql/logfile start
  sleep 2
fi

# API Laravel (0.0.0.0 para acceso LAN directo si hace falta :8000)
source "$ROOT/scripts/dev-env.sh"
cd "$BACKEND"
if ! ss -tln | grep -q ':8000 '; then
  nohup "$ROOT/scripts/php-local.sh" -S 0.0.0.0:8000 -t public public/index.php \
    > /home/raul/.local/nginx-run/logs/api.log 2>&1 &
  echo $! > /home/raul/.local/nginx-run/run/api.pid
  sleep 2
fi

# Nginx (frontend + proxy /api/)
NGINX="/home/raul/.local/nginx-root/usr/sbin/nginx"
CONF="$BACKEND/docker/lab-casa/nginx-main.conf"
if [ -f /home/raul/.local/nginx-run/run/nginx.pid ] && kill -0 "$(cat /home/raul/.local/nginx-run/run/nginx.pid)" 2>/dev/null; then
  "$NGINX" -p /home/raul/.local/nginx-run -c "$CONF" -s reload 2>/dev/null || true
else
  "$NGINX" -p /home/raul/.local/nginx-run -c "$CONF"
fi

echo ""
echo "=== RIS laboratorio (casa) ==="
echo "  IP LAN:     $LAN_IP"
echo "  Navegador:  http://${LAN_IP}:${PORT}"
echo "  Health:     http://${LAN_IP}:${PORT}/api/health"
echo "  Login demo: rgutierrez / rgutierrez"
echo ""
echo "Desde otra PC en la misma red Wi‑Fi:"
echo "  curl -s http://${LAN_IP}:${PORT}/api/health"
echo ""
