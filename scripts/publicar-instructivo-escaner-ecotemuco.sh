#!/usr/bin/env bash
# Publica instructivo escaner: carpeta web del RIS + Escritorio/Descargas en ECOTEMUCO (Windows).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOWNLOADS="$ROOT/frontend/downloads"
BAT="$ROOT/tools/ris-local-bridge/setup-scanner-windows.bat"
PDF="$ROOT/docs/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
HOST="${ECOTEMUCO_SSH:-ecotemuco}"
SSH_TARGET="${ECOTEMUCO_SSH_TARGET:-${ECOTEMUCO_USER:-admin}@100.96.63.26}"

mkdir -p "$DOWNLOADS"
cp -f "$BAT" "$DOWNLOADS/setup-scanner-windows.bat"
cp -f "$PDF" "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
echo "OK web: frontend/downloads/ (http://<IP-LAN>/downloads/)"

if ! ssh -o BatchMode=yes -o ConnectTimeout=10 "$HOST" "echo ok" 2>/dev/null; then
  echo ""
  echo "ECOTEMUCO no alcanzable por SSH (Tailscale/apagado)."
  echo "Cuando este en linea, ejecute de nuevo:"
  echo "  bash $0"
  echo ""
  echo "O descargue desde el navegador en la LAN del centro:"
  echo "  http://<IP-servidor-ECOTEMUCO>/downloads/"
  exit 0
fi

# Rutas Windows (OpenSSH): Escritorio y Descargas del usuario admin
REMOTE_PATHS=(
  "Desktop"
  "Escritorio"
  "Downloads"
  "Descargas"
)

for sub in "${REMOTE_PATHS[@]}"; do
  dest="${HOST}:${sub}/"
  if scp -o ConnectTimeout=15 "$BAT" "$PDF" "$dest" 2>/dev/null; then
    echo "OK copiado a ~/${sub}/"
  fi
done

echo "Listo en ECOTEMUCO."
