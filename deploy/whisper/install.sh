#!/usr/bin/env bash
# Instala / actualiza faster-whisper en el servidor nube (loopback :8765).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")" && pwd)"
VENV="$ROOT/.venv"
FFMPEG_DIR="$ROOT/bin"
export PATH="${HOME}/.local/bin:${PATH}"

cd "$ROOT"

if ! command -v uv >/dev/null 2>&1; then
  echo "==> instalando uv"
  curl -LsSf https://astral.sh/uv/install.sh | sh
  # shellcheck disable=SC1091
  source "${HOME}/.local/bin/env" 2>/dev/null || true
  export PATH="${HOME}/.local/bin:${PATH}"
fi

echo "==> venv (uv + Python 3.12)"
rm -rf "$VENV"
uv python install 3.12
uv venv --python 3.12 "$VENV"
# shellcheck disable=SC1091
source "$VENV/bin/activate"
uv pip install -r requirements.txt

echo "==> ffmpeg estático (si falta)"
mkdir -p "$FFMPEG_DIR"
if [ ! -x "$FFMPEG_DIR/ffmpeg" ]; then
  TMP="$(mktemp -d)"
  curl -fsSL -o "$TMP/ffmpeg.tar.xz" \
    "https://johnvansickle.com/ffmpeg/releases/ffmpeg-release-amd64-static.tar.xz"
  tar -xJf "$TMP/ffmpeg.tar.xz" -C "$TMP"
  SRC="$(find "$TMP" -type f -name ffmpeg | head -1)"
  cp "$SRC" "$FFMPEG_DIR/ffmpeg"
  chmod +x "$FFMPEG_DIR/ffmpeg"
  rm -rf "$TMP"
fi
"$FFMPEG_DIR/ffmpeg" -version | head -1

echo "==> precarga modelo (WHISPER_MODEL=${WHISPER_MODEL:-small})"
export PATH="$FFMPEG_DIR:$PATH"
python - <<'PY'
import os
from faster_whisper import WhisperModel
size = os.getenv("WHISPER_MODEL", "small")
print(f"Descargando/cargando modelo {size}...")
WhisperModel(size, device=os.getenv("WHISPER_DEVICE", "cpu"), compute_type=os.getenv("WHISPER_COMPUTE_TYPE", "int8"))
print("OK")
PY

echo "==> listo. Arranque: sudo systemctl enable --now ris-whisper"
echo "    Health: curl -s http://127.0.0.1:8765/health"
