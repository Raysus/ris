#!/usr/bin/env bash
# Publica instaladores escáner/bridge: carpeta web del RIS + Escritorio en SIRESA.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOWNLOADS="$ROOT/frontend/downloads"
BAT="$ROOT/tools/ris-local-bridge/setup-scanner-windows.bat"
MAC="$ROOT/tools/ris-local-bridge/setup-scanner-macos.sh"
PDF="$ROOT/docs/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
GUIA="$DOWNLOADS/GUIA-Bridge-Mac-Horos.txt"
HOST="${SIRESA_SSH:-ris-siresa}"
REMOTE_APP="${SIRESA_APP_DIR:-/opt/RIS}"

mkdir -p "$DOWNLOADS"
cp -f "$BAT" "$DOWNLOADS/setup-scanner-windows.bat"
cp -f "$ROOT/tools/ris-local-bridge/configure-printer-windows.ps1" "$DOWNLOADS/configure-printer-windows.ps1"
cp -f "$MAC" "$DOWNLOADS/setup-scanner-macos.sh"
[[ -f "$PDF" ]] && cp -f "$PDF" "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
bash "$ROOT/scripts/package-ris-bridge-macos.sh"
echo "OK web local: frontend/downloads/"

if ! ssh -o BatchMode=yes -o ConnectTimeout=10 "$HOST" "echo ok" 2>/dev/null; then
  echo ""
  echo "SIRESA no alcanzable por SSH (Tailscale/apagado)."
  echo "Cuando esté en línea, ejecute de nuevo: bash $0"
  echo "O use en la LAN: http://<IP-servidor-SIRESA>/downloads/"
  exit 0
fi

rsync -az \
  "$DOWNLOADS/setup-scanner-windows.bat" \
  "$DOWNLOADS/setup-scanner-macos.sh" \
  "$DOWNLOADS/ris-local-bridge-macos.tar.gz" \
  "$DOWNLOADS/index.html" \
  "$HOST:${REMOTE_APP}/frontend/downloads/"
[[ -f "$GUIA" ]] && rsync -az "$GUIA" "$HOST:${REMOTE_APP}/frontend/downloads/"
[[ -f "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" ]] && \
  rsync -az "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "$HOST:${REMOTE_APP}/frontend/downloads/"

ssh -o ConnectTimeout=15 "$HOST" bash -s <<EOF
set -euo pipefail
APP="${REMOTE_APP}"
DESK="\${HOME}/Escritorio"
FOLDER="\$DESK/Instalar-Bridge-Mac-Horos"
mkdir -p "\$FOLDER"
cp -f "\${APP}/frontend/downloads/GUIA-Bridge-Mac-Horos.txt" "\$FOLDER/" 2>/dev/null || true
cp -f "\${APP}/frontend/downloads/setup-scanner-macos.sh" "\$FOLDER/"
cp -f "\${APP}/frontend/downloads/ris-local-bridge-macos.tar.gz" "\$FOLDER/"
cp -f "\${APP}/frontend/downloads/setup-scanner-windows.bat" "\$FOLDER/"
[[ -f "\${APP}/frontend/downloads/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" ]] && \
  cp -f "\${APP}/frontend/downloads/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "\$FOLDER/" || true
chmod +x "\$FOLDER/setup-scanner-macos.sh"
[[ -f "\$FOLDER/GUIA-Bridge-Mac-Horos.txt" ]] && cp -f "\$FOLDER/GUIA-Bridge-Mac-Horos.txt" "\$DESK/"
# Evitar duplicados sueltos en la raíz del Escritorio
rm -f "\$DESK/setup-scanner-macos.sh" "\$DESK/ris-local-bridge-macos.tar.gz" \
      "\$DESK/setup-scanner-windows.bat" "\$DESK/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
LAN_IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
cat > "\$DESK/LEEME-instaladores-RIS.txt" <<LEEME
Instaladores RIS Bridge (Escritorio del servidor)
================================================

Mac (Horos, sin Homebrew):
  Carpeta:  Escritorio/Instalar-Bridge-Mac-Horos/
  Guía:     Escritorio/GUIA-Bridge-Mac-Horos.txt

Windows (escáner):
  Misma carpeta: setup-scanner-windows.bat + PDF

Web LAN:
  http://\${LAN_IP:-192.168.0.127}/downloads/
LEEME
if [ -d "\${HOME}/Descargas" ]; then
  rm -rf "\${HOME}/Descargas/Instalar-Bridge-Mac-Horos"
  cp -rf "\$FOLDER" "\${HOME}/Descargas/" 2>/dev/null || true
fi
echo "OK carpeta: \$FOLDER"
ls -lah "\$FOLDER"
EOF

echo "Listo en SIRESA."
