#!/usr/bin/env bash
# Instala Tailscale en este servidor (guía laboratorio §11).
# Ejecutar: sudo bash scripts/setup-tailscale.sh
# Luego:    sudo tailscale up   (o con --auth-key=tskey-auth-...)
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Use: sudo bash $0" >&2
  exit 1
fi

REAL_USER="${SUDO_USER:-raul}"
LAN_IP="$(hostname -I | awk '{print $1}')"

echo "=== Tailscale — acceso remoto / deploy Envoy ==="

if ! command -v tailscale >/dev/null 2>&1; then
  curl -fsSL https://tailscale.com/install.sh | sh
fi

systemctl enable --now tailscaled

# SSH recomendado junto con Tailscale (guía §11.1)
if ! dpkg -l openssh-server 2>/dev/null | grep -q '^ii'; then
  apt-get update -qq
  apt-get install -y openssh-server
  systemctl enable --now ssh
  ufw allow OpenSSH 2>/dev/null || true
fi

echo ""
echo "Tailscale instalado. Conecte el nodo (elija UNA opción):"
echo ""
echo "  1) Login interactivo:"
echo "       sudo tailscale up"
echo ""
echo "  2) Clave de auth (la entrega sistemas):"
echo "       sudo tailscale up --auth-key=tskey-auth-XXXXXXXX"
echo ""
echo "Compruebe:"
echo "  tailscale status"
echo "  tailscale ip -4    # anote la IP 100.x.x.x"
echo ""
echo "Desde su otro PC (con Tailscale):"
echo "  ssh ${REAL_USER}@$(tailscale ip -4 2>/dev/null || echo '100.x.x.x')"
echo ""
echo "Datos para Envoy (backend/Envoy.blade.php):"
echo "  IP LAN:      ${LAN_IP}"
echo "  IP Tailscale: (ejecute: tailscale ip -4)"
echo "  Usuario SSH: ${REAL_USER}"
echo "  Ruta RIS:    /home/raul/Escritorio/RIS"
