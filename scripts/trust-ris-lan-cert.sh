#!/usr/bin/env bash
# Confía en el certificado HTTPS del RIS de laboratorio (SpeechMike / WebHID / dictado).
# Linux: almacén del sistema. macOS: Llavero del sistema (requiere sudo).
set -euo pipefail

RIS_HOST="${RIS_HOST:-siresamatriz.healthticloud.cl}"
RIS_IP="${RIS_IP:-}"
CERT_URL="${RIS_CERT_URL:-}"
CA_LOCAL="/tmp/ris-lan-cert-trust.pem"
CA_NAME="healthticloud-ris-lan.crt"

if [[ -z "${CERT_URL}" ]]; then
  if [[ -z "${RIS_IP}" ]]; then
  if ping -c 1 -W 2 "${RIS_HOST}" >/dev/null 2>&1; then
      RIS_IP="$(getent hosts "${RIS_HOST}" | awk '{print $1; exit}')"
    fi
  fi
  if [[ -z "${RIS_IP}" ]]; then
    echo "Indique IP del servidor RIS: RIS_IP=192.168.0.127 sudo bash $0" >&2
    echo "O URL del cert: RIS_CERT_URL=https://IP/ris-lan-cert.pem sudo bash $0" >&2
    exit 1
  fi
  CERT_URL="https://${RIS_IP}/ris-lan-ca.pem"
fi

echo "→ Descargando certificado desde ${CERT_URL} ..."
curl -skf "${CERT_URL}" -H "Host: ${RIS_HOST}" -o "${CA_LOCAL}"

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Ejecute con sudo: sudo bash $0" >&2
  exit 1
fi

OS="$(uname -s)"
case "${OS}" in
  Darwin)
    security add-trusted-cert -d -r trustRoot -k /Library/Keychains/System.keychain "${CA_LOCAL}"
    echo "→ Certificado instalado en Llavero del sistema (macOS)."
    ;;
  Linux)
    install -m 0644 "${CA_LOCAL}" "/usr/local/share/ca-certificates/${CA_NAME}"
    update-ca-certificates --fresh >/dev/null
    if command -v certutil >/dev/null 2>&1; then
      for db in /etc/pki/nssdb "${SUDO_USER:+/home/${SUDO_USER}/.pki/nssdb}"; do
        [[ -d "${db}" ]] || continue
        certutil -d "sql:${db}" -D -n "healthticloud-ris-lan" 2>/dev/null || true
        certutil -d "sql:${db}" -A -t "C,," -n "healthticloud-ris-lan" -i "${CA_LOCAL}" 2>/dev/null || true
      done
    fi
    echo "→ Certificado instalado (Linux)."
    ;;
  *)
    echo "SO no soportado (${OS}). Importe ${CA_LOCAL} manualmente como CA de confianza." >&2
    exit 1
    ;;
esac

rm -f "${CA_LOCAL}"

if curl -sSf -o /dev/null --connect-timeout 8 "https://${RIS_HOST}/api/health"; then
  echo "Listo. Abra https://${RIS_HOST} y reinicie Chrome/Edge/Safari."
else
  echo "Certificado instalado. Añada en /etc/hosts: <IP-servidor> ${RIS_HOST}"
  echo "  sudo bash $(dirname "$0")/setup-ris-lab-workstation.sh"
fi
