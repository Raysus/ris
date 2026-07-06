#!/usr/bin/env bash
# Instala la CA del RIS LAN en macOS (Llavero del sistema).
# Requiere sudo. Luego reinicie Chrome/Edge/Safari.
set -euo pipefail

RIS_IP="${RIS_IP:-192.168.0.127}"
RIS_HOST="${RIS_HOST:-siresamatriz.healthticloud.cl}"
CERT_URL="${RIS_CERT_URL:-https://${RIS_IP}/ris-lan-ca.pem}"
TMP_CERT="/tmp/healthticloud-ris-lan-ca.pem"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Ejecute con sudo: sudo RIS_IP=${RIS_IP} RIS_HOST=${RIS_HOST} bash $0" >&2
  exit 1
fi

echo "→ Descargando CA desde ${CERT_URL} ..."
curl -skf "${CERT_URL}" -H "Host: ${RIS_HOST}" -o "${TMP_CERT}"

echo "→ Instalando CA en Llavero del sistema (requiere clave admin) ..."
security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain "${TMP_CERT}"

if ! grep -q "${RIS_HOST}" /etc/hosts 2>/dev/null; then
  printf '\n# HealthTiCloud RIS LAN\n%s %s\n' "${RIS_IP}" "${RIS_HOST}" >> /etc/hosts
  echo "→ /etc/hosts: ${RIS_IP} ${RIS_HOST}"
fi

rm -f "${TMP_CERT}"

echo ""
echo "Listo."
echo "Abra: https://${RIS_IP}/"
echo "o    : https://${RIS_HOST}/ (si usa alias DNS local)"
