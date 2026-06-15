#!/usr/bin/env bash
# Abre puertos para acceso LAN al RIS (Docker puerto 80 + MWL DICOM).
# Ejecutar: sudo bash scripts/open-lab-firewall.sh
set -euo pipefail
if [ "$(id -u)" -ne 0 ]; then
  echo "Use: sudo bash $0" >&2
  exit 1
fi
ufw allow 80/tcp comment 'RIS HealthTiCloud (Docker LAN)'
ufw allow 4242/tcp comment 'RIS MWL DICOM'
ufw allow 8080/tcp comment 'RIS modo casa (opcional)'
ufw status
echo ""
LAN="$(hostname -I | awk '{print $1}')"
echo "Desde otra PC pruebe:"
echo "  curl -s http://${LAN}/api/health"
echo "  curl -s http://${LAN}:4242  # MWL (puerto TCP abierto)"
