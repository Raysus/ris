#!/usr/bin/env bash
# Instala LaunchAgent para iniciar el bridge al iniciar sesion (macOS).
# Uso: chmod +x install-macos-startup.sh && ./install-macos-startup.sh

set -e
BRIDGE_DIR="$(cd "$(dirname "$0")" && pwd)"
PLIST_NAME="com.healthticloud.ris-bridge.plist"
TARGET="$HOME/Library/LaunchAgents/$PLIST_NAME"

if [ -x "$BRIDGE_DIR/.node/bin/node" ]; then
  export PATH="$BRIDGE_DIR/.node/bin:$PATH"
fi

NODE_PATH="$(command -v node || true)"
if [ -z "$NODE_PATH" ]; then
  echo "Node.js no encontrado. Ejecute primero: ./setup-scanner-macos.sh"
  exit 1
fi

chmod +x "$BRIDGE_DIR/start-bridge.sh"
[ -f "$BRIDGE_DIR/config.json" ] || cp "$BRIDGE_DIR/config.example.json" "$BRIDGE_DIR/config.json"
cd "$BRIDGE_DIR" && npm install --silent 2>/dev/null || npm install

mkdir -p "$HOME/Library/LaunchAgents"

cat > "$TARGET" <<EOF
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE plist PUBLIC "-//Apple//DTD PLIST 1.0//EN" "http://www.apple.com/DTDs/PropertyList-1.0.dtd">
<plist version="1.0">
<dict>
    <key>Label</key>
    <string>com.healthticloud.ris-bridge</string>
    <key>ProgramArguments</key>
    <array>
        <string>$NODE_PATH</string>
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
launchctl unload "$TARGET" 2>/dev/null || true
if launchctl bootstrap "gui/$(id -u)" "$TARGET" 2>/dev/null; then
  launchctl enable "gui/$(id -u)/com.healthticloud.ris-bridge" 2>/dev/null || true
  launchctl kickstart -k "gui/$(id -u)/com.healthticloud.ris-bridge" 2>/dev/null || true
else
  launchctl load "$TARGET"
fi

echo "Listo: $TARGET"
echo "Bridge activo. Probar: curl http://127.0.0.1:8181/health"
echo "Detener: launchctl bootout gui/\$(id -u)/com.healthticloud.ris-bridge"
