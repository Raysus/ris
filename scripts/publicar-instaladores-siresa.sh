#!/usr/bin/env bash
# Publica instaladores escáner/bridge: carpeta web del RIS + Escritorio en SIRESA.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOWNLOADS="$ROOT/frontend/downloads"
BAT="$ROOT/tools/ris-local-bridge/setup-scanner-windows.bat"
MAC="$ROOT/tools/ris-local-bridge/setup-scanner-macos.sh"
PDF="$ROOT/docs/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
GUIA_MAC="$DOWNLOADS/GUIA-Bridge-Mac-Horos.txt"
GUIA_WIN="$DOWNLOADS/LEEME-Bridge-Windows.txt"
HOST="${SIRESA_SSH:-ris-siresa}"
REMOTE_APP="${SIRESA_APP_DIR:-/opt/RIS}"

mkdir -p "$DOWNLOADS"
cp -f "$BAT" "$DOWNLOADS/setup-scanner-windows.bat"
cp -f "$ROOT/tools/ris-local-bridge/configure-printer-windows.ps1" "$DOWNLOADS/configure-printer-windows.ps1"
cp -f "$MAC" "$DOWNLOADS/setup-scanner-macos.sh"
[[ -f "$PDF" ]] && cp -f "$PDF" "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
bash "$ROOT/scripts/package-ris-bridge-macos.sh"
bash "$ROOT/scripts/package-ris-bridge-windows.sh"
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
  "$DOWNLOADS/configure-printer-windows.ps1" \
  "$DOWNLOADS/LEEME-Bridge-Windows.txt" \
  "$DOWNLOADS/ris-local-bridge-windows.zip" \
  "$DOWNLOADS/setup-scanner-macos.sh" \
  "$DOWNLOADS/ris-local-bridge-macos.tar.gz" \
  "$DOWNLOADS/index.html" \
  "$HOST:${REMOTE_APP}/frontend/downloads/"
[[ -f "$GUIA_MAC" ]] && rsync -az "$GUIA_MAC" "$HOST:${REMOTE_APP}/frontend/downloads/"
[[ -f "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" ]] && \
  rsync -az "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "$HOST:${REMOTE_APP}/frontend/downloads/"

# Empaqueta también el zip Windows en el servidor para descomprimir en Escritorio
rsync -az "$DOWNLOADS/ris-local-bridge-windows.zip" "$HOST:/tmp/ris-local-bridge-windows.zip"

ssh -o ConnectTimeout=15 "$HOST" bash -s <<EOF
set -euo pipefail
APP="${REMOTE_APP}"
DESK="\${HOME}/Escritorio"
FOLDER_MAC="\$DESK/Instalar-Bridge-Mac-Horos"
FOLDER_WIN="\$DESK/Instalar-Bridge-Windows"

# --- Mac ---
mkdir -p "\$FOLDER_MAC"
cp -f "\${APP}/frontend/downloads/GUIA-Bridge-Mac-Horos.txt" "\$FOLDER_MAC/" 2>/dev/null || true
cp -f "\${APP}/frontend/downloads/setup-scanner-macos.sh" "\$FOLDER_MAC/"
cp -f "\${APP}/frontend/downloads/ris-local-bridge-macos.tar.gz" "\$FOLDER_MAC/"
chmod +x "\$FOLDER_MAC/setup-scanner-macos.sh" 2>/dev/null || true
[[ -f "\$FOLDER_MAC/GUIA-Bridge-Mac-Horos.txt" ]] && cp -f "\$FOLDER_MAC/GUIA-Bridge-Mac-Horos.txt" "\$DESK/"

# --- Windows (impresora + escáner) ---
rm -rf "\$FOLDER_WIN"
mkdir -p "\$FOLDER_WIN"
if command -v unzip >/dev/null 2>&1; then
  unzip -qo /tmp/ris-local-bridge-windows.zip -d "\$DESK"
else
  python3 - <<'PY'
import zipfile, os
desk = os.path.expanduser("~/Escritorio")
with zipfile.ZipFile("/tmp/ris-local-bridge-windows.zip") as z:
    z.extractall(desk)
print("OK unzip python")
PY
fi
# Asegurar archivos sueltos también en la carpeta (por si el zip cambió de estructura)
cp -f "\${APP}/frontend/downloads/setup-scanner-windows.bat" "\$FOLDER_WIN/"
cp -f "\${APP}/frontend/downloads/configure-printer-windows.ps1" "\$FOLDER_WIN/"
cp -f "\${APP}/frontend/downloads/LEEME-Bridge-Windows.txt" "\$FOLDER_WIN/"
[[ -f "\${APP}/frontend/downloads/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" ]] && \
  cp -f "\${APP}/frontend/downloads/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "\$FOLDER_WIN/" || true
cp -f "\${APP}/frontend/downloads/ris-local-bridge-windows.zip" "\$FOLDER_WIN/"
cp -f "\$FOLDER_WIN/LEEME-Bridge-Windows.txt" "\$DESK/" 2>/dev/null || true

# Evitar duplicados sueltos en la raíz del Escritorio
rm -f "\$DESK/setup-scanner-macos.sh" "\$DESK/ris-local-bridge-macos.tar.gz" \
      "\$DESK/setup-scanner-windows.bat" "\$DESK/configure-printer-windows.ps1" \
      "\$DESK/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "\$DESK/ris-local-bridge-windows.zip"

LAN_IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
cat > "\$DESK/LEEME-instaladores-RIS.txt" <<LEEME
Instaladores RIS Bridge (Escritorio del servidor SIRESA)
=======================================================

Windows (escáner + impresora térmica Epson):
  Carpeta:  Escritorio/Instalar-Bridge-Windows/
  Guía:     Escritorio/LEEME-Bridge-Windows.txt
  ZIP:      Instalar-Bridge-Windows/ris-local-bridge-windows.zip
            (copie el ZIP o la carpeta a cada PC de recepción)

  Pasos rápidos en cada PC:
    1. Copiar carpeta Instalar-Bridge-Windows al PC
    2. Ejecutar setup-scanner-windows.bat (como Administrador)
    3. Ejecutar: powershell -ExecutionPolicy Bypass -File configure-printer-windows.ps1
    4. start-bridge.bat y verificar http://127.0.0.1:8181/health

Mac (Horos):
  Carpeta:  Escritorio/Instalar-Bridge-Mac-Horos/
  Guía:     Escritorio/GUIA-Bridge-Mac-Horos.txt

Web LAN:
  http://\${LAN_IP:-192.168.0.127}/downloads/
LEEME

if [ -d "\${HOME}/Descargas" ]; then
  rm -rf "\${HOME}/Descargas/Instalar-Bridge-Mac-Horos" "\${HOME}/Descargas/Instalar-Bridge-Windows"
  cp -rf "\$FOLDER_MAC" "\${HOME}/Descargas/" 2>/dev/null || true
  cp -rf "\$FOLDER_WIN" "\${HOME}/Descargas/" 2>/dev/null || true
fi

echo "OK Mac: \$FOLDER_MAC"
ls -lah "\$FOLDER_MAC"
echo "OK Windows: \$FOLDER_WIN"
ls -lah "\$FOLDER_WIN"
EOF

echo "Listo en SIRESA."
