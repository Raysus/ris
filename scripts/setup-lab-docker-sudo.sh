#!/usr/bin/env bash
# Instalación stack LAN con Docker (guía laboratorio). Ejecutar:
#   sudo bash /home/raul/Escritorio/RIS/scripts/setup-lab-docker-sudo.sh
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Ejecute con sudo: sudo bash $0" >&2
  exit 1
fi

REAL_USER="${SUDO_USER:-raul}"
ROOT="/home/raul/Escritorio/RIS"
BACKEND="$ROOT/backend"
LAN_IP="$(hostname -I | awk '{print $1}')"
COMPOSE="docker compose -f docker-compose.lan.yml"

echo "=== RIS laboratorio Docker (sudo) ==="
echo "Usuario: $REAL_USER | IP LAN: $LAN_IP"

# Grupo docker
usermod -aG docker "$REAL_USER" 2>/dev/null || true

# Detener stack casa (libera 5432, 8000, 8080)
if [ -x "$ROOT/scripts/stop-lab-casa.sh" ]; then
  sudo -u "$REAL_USER" bash "$ROOT/scripts/stop-lab-casa.sh" 2>/dev/null || true
fi
export PATH="/home/raul/.local/pgsql/usr/lib/postgresql/18/bin:${PATH:-}"
pg_ctl -D /home/raul/.local/pgsql/data stop -m fast 2>/dev/null || true
pkill -f 'php.*0.0.0.0:8000' 2>/dev/null || true
fuser -k 8080/tcp 2>/dev/null || true

cd "$BACKEND"

# .env LAN
if [ ! -f .env ]; then
  cp .env.lan.example .env
fi
sed -i "s/192\.168\.1\.50/${LAN_IP}/g" .env
sed -i "s|http://siresamatriz.healthticloud.cl|http://${LAN_IP}|g" .env
sed -i "s|:8080||g" .env
sed -i 's/^DTE_EMISOR_RAZON_SOCIAL=HealthTiCloud RIS/DTE_EMISOR_RAZON_SOCIAL="HealthTiCloud RIS"/' .env
if ! grep -q '^DB_PASSWORD=.' .env || grep -q '^DB_PASSWORD=cambie-esta-clave' .env; then
  DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
  sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
fi
chown "$REAL_USER:$REAL_USER" .env

# MWL local (puerto 4242)
if ! grep -q '^MWL_PROVIDER=wlmscpfs' .env; then
  cat >> .env <<EOF

MWL_PROVIDER=wlmscpfs
MWL_DICOM_HOST=${LAN_IP}
MWL_PORT=4242
MWL_AET=SIRESA_MWL
MWL_FILES_PATH=/var/www/html/storage/app/mwl-worklists
WORKLIST_TIMEZONE=America/Santiago
APP_LAB_TIMEZONE=America/Santiago
EOF
else
  sed -i "s|^MWL_DICOM_HOST=.*|MWL_DICOM_HOST=${LAN_IP}|" .env
fi

echo "→ Construyendo imagen API..."
$COMPOSE build api

if ! grep -q '^APP_KEY=base64:' .env; then
  echo "→ Generando APP_KEY..."
  APP_KEY="$($COMPOSE run --rm --no-deps api php artisan key:generate --show)"
  sed -i "s/^APP_KEY=.*/APP_KEY=${APP_KEY}/" .env
  chown "$REAL_USER:$REAL_USER" .env
fi

echo "→ Levantando contenedores (primera vez puede tardar varios minutos)..."
$COMPOSE --profile mwl up -d --build

echo "→ Firewall (puertos 80 y 4242 MWL)..."
ufw allow 80/tcp comment 'RIS HealthTiCloud' 2>/dev/null || true
ufw allow 4242/tcp comment 'RIS MWL DICOM' 2>/dev/null || true
ufw allow OpenSSH 2>/dev/null || true

echo "→ Recargando config Laravel (MWL)..."
$COMPOSE exec -T api php artisan config:clear 2>/dev/null || true

echo "→ Esperando servicios..."
sleep 8
$COMPOSE ps

echo ""
echo "=== Health check ==="
curl -sS -m 15 http://127.0.0.1/api/health | head -c 400 || true
echo ""
echo ""
echo "Listo:"
echo "  Navegador (esta PC):  http://127.0.0.1"
echo "  Otras PCs en la red:  http://${LAN_IP}"
echo "  Login demo:           rgutierrez / rgutierrez"
echo ""
echo "Tras verificar login, edite backend/.env:"
echo "  DB_AUTO_SEED=false"
echo "  docker compose -f docker-compose.lan.yml up -d"
