#!/usr/bin/env bash
# Instala proxy HTTPS pacs.healthticloud.cl en ris-nube (Tailscale, sin WireGuard en cliente).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CONF_SRC="$ROOT/deploy/nginx/pacs-healthticloud.conf"
REMOTE="${NUBE_SSH:-ris-nube}"
REMOTE_CONF="/etc/nginx/sites-available/pacs-healthticloud"

if [ ! -f "$CONF_SRC" ]; then
  echo "No se encuentra $CONF_SRC" >&2
  exit 1
fi

echo "→ Copiando nginx PACS a ${REMOTE}..."
scp "$CONF_SRC" "${REMOTE}:/tmp/pacs-healthticloud.conf"

ssh -t "$REMOTE" 'bash -s' <<'REMOTE'
set -euo pipefail
sudo cp /tmp/pacs-healthticloud.conf /etc/nginx/sites-available/pacs-healthticloud
sudo ln -sf /etc/nginx/sites-available/pacs-healthticloud /etc/nginx/sites-enabled/pacs-healthticloud
sudo nginx -t
sudo systemctl reload nginx
echo "nginx PACS recargado."
REMOTE

echo ""
echo "En esta estación (sin WireGuard):"
echo "  sudo bash scripts/setup-pacs-directo.sh"
echo "  → https://pacs.healthticloud.cl"
