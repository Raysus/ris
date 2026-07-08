#!/usr/bin/env bash
# Publica instaladores escáner/bridge: carpeta web del RIS + Escritorio en SIRESA.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
DOWNLOADS="$ROOT/frontend/downloads"
BAT="$ROOT/tools/ris-local-bridge/setup-scanner-windows.bat"
MAC="$ROOT/tools/ris-local-bridge/setup-scanner-macos.sh"
PDF="$ROOT/docs/INSTRUCTIVO_Escaner_RIS_Bridge.pdf"
HOST="${SIRESA_SSH:-ris-siresa}"
REMOTE_APP="${SIRESA_APP_DIR:-/opt/RIS}"

mkdir -p "$DOWNLOADS"
cp -f "$BAT" "$DOWNLOADS/setup-scanner-windows.bat"
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

# Sincroniza artefactos locales → servidor (aunque el repo aún no tenga el commit).
rsync -az \
  "$DOWNLOADS/setup-scanner-windows.bat" \
  "$DOWNLOADS/setup-scanner-macos.sh" \
  "$DOWNLOADS/ris-local-bridge-macos.tar.gz" \
  "$DOWNLOADS/index.html" \
  "$HOST:${REMOTE_APP}/frontend/downloads/"
[[ -f "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" ]] && \
  rsync -az "$DOWNLOADS/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "$HOST:${REMOTE_APP}/frontend/downloads/"

ssh -o ConnectTimeout=15 "$HOST" bash -s <<EOF
set -euo pipefail
APP="${REMOTE_APP}"
FILES=(
  "\${APP}/frontend/downloads/setup-scanner-windows.bat"
  "\${APP}/frontend/downloads/setup-scanner-macos.sh"
  "\${APP}/frontend/downloads/ris-local-bridge-macos.tar.gz"
)
for f in "\${FILES[@]}"; do
  if [ ! -f "\$f" ]; then
    echo "Falta en servidor: \$f" >&2
    exit 1
  fi
done
for dest in "\${HOME}/Escritorio" "\${HOME}/Desktop" "\${HOME}/Descargas" "\${HOME}/Downloads"; do
  if [ -d "\$dest" ]; then
    cp -f "\${FILES[@]}" "\$dest/" 2>/dev/null || true
    [[ -f "\${APP}/frontend/downloads/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" ]] && \
      cp -f "\${APP}/frontend/downloads/INSTRUCTIVO_Escaner_RIS_Bridge.pdf" "\$dest/" || true
    echo "OK copiado a \$dest/"
  fi
done
LAN_IP=\$(hostname -I 2>/dev/null | awk '{print \$1}')
cat > "\${HOME}/Escritorio/LEEME-instaladores-RIS.txt" <<LEEME
Instaladores RIS Bridge
=======================
Windows (escáner USB):
  Copie setup-scanner-windows.bat a C:\\RIS\\tools\\ris-local-bridge\\ y ejecútelo.

macOS (Horos, sin Homebrew ni Node previos):
  En Terminal del Mac:
    mkdir -p ~/HealthTiCloud/ris-local-bridge && cd ~/HealthTiCloud/ris-local-bridge
    curl -fsSL http://\${LAN_IP:-192.168.0.127}/downloads/ris-local-bridge-macos.tar.gz | tar -xz
    chmod +x setup-scanner-macos.sh && ./setup-scanner-macos.sh
  Requiere Horos en /Applications. El script descarga Node oficial si falta.

También: http://\${LAN_IP:-192.168.0.127}/downloads/
LEEME
echo "OK LEEME en Escritorio"
ls -la "\${HOME}/Escritorio/" 2>/dev/null | grep -E 'bat|pdf|macos|bridge|LEEME' || true
EOF

echo "Listo en SIRESA."
