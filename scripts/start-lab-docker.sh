#!/usr/bin/env bash
# Levanta el stack Docker LAN (puerto 80). Requiere grupo docker o sudo.
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
LAN_IP="$(hostname -I | awk '{print $1}')"
COMPOSE="docker compose -f docker-compose.lan.yml"

docker_cmd() {
  if docker info >/dev/null 2>&1; then
    docker "$@"
  elif sudo -n docker info >/dev/null 2>&1; then
    sudo docker "$@"
  else
    echo "Sin acceso a Docker. Ejecute:" >&2
    echo "  sudo bash $ROOT/scripts/setup-lab-docker-sudo.sh" >&2
    echo "  # o cierre sesión y vuelva a entrar (grupo docker)" >&2
    exit 1
  fi
}

compose_cmd() {
  if docker compose version >/dev/null 2>&1 && docker info >/dev/null 2>&1; then
    docker compose "$@"
  else
    sudo docker compose "$@"
  fi
}

echo "=== RIS Docker LAN ==="
echo "IP: $LAN_IP"

# Detener stack casa si corre
"$ROOT/scripts/stop-lab-casa.sh" 2>/dev/null || true
export PATH="/home/raul/.local/pgsql/usr/lib/postgresql/18/bin:${PATH:-}"
pg_ctl -D /home/raul/.local/pgsql/data stop -m fast 2>/dev/null || true

cd "$BACKEND"

# URLs puerto 80 (sin :8080)
sed -i "s|^APP_URL=.*|APP_URL=http://${LAN_IP}|" .env
sed -i "s|^FRONTEND_URL=.*|FRONTEND_URL=http://${LAN_IP}|" .env
sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${LAN_IP},localhost,127.0.0.1|" .env

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "→ Generando APP_KEY..."
  APP_KEY="$(compose_cmd -f docker-compose.lan.yml run --rm --no-deps api php artisan key:generate --show)"
  sed -i "s/^APP_KEY=.*/APP_KEY=${APP_KEY}/" .env
fi

echo "→ Construyendo y levantando contenedores..."
compose_cmd -f docker-compose.lan.yml up -d --build

sleep 6
compose_cmd -f docker-compose.lan.yml ps

echo ""
curl -sS -m 15 http://127.0.0.1/api/health | head -c 300 || true
echo ""
echo ""
echo "RIS en Docker:"
echo "  http://127.0.0.1"
echo "  http://${LAN_IP}"
echo "  Login: rgutierrez / rgutierrez"
