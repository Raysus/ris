#!/usr/bin/env bash
# WireGuard (VasquezIT) — solo si no usa Tailscale directo.
# Preferido en estación sistemas: scripts/setup-pacs-directo.sh (sin VPN).
set -euo pipefail

WG_CONF="${WG_CONF:-$HOME/Descargas/VasquezIT.conf}"
HOSTS_LINE="172.16.66.11 pacs.healthticloud.cl"

if [ ! -f "$WG_CONF" ]; then
  echo "No se encuentra: $WG_CONF" >&2
  exit 1
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "Ejecute con sudo: sudo bash $0" >&2
  exit 1
fi

if ! ip link show wg0 &>/dev/null; then
  echo "→ Levantando WireGuard ($WG_CONF)..."
  wg-quick up "$WG_CONF"
else
  echo "→ WireGuard ya activo (wg0)"
fi

if ! grep -qE '^[[:space:]]*172\.16\.66\.11[[:space:]]+pacs\.healthticloud\.cl' /etc/hosts; then
  echo "→ Añadiendo $HOSTS_LINE a /etc/hosts"
  printf '%s\n' "$HOSTS_LINE" >> /etc/hosts
else
  echo "→ /etc/hosts ya tiene pacs.healthticloud.cl → 172.16.66.11"
fi

echo "→ Comprobando..."
ping -c 1 -W 3 172.16.66.11 >/dev/null
code=$(curl -sS -o /dev/null -w '%{http_code}' -k --connect-timeout 10 "https://pacs.healthticloud.cl/system")
echo "HTTPS pacs.healthticloud.cl/system → $code"
if [ "$code" = "200" ]; then
  echo "Listo. Abra https://pacs.healthticloud.cl en el navegador."
else
  echo "Falló ($code). Revise que el housing (170.246.172.83:13231) esté accesible." >&2
  exit 1
fi
