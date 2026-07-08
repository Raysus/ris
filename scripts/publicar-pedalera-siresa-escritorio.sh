#!/usr/bin/env bash
# Publica guía pedalera LFH2330 en /downloads y Escritorio del servidor SIRESA.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
GUIA="$ROOT/docs/GUIA-Pedalera-LFH2330.txt"
HOST="${SIRESA_SSH:-ris-siresa}"
REMOTE_APP="${SIRESA_APP_DIR:-/opt/RIS}"

if [[ ! -f "$GUIA" ]]; then
  echo "No existe: $GUIA"
  exit 1
fi

mkdir -p "$ROOT/frontend/downloads"
cp -f "$GUIA" "$ROOT/frontend/downloads/GUIA-Pedalera-LFH2330.txt"

if ! ssh -o BatchMode=yes -o ConnectTimeout=10 "$HOST" "echo ok" 2>/dev/null; then
  echo ""
  echo "SIRESA no alcanzable por SSH."
  echo "Cuando esté en línea: bash $0"
  exit 0
fi

rsync -az "$GUIA" "$HOST:${REMOTE_APP}/frontend/downloads/GUIA-Pedalera-LFH2330.txt"

rsync -az "$ROOT/frontend/js/lib/dictation_support.js" "$HOST:${REMOTE_APP}/frontend/js/lib/"
rsync -az "$ROOT/frontend/js/workflow/speechmike-dictation.js" "$HOST:${REMOTE_APP}/frontend/js/workflow/"
rsync -az "$ROOT/frontend/js/workflow/transcription.js" "$HOST:${REMOTE_APP}/frontend/js/workflow/"
rsync -az "$ROOT/frontend/js/core/router.js" "$HOST:${REMOTE_APP}/frontend/js/core/"
rsync -az "$ROOT/frontend/pages/transcription.html" "$HOST:${REMOTE_APP}/frontend/pages/"
rsync -az "$ROOT/frontend/pages/radiologist.html" "$HOST:${REMOTE_APP}/frontend/pages/"

ssh -o ConnectTimeout=15 "$HOST" bash -s <<'EOF'
set -euo pipefail
APP="/opt/RIS"
DESK="${HOME}/Escritorio"
FOLDER="$DESK/Pedalera-LFH2330-RIS"
mkdir -p "$FOLDER"
cp -f "${APP}/frontend/downloads/GUIA-Pedalera-LFH2330.txt" "$FOLDER/"
cp -f "$DESK/chrome-HealthTICloud_RIS.desktop" "$FOLDER/Abrir-RIS-Chrome.desktop" 2>/dev/null || true
chmod +x "$FOLDER/Abrir-RIS-Chrome.desktop" 2>/dev/null || true

LAN_IP=$(hostname -I 2>/dev/null | awk '{print $1}')
cat > "$FOLDER/LEEME.txt" <<LEEME
Pedalera Philips LFH2330 — RIS
==============================

1. Lea GUIA-Pedalera-LFH2330.txt (paso a paso).
2. Abra el RIS con «Abrir-RIS-Chrome.desktop» (Chrome + HTTPS).
3. Módulo Transcripción → «Conectar pedalera / SpeechMike».
4. Cierre SpeechControl si la pedalera no responde.

Web LAN (descarga guía): http://${LAN_IP:-192.168.0.127}/downloads/
LEEME

cp -f "$FOLDER/LEEME.txt" "$DESK/LEEME-Pedalera-LFH2330.txt"
cp -f "$FOLDER/GUIA-Pedalera-LFH2330.txt" "$DESK/GUIA-Pedalera-LFH2330.txt"

echo "OK carpeta: $FOLDER"
ls -lah "$FOLDER"
EOF

echo "Listo: pedalera publicada en SIRESA (código + escritorio)."
