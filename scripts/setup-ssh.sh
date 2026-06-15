#!/usr/bin/env bash
# Habilita SSH para acceso remoto desde otra PC en la LAN (guía laboratorio §11.1).
# Ejecutar: sudo bash scripts/setup-ssh.sh
set -euo pipefail

if [ "$(id -u)" -ne 0 ]; then
  echo "Use: sudo bash $0" >&2
  exit 1
fi

REAL_USER="${SUDO_USER:-raul}"
LAN_IP="$(hostname -I | awk '{print $1}')"

echo "=== SSH — acceso remoto RIS ==="

apt-get update -qq
apt-get install -y openssh-server

systemctl enable --now ssh
systemctl status ssh --no-pager | head -5

# Firewall
if command -v ufw >/dev/null 2>&1; then
  ufw allow OpenSSH
  ufw status | rg -i '22|ssh' || ufw status
fi

echo ""
echo "SSH activo. Desde su otro equipo:"
echo ""
echo "  ssh ${REAL_USER}@${LAN_IP}"
echo ""
echo "Compruebe en el servidor:"
echo "  ss -tln | grep :22"
echo ""
echo "Si pide contraseña, use la de su usuario Linux (${REAL_USER})."
echo "Opcional (más seguro): copie su clave pública:"
echo "  ssh-copy-id ${REAL_USER}@${LAN_IP}"
