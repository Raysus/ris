#!/usr/bin/env bash
# Aplica Caddyfile PACS en 172.16.66.11 y recarga caddy_pacs.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
SRC="${ROOT}/deploy/caddy/pacs.Caddyfile"
JUMP="${PACS_JUMP_HOST:-ris-nube}"
TARGET="${PACS_SSH:-debuser@172.16.66.11}"
REMOTE_FILE="/home/debuser/opt/Caddyfile"

if [ ! -f "$SRC" ]; then
  echo "No existe: $SRC" >&2
  exit 1
fi

echo "→ Copiando Caddyfile a ${TARGET}..."
scp -o "ProxyJump=${JUMP}" "$SRC" "${TARGET}:${REMOTE_FILE}"

echo "→ Validando y recargando caddy_pacs..."
ssh -J "$JUMP" "$TARGET" bash -s <<'REMOTE'
set -euo pipefail
cd /home/debuser/opt
if ! sudo docker exec caddy_pacs caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile; then
  echo "Caddyfile inválido" >&2
  exit 1
fi
sudo docker restart caddy_pacs
sleep 2
curl -fsS -o /dev/null -w "local_https:%{http_code}\n" -k \
  --resolve pacs.healthticloud.cl:443:127.0.0.1 \
  https://pacs.healthticloud.cl/system
REMOTE

echo "Listo. Acceso permitido:"
echo "  - VPN WireGuard (10.0.0.0/24): https://pacs.healthticloud.cl → 172.16.66.11"
echo "  - LAN housing (172.16.66.0/24): mismo destino"
echo "  - Tailscale (100.64.0.0/10): ThinkCentre, Fer, labs (directo vía ris-nube o Caddy)"
