#!/usr/bin/env bash
# Empaqueta tools/ris-local-bridge para macOS (sin node_modules ni .node).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/tools/ris-local-bridge"
OUT_DIR="$ROOT/frontend/downloads"
OUT="$OUT_DIR/ris-local-bridge-macos.tar.gz"
STAGE="$(mktemp -d)"

mkdir -p "$OUT_DIR" "$STAGE/ris-local-bridge"
cp -a "$SRC/server.js" "$SRC/package.json" "$SRC/package-lock.json" "$STAGE/ris-local-bridge/"
cp -a "$SRC/lib" "$STAGE/ris-local-bridge/"
cp -a "$SRC/setup-scanner-macos.sh" "$SRC/start-bridge.sh" "$SRC/install-macos-startup.sh" "$STAGE/ris-local-bridge/"
cp -a "$SRC/config.example.json" "$STAGE/ris-local-bridge/"
cp -a "$SRC/com.healthticloud.ris-bridge.plist.example" "$STAGE/ris-local-bridge/" 2>/dev/null || true
chmod +x "$STAGE/ris-local-bridge/"*.sh

# El tarball se extrae con contents en la raíz del destino (server.js al nivel BRIDGE_DIR).
tar -C "$STAGE/ris-local-bridge" -czf "$OUT" .
cp -f "$SRC/setup-scanner-macos.sh" "$OUT_DIR/setup-scanner-macos.sh"
rm -rf "$STAGE"
echo "OK $OUT ($(du -h "$OUT" | awk '{print $1}'))"
echo "OK $OUT_DIR/setup-scanner-macos.sh"
