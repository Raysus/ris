#!/usr/bin/env bash
# Acceso PACS desde esta estación SIN WireGuard: Tailscale + DNS local → ris-nube.
set -euo pipefail

PACS_TS_IP="${PACS_TS_IP:-100.104.4.114}"
HOSTS_LINE="${PACS_TS_IP} pacs.healthticloud.cl"
HOSTS_MARK="# healthticloud-pacs-tailscale"

if ! tailscale status >/dev/null 2>&1; then
  echo "Tailscale no está activo. Ejecute: sudo tailscale up" >&2
  exit 1
fi

TS_IP="$(tailscale ip -4 2>/dev/null || true)"
if [ -z "$TS_IP" ]; then
  echo "No hay IP Tailscale en este equipo." >&2
  exit 1
fi
echo "→ Tailscale OK ($TS_IP)"

code_http="$(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 8 "http://${PACS_TS_IP}:8042/system" || echo 000)"
echo "→ http://${PACS_TS_IP}:8042/system → ${code_http}"
if [ "$code_http" != "200" ]; then
  echo "No hay ruta al PACS por Tailscale. Compruebe que ris-nube (host-172-83) esté online." >&2
  exit 1
fi

if [ "$(id -u)" -eq 0 ]; then
  if ! grep -qF "$HOSTS_MARK" /etc/hosts 2>/dev/null; then
    printf '%s\n%s %s\n' "$HOSTS_MARK" "$HOSTS_LINE" >> /etc/hosts
    echo "→ Añadido a /etc/hosts: $HOSTS_LINE"
  else
    sed -i "s/^.*pacs\.healthticloud\.cl.*$/${HOSTS_LINE} ${HOSTS_MARK}/" /etc/hosts
    echo "→ /etc/hosts actualizado: $HOSTS_LINE"
  fi
else
  echo "→ Para usar https://pacs.healthticloud.cl en el navegador, ejecute:"
  echo "     sudo bash $0"
  echo "  (añade ${HOSTS_LINE} en /etc/hosts)"
  echo ""
  echo "→ Mientras tanto, abra directamente:"
  echo "     http://${PACS_TS_IP}:8042/app/explorer.html"
  exit 0
fi

code_https="$(curl -sk -o /dev/null -w '%{http_code}' --connect-timeout 8 "https://pacs.healthticloud.cl/system" || echo 000)"
echo "→ https://pacs.healthticloud.cl/system → ${code_https}"
if [ "$code_https" = "200" ]; then
  echo ""
  echo "Siguiente paso (quita el aviso de certificado en el navegador):"
  echo "  sudo bash $(dirname "$0")/trust-pacs-cert.sh"
  echo "  (reinicie el navegador después)"
  echo ""
  echo "Luego abra:"
  echo "  https://pacs.healthticloud.cl/app/explorer.html"
  echo ""
  echo "NO use la IP pública 170.246.172.84 ni la privada 172.16.66.11"
  echo "desde su casa: el housing tiene esos puertos cerrados / sin ruta."
else
  echo "HTTP por Tailscale funciona; HTTPS aún no (¿falta nginx en ris-nube?)."
  echo "Use: http://${PACS_TS_IP}:8042/app/explorer.html"
fi
