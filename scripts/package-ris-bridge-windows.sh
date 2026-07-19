#!/usr/bin/env bash
# Empaqueta tools/ris-local-bridge para Windows (sin node_modules).
set -euo pipefail
ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="$ROOT/tools/ris-local-bridge"
OUT_DIR="$ROOT/frontend/downloads"
OUT="$OUT_DIR/ris-local-bridge-windows.zip"
STAGE="$(mktemp -d)"
PKG="$STAGE/Instalar-Bridge-Windows"

mkdir -p "$OUT_DIR" "$PKG/ris-local-bridge/lib"

cp -a "$SRC/server.js" "$SRC/package.json" "$SRC/package-lock.json" "$PKG/ris-local-bridge/"
cp -a "$SRC/lib/." "$PKG/ris-local-bridge/lib/"
cp -a "$SRC/setup-scanner-windows.bat" "$PKG/"
cp -a "$SRC/configure-printer-windows.ps1" "$PKG/"
cp -a "$SRC/start-bridge.bat" "$PKG/"
cp -a "$SRC/start-bridge-hidden.vbs" "$PKG/"
cp -a "$SRC/install-windows-startup.ps1" "$PKG/"
cp -a "$SRC/Instalar-Bridge-Siempre-Encendido.bat" "$PKG/"
cp -a "$SRC/LEEME-Bridge-Siempre-Encendido.txt" "$PKG/"
cp -a "$SRC/config.example.json" "$PKG/"
[[ -f "$SRC/config.json" ]] && cp -a "$SRC/config.json" "$PKG/"
# Copia también dentro de ris-local-bridge para quien descomprima solo el código
cp -a "$SRC/setup-scanner-windows.bat" "$SRC/configure-printer-windows.ps1" \
  "$SRC/start-bridge.bat" "$SRC/start-bridge-hidden.vbs" \
  "$SRC/install-windows-startup.ps1" \
  "$SRC/Instalar-Bridge-Siempre-Encendido.bat" \
  "$SRC/LEEME-Bridge-Siempre-Encendido.txt" \
  "$SRC/config.example.json" \
  "$PKG/ris-local-bridge/"
[[ -f "$SRC/config.json" ]] && cp -a "$SRC/config.json" "$PKG/ris-local-bridge/"

# Kit corto: autoinicio siempre encendido
ALWAYS="$OUT_DIR/Instalar-Bridge-Siempre-Encendido"
rm -rf "$ALWAYS"
mkdir -p "$ALWAYS"
cp -a "$SRC/Instalar-Bridge-Siempre-Encendido.bat" "$SRC/install-windows-startup.ps1" \
  "$SRC/start-bridge-hidden.vbs" "$SRC/start-bridge.bat" "$SRC/server.js" \
  "$SRC/LEEME-Bridge-Siempre-Encendido.txt" "$ALWAYS/"
[[ -f "$SRC/config.json" ]] && cp -a "$SRC/config.json" "$ALWAYS/"
(cd "$OUT_DIR" && rm -f Instalar-Bridge-Siempre-Encendido.zip && zip -qr Instalar-Bridge-Siempre-Encendido.zip Instalar-Bridge-Siempre-Encendido)

# Kit: limpiar / reinstalar cuando el puerto 8181 no abre
CLEAN="$OUT_DIR/Limpiar-Bridge-Windows"
rm -rf "$CLEAN"
mkdir -p "$CLEAN/lib"
cp -a "$SRC/Limpiar-Reinstalar-Bridge.bat" "$SRC/limpiar-reinstalar-bridge-windows.ps1" \
  "$SRC/LEEME-Limpiar-Bridge-Windows.txt" \
  "$SRC/server.js" "$SRC/package.json" "$SRC/package-lock.json" \
  "$SRC/start-bridge.bat" "$SRC/start-bridge-hidden.vbs" \
  "$SRC/install-windows-startup.ps1" \
  "$SRC/Instalar-Bridge-Siempre-Encendido.bat" \
  "$SRC/config.example.json" \
  "$CLEAN/"
[[ -f "$SRC/config.json" ]] && cp -a "$SRC/config.json" "$CLEAN/"
cp -a "$SRC/lib/." "$CLEAN/lib/"
# También en el zip Windows completo
cp -a "$SRC/Limpiar-Reinstalar-Bridge.bat" "$SRC/limpiar-reinstalar-bridge-windows.ps1" \
  "$SRC/LEEME-Limpiar-Bridge-Windows.txt" "$PKG/" "$PKG/ris-local-bridge/" 2>/dev/null || true
cp -a "$SRC/Limpiar-Reinstalar-Bridge.bat" "$SRC/limpiar-reinstalar-bridge-windows.ps1" \
  "$SRC/LEEME-Limpiar-Bridge-Windows.txt" "$PKG/"
cp -a "$SRC/Limpiar-Reinstalar-Bridge.bat" "$SRC/limpiar-reinstalar-bridge-windows.ps1" \
  "$SRC/LEEME-Limpiar-Bridge-Windows.txt" "$PKG/ris-local-bridge/"
(cd "$OUT_DIR" && rm -f Limpiar-Bridge-Windows.zip && zip -qr Limpiar-Bridge-Windows.zip Limpiar-Bridge-Windows)

if [[ -f "$OUT_DIR/LEEME-Bridge-Windows.txt" ]]; then
  cp -f "$OUT_DIR/LEEME-Bridge-Windows.txt" "$PKG/"
else
  cat >"$PKG/LEEME-Bridge-Windows.txt" <<'EOF'
Ejecute setup-scanner-windows.bat y luego configure-printer-windows.ps1
EOF
fi

rm -f "$OUT"
(
  cd "$STAGE"
  zip -qr "$OUT" Instalar-Bridge-Windows
)

cp -f "$SRC/setup-scanner-windows.bat" "$OUT_DIR/setup-scanner-windows.bat"
cp -f "$SRC/configure-printer-windows.ps1" "$OUT_DIR/configure-printer-windows.ps1"
cp -f "$PKG/LEEME-Bridge-Windows.txt" "$OUT_DIR/LEEME-Bridge-Windows.txt"

rm -rf "$STAGE"
echo "OK $OUT ($(du -h "$OUT" | awk '{print $1}'))"
