#!/usr/bin/env bash
# Instalador RIS Local Bridge para macOS (recepción + radiología con Horos).
# NO requiere Homebrew. Si no hay Node, descarga el binario oficial de nodejs.org.
#
# Uso (desde esta carpeta del repo):
#   chmod +x setup-scanner-macos.sh && ./setup-scanner-macos.sh
#
# Uso (instalador web / Descarga):
#   curl -fsSL http://SIRESA/downloads/setup-scanner-macos.sh | bash
#   # o descargue el .sh y ejecute en Terminal

set -euo pipefail

NODE_MAJOR="${NODE_MAJOR:-22}"
INSTALL_ROOT="${RIS_BRIDGE_HOME:-$HOME/HealthTiCloud/ris-local-bridge}"
HOROS_APP="/Applications/Horos.app"
HOROS_BIN="$HOROS_APP/Contents/MacOS/Horos"
NAPS2_BIN="/Applications/NAPS2.app/Contents/MacOS/NAPS2.Console"

SCRIPT_SRC="${BASH_SOURCE[0]:-$0}"
# Si se ejecuta por pipe (curl | bash), BASH_SOURCE no es un archivo local.
if [[ "$SCRIPT_SRC" == /* ]] && [[ -f "$SCRIPT_SRC" ]]; then
  SCRIPT_DIR="$(cd "$(dirname "$SCRIPT_SRC")" && pwd)"
else
  SCRIPT_DIR=""
fi

# Si el script vive dentro del repo tools/ris-local-bridge, instalar ahí;
# si es un descargable suelto / pipe, usar ~/HealthTiCloud/ris-local-bridge.
if [[ -n "$SCRIPT_DIR" && -f "$SCRIPT_DIR/server.js" && -f "$SCRIPT_DIR/package.json" ]]; then
  BRIDGE_DIR="$SCRIPT_DIR"
else
  BRIDGE_DIR="$INSTALL_ROOT"
fi

LOG_FILE="$BRIDGE_DIR/setup-scanner-macos.log"
mkdir -p "$BRIDGE_DIR"

log() {
  echo "$1" | tee -a "$LOG_FILE"
}

fail() {
  log "ERROR: $1"
  exit 1
}

require_macos() {
  [[ "$(uname -s)" == "Darwin" ]] || fail "Este instalador es solo para macOS."
}

resolve_arch() {
  case "$(uname -m)" in
    arm64|aarch64) echo "arm64" ;;
    x86_64) echo "x64" ;;
    *) fail "Arquitectura no soportada: $(uname -m)" ;;
  esac
}

ensure_node() {
  export PATH="$BRIDGE_DIR/.node/bin:/opt/homebrew/bin:/usr/local/bin:$PATH"
  if command -v node >/dev/null 2>&1 && command -v npm >/dev/null 2>&1; then
    log "Node.js encontrado: $(node -v) ($(command -v node))"
    return 0
  fi

  local arch tarball url tmpdir node_dir
  arch="$(resolve_arch)"
  log "Node.js no está instalado. Descargando binario oficial (v${NODE_MAJOR}, ${arch}) sin Homebrew..."

  tmpdir="$(mktemp -d)"
  # Resuelve la última versión mayor desde el índice oficial.
  local ver
  ver="$(curl -fsSL "https://nodejs.org/dist/index.json" \
    | python3 -c "import json,sys; maj=int(sys.argv[1]); rel=[x for x in json.load(sys.stdin) if x['version'].startswith('v'+str(maj)+'.')]; print(rel[0]['version'] if rel else '')" "$NODE_MAJOR" 2>/dev/null || true)"
  if [[ -z "$ver" ]]; then
    ver="v${NODE_MAJOR}.14.0"
    log "AVISO: no se pudo resolver la versión LTS; usando $ver"
  fi
  tarball="node-${ver}-darwin-${arch}.tar.gz"
  url="https://nodejs.org/dist/${ver}/${tarball}"
  log "Descargando $url ..."
  curl -fL --retry 3 --retry-delay 2 -o "$tmpdir/$tarball" "$url" || fail "Descarga de Node falló ($url)"

  tar -xzf "$tmpdir/$tarball" -C "$tmpdir"
  node_dir="$(find "$tmpdir" -maxdepth 1 -type d -name "node-*" | head -1)"
  [[ -n "$node_dir" ]] || fail "No se extrajo el tarball de Node"

  rm -rf "$BRIDGE_DIR/.node"
  mkdir -p "$BRIDGE_DIR/.node"
  # Contiene bin/, lib/, etc.
  cp -R "$node_dir"/* "$BRIDGE_DIR/.node/"
  rm -rf "$tmpdir"

  export PATH="$BRIDGE_DIR/.node/bin:$PATH"
  command -v node >/dev/null 2>&1 || fail "Node no quedó usable en $BRIDGE_DIR/.node/bin"
  log "Node.js instalado localmente: $(node -v) → $BRIDGE_DIR/.node"
}

bootstrap_bridge_files() {
  if [[ -f "$BRIDGE_DIR/server.js" && -f "$BRIDGE_DIR/package.json" ]]; then
    log "Código del bridge presente en $BRIDGE_DIR"
    return 0
  fi

  local src_url="${RIS_BRIDGE_SOURCE_URL:-}"
  if [[ -z "$src_url" && -n "${RIS_DOWNLOADS_BASE:-}" ]]; then
    src_url="${RIS_DOWNLOADS_BASE%/}/ris-local-bridge-macos.tar.gz"
  fi
  # Por defecto en Siresa (LAN). Se puede sobreescribir con RIS_DOWNLOADS_BASE / RIS_BRIDGE_SOURCE_URL.
  if [[ -z "$src_url" ]]; then
    src_url="http://192.168.0.127/downloads/ris-local-bridge-macos.tar.gz"
  fi

  log "Descargando bridge desde $src_url ..."
  local tmp
  tmp="$(mktemp -d)"
  curl -fL --retry 3 -o "$tmp/bridge.tgz" "$src_url" || fail "No se pudo descargar $src_url"
  tar -xzf "$tmp/bridge.tgz" -C "$BRIDGE_DIR"
  rm -rf "$tmp"
  [[ -f "$BRIDGE_DIR/server.js" ]] || fail "El paquete no contiene server.js"
}

write_config() {
  local horos_path naps2_path printer_iface
  if [[ -x "$HOROS_BIN" ]]; then
    horos_path="$HOROS_APP"
    log "Horos: $HOROS_BIN"
  else
    horos_path="$HOROS_APP"
    log "AVISO: Horos no está en $HOROS_APP — instálelo y vuelva a ejecutar, o edite config.json"
  fi

  naps2_path="$NAPS2_BIN"
  if [[ ! -x "$NAPS2_BIN" ]]; then
    log "NAPS2 no encontrado (opcional para escanear)."
  fi

  printer_iface="${THERMAL_PRINTER_INTERFACE:-}"

  cat >"$BRIDGE_DIR/config.json" <<EOF
{
    "viewer": "horos",
    "paths": {
        "radiant": "",
        "horos": "$horos_path",
        "osirix": "/Applications/OsiriX.app",
        "weasis": ""
    },
    "scanner": {
        "naps2_path": "$naps2_path",
        "profile": "Default"
    },
    "printer": {
        "enabled": $([ -n "$printer_iface" ] && echo true || echo false),
        "interface": "$printer_iface",
        "width_chars": 48,
        "copies": 1
    }
}
EOF
  log "config.json listo (viewer=horos)"
}

install_launchagent() {
  local plist_name target node_path
  plist_name="com.healthticloud.ris-bridge.plist"
  target="$HOME/Library/LaunchAgents/$plist_name"
  node_path="$(command -v node)"

  mkdir -p "$HOME/Library/LaunchAgents"
  cat >"$target" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.healthticloud.ris-bridge</string>
    <key>ProgramArguments</key>
    <array>
        <string>$node_path</string>
        <string>$BRIDGE_DIR/server.js</string>
    </array>
    <key>WorkingDirectory</key>
    <string>$BRIDGE_DIR</string>
    <key>RunAtLoad</key>
    <true/>
    <key>KeepAlive</key>
    <true/>
    <key>StandardOutPath</key>
    <string>$BRIDGE_DIR/bridge.log</string>
    <key>StandardErrorPath</key>
    <string>$BRIDGE_DIR/bridge-error.log</string>
    <key>EnvironmentVariables</key>
    <dict>
        <key>PATH</key>
        <string>$BRIDGE_DIR/.node/bin:/opt/homebrew/bin:/usr/local/bin:/usr/bin:/bin</string>
    </dict>
</dict>
</plist>
EOF

  launchctl bootout "gui/$(id -u)/com.healthticloud.ris-bridge" 2>/dev/null || true
  launchctl unload "$target" 2>/dev/null || true
  if launchctl bootstrap "gui/$(id -u)" "$target" 2>/dev/null; then
    launchctl enable "gui/$(id -u)/com.healthticloud.ris-bridge" 2>/dev/null || true
    launchctl kickstart -k "gui/$(id -u)/com.healthticloud.ris-bridge" 2>/dev/null || true
  else
    launchctl load "$target"
  fi
  log "LaunchAgent: $target"
}

: >"$LOG_FILE"
require_macos
log "=== Instalador RIS Local Bridge (macOS, sin Homebrew) ==="
log "Carpeta: $BRIDGE_DIR"

bootstrap_bridge_files
ensure_node

log "Instalando dependencias npm..."
cd "$BRIDGE_DIR"
npm install --no-fund --no-audit >>"$LOG_FILE" 2>&1 || fail "npm install falló. Revise $LOG_FILE"
chmod +x "$BRIDGE_DIR/start-bridge.sh" 2>/dev/null || true
chmod +x "$BRIDGE_DIR/install-macos-startup.sh" 2>/dev/null || true

write_config
install_launchagent

log "Verificando bridge..."
sleep 2
if curl -sf http://127.0.0.1:8181/health >/dev/null; then
  log "OK: bridge respondiendo en http://127.0.0.1:8181/health"
  curl -s http://127.0.0.1:8181/health | tee -a "$LOG_FILE" || true
else
  log "AVISO: el bridge aún no responde. Ejecute: $BRIDGE_DIR/.node/bin/node $BRIDGE_DIR/server.js"
fi

echo ""
echo "============================================================"
echo " INSTALACIÓN COMPLETADA (macOS / Horos)"
echo "============================================================"
echo " Bridge:  http://127.0.0.1:8181"
echo " Carpeta: $BRIDGE_DIR"
echo " Node:    $(command -v node) ($(node -v))"
echo " Log:     $LOG_FILE"
echo ""
echo " Requisitos:"
echo "  • Horos en /Applications/Horos.app"
echo "  • En RIS: Radiólogo → Visor PACS (usa este bridge)"
echo ""
echo " Detener auto-inicio:"
echo "   launchctl bootout gui/\$(id -u)/com.healthticloud.ris-bridge"
echo ""
