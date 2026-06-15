#!/usr/bin/env bash
# Levanta MWL local (DCMTK wlmscpfs) en puerto 4242 — guía laboratorio §13.
# Ejecutar: sudo bash scripts/start-mwl.sh
#   o (con grupo docker): bash scripts/start-mwl.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
LAN_IP="$(hostname -I | awk '{print $1}')"
COMPOSE="docker compose -f docker-compose.lan.yml"

compose() {
  if docker info >/dev/null 2>&1; then
    $COMPOSE "$@"
  else
    sudo $COMPOSE "$@"
  fi
}

cd "$BACKEND"

# Asegurar variables MWL en .env
if ! grep -q '^MWL_PROVIDER=wlmscpfs' .env 2>/dev/null; then
  echo "Active MWL_PROVIDER=wlmscpfs en backend/.env (ver .env.lan.example §MWL)" >&2
  exit 1
fi
sed -i "s|^MWL_DICOM_HOST=.*|MWL_DICOM_HOST=${LAN_IP}|" .env

echo "=== MWL local (wlmscpfs) puerto 4242 ==="
echo "IP LAN equipos DICOM: ${LAN_IP}"
echo "AE Title destino:     $(grep '^MWL_AET=' .env | cut -d= -f2-)"

echo "→ Levantando contenedor mwl..."
compose --profile mwl up -d mwl

echo "→ Recreando api (volumen worklists compartido)..."
compose up -d --build api queue

echo "→ Limpiando caché de config..."
compose exec -T api php artisan config:clear

if [ "$(id -u)" -eq 0 ]; then
  ufw allow 4242/tcp comment 'RIS MWL DICOM' 2>/dev/null || true
fi

sleep 3
compose ps mwl api

echo ""
if ss -tln | grep -q ':4242 '; then
  echo "Puerto 4242: ESCUCHANDO"
else
  echo "Puerto 4242: no detectado — revise: compose logs mwl"
fi

echo ""
echo "Prueba C-FIND desde el servidor:"
echo "  cd backend && docker compose -f docker-compose.lan.yml exec -T api php artisan pacs:probe-mwl --station=FCR_PANO --modality=CR"
echo ""
echo "En el equipo (FCR / ecógrafo):"
echo "  Host MWL:  ${LAN_IP}"
echo "  Puerto:    4242"
echo "  AE remoto: $(grep '^MWL_AET=' .env | cut -d= -f2-)"
