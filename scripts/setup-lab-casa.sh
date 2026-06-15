#!/usr/bin/env bash
# Instalación RIS laboratorio en casa — sigue docs/GUIA_INSTALACION_LABORATORIO.md
#
# Modo A (recomendado, guía oficial): Docker en puerto 80
# Modo B (sin sudo): stack local en puerto 8080 (scripts/start-lab-casa.sh)
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
LAN_IP="$(hostname -I | awk '{print $1}')"
COMPOSE="docker compose -f docker-compose.lan.yml"

echo "=== HealthTiCloud RIS — instalación laboratorio (casa) ==="
echo "IP detectada: $LAN_IP"
echo ""

if command -v docker >/dev/null 2>&1 && docker compose version >/dev/null 2>&1; then
  echo "→ Docker detectado: modo guía oficial (puerto 80)"
  cd "$BACKEND"

  if [ ! -f .env ] || ! grep -q "^APP_URL=http://${LAN_IP}\$" .env 2>/dev/null; then
    cp .env.lan.example .env
    sed -i "s/192\.168\.1\.50/${LAN_IP}/g" .env
    sed -i "s|http://siresamatriz.healthticloud.cl|http://${LAN_IP}|g" .env
    sed -i 's/^DTE_EMISOR_RAZON_SOCIAL=HealthTiCloud RIS/DTE_EMISOR_RAZON_SOCIAL="HealthTiCloud RIS"/' .env
    DB_PASS="$(openssl rand -base64 24 | tr -d '/+=' | head -c 24)"
    sed -i "s/^DB_PASSWORD=.*/DB_PASSWORD=${DB_PASS}/" .env
    if ! grep -q '^APP_KEY=base64:' .env; then
      APP_KEY="$($COMPOSE run --rm api php artisan key:generate --show)"
      sed -i "s/^APP_KEY=.*/APP_KEY=${APP_KEY}/" .env
    fi
    echo "  .env creado. Revise ORTHANC_URL y CLOUD_SYNC_SECRET si aplica."
  fi

  echo "→ Construyendo y levantando contenedores (puede tardar varios minutos)..."
  $COMPOSE up -d --build

  echo ""
  echo "Compruebe: curl -s http://127.0.0.1/api/health"
  echo "Navegador: http://${LAN_IP}"
  echo ""
  echo "Tras el primer arranque exitoso, edite .env:"
  echo "  DB_AUTO_SEED=false"
  echo "  docker compose -f docker-compose.lan.yml up -d"
  exit 0
fi

echo "Docker no está instalado."
echo ""
echo "Para seguir la guía oficial (puerto 80, nginx en Docker), ejecute UNA VEZ:"
echo ""
echo "  sudo apt-get update"
echo "  sudo apt-get install -y docker.io docker-compose-v2 git"
echo "  sudo usermod -aG docker \"\$USER\""
echo "  # Cierre sesión y vuelva a entrar"
echo "  $0"
echo ""
echo "Mientras tanto, use el modo casa sin Docker (puerto 8080):"
echo ""
echo "  $ROOT/scripts/start-lab-casa.sh"
echo ""
exit 1
