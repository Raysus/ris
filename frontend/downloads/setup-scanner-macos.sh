#!/usr/bin/env bash
# Instalador RIS Local Bridge para macOS (recepción + radiología con Horos).
# Uso: chmod +x setup-scanner-macos.sh && ./setup-scanner-macos.sh

set -euo pipefail

BRIDGE_DIR="$(cd "$(dirname "$0")" && pwd)"
LOG_FILE="$BRIDGE_DIR/setup-scanner-macos.log"
HOROS_APP="/Applications/Horos.app"
HOROS_BIN="$HOROS_APP/Contents/MacOS/Horos"
NAPS2_BIN="/Applications/NAPS2.app/Contents/MacOS/NAPS2.Console"

log() {
    echo "$1" | tee -a "$LOG_FILE"
}

fail() {
    log "ERROR: $1"
    exit 1
}

: >"$LOG_FILE"
log "=== Instalador RIS Local Bridge (macOS) ==="
log "Carpeta: $BRIDGE_DIR"

log "[1/6] Comprobando Node.js..."
if ! command -v node >/dev/null 2>&1; then
    if command -v brew >/dev/null 2>&1; then
        log "Instalando Node.js con Homebrew..."
        brew install node >>"$LOG_FILE" 2>&1 || fail "No se pudo instalar Node.js con brew"
    else
        fail "Node.js no encontrado. Instálelo desde https://nodejs.org o instale Homebrew: https://brew.sh"
    fi
fi
log "Node.js: $(node -v)"

log "[2/6] Comprobando Horos..."
if [ ! -x "$HOROS_BIN" ]; then
    log "AVISO: Horos no está en $HOROS_APP"
    log "Descargue e instale Horos desde https://horosproject.org/ (arrastre a Aplicaciones)"
    HOROS_PATH="$HOROS_APP"
else
    log "Horos: $HOROS_BIN"
    HOROS_PATH="$HOROS_APP"
fi

log "[3/6] Comprobando NAPS2 (escáner, opcional)..."
NAPS2_PATH="$NAPS2_BIN"
if [ ! -x "$NAPS2_BIN" ]; then
    log "NAPS2 no encontrado (opcional para escanear en Agenda)."
    log "Instálelo desde https://www.naps2.com/download si usa escáner USB."
    NAPS2_PATH="$NAPS2_BIN"
fi

log "[4/6] Generando config.json (visor: Horos)..."
cat >"$BRIDGE_DIR/config.json" <<EOF
{
    "viewer": "horos",
    "paths": {
        "radiant": "",
        "horos": "$HOROS_PATH",
        "osirix": "/Applications/OsiriX.app",
        "weasis": ""
    },
    "scanner": {
        "naps2_path": "$NAPS2_PATH",
        "profile": "Default"
    }
}
EOF
log "config.json listo (viewer=horos)"

log "[5/6] Dependencias npm e inicio automático..."
cd "$BRIDGE_DIR"
npm install --no-fund --no-audit >>"$LOG_FILE" 2>&1 || fail "npm install falló. Revise $LOG_FILE"

chmod +x "$BRIDGE_DIR/start-bridge.sh" "$BRIDGE_DIR/install-macos-startup.sh"
"$BRIDGE_DIR/install-macos-startup.sh" >>"$LOG_FILE" 2>&1

log "[6/6] Verificando bridge..."
sleep 2
if curl -sf http://127.0.0.1:8181/health >/dev/null; then
    log "OK: bridge respondiendo en http://127.0.0.1:8181/health"
else
    log "AVISO: el bridge aún no responde. Ejecute: $BRIDGE_DIR/start-bridge.sh"
fi

echo ""
echo "============================================================"
echo " INSTALACIÓN COMPLETADA (macOS)"
echo "============================================================"
echo ""
echo " Bridge:  http://127.0.0.1:8181"
echo " Visor:   Horos"
echo " Log:     $LOG_FILE"
echo ""
echo " Si usa escáner: abra NAPS2, cree perfil \"Default\" y elija el dispositivo."
echo " Prueba RIS: Radiólogo → Visor PACS | Agenda → Escanear orden"
echo ""
echo " Detener auto-inicio:"
echo "   launchctl unload ~/Library/LaunchAgents/com.healthticloud.ris-bridge.plist"
echo ""
