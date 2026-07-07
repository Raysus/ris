#!/usr/bin/env bash
# Prepara esta estación (Windows vía WSL/macOS/Linux) para RIS LAN + SpeechMike.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
RIS_HOST="${RIS_HOST:-siresamatriz.healthticloud.cl}"
RIS_IP="${RIS_IP:-}"
HOSTS_MARK="# healthticloud-ris-lan"

usage() {
  cat <<EOF
Uso: sudo bash $0 [IP_SERVIDOR_RIS]

Ejemplos:
  sudo bash $0 192.168.0.127          # PC en la LAN del centro
  sudo bash $0 100.103.135.42         # Acceso remoto por Tailscale (Siresa)

Luego abra: https://${RIS_HOST}
EOF
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
  usage
  exit 0
fi

if [[ -n "${1:-}" ]]; then
  RIS_IP="$1"
fi

if [[ -z "${RIS_IP}" ]]; then
  if ping -c 1 -W 2 192.168.0.127 >/dev/null 2>&1; then
    RIS_IP="192.168.0.127"
  elif command -v tailscale >/dev/null 2>&1 && tailscale status >/dev/null 2>&1; then
    RIS_IP="100.103.135.42"
    echo "→ Usando Tailscale Siresa: ${RIS_IP}"
  else
    echo "Pase la IP del servidor RIS como argumento." >&2
    usage
    exit 1
  fi
fi

if [[ "$(id -u)" -ne 0 ]]; then
  echo "Ejecute con sudo: sudo bash $0 ${RIS_IP}" >&2
  exit 1
fi

if ! grep -qF "${HOSTS_MARK}" /etc/hosts 2>/dev/null; then
  printf '%s\n%s %s\n' "${HOSTS_MARK}" "${RIS_IP}" "${RIS_HOST}" >> /etc/hosts
  echo "→ /etc/hosts: ${RIS_IP} ${RIS_HOST}"
else
  sed -i "s/^.*${RIS_HOST}.*$/${RIS_IP} ${RIS_HOST} ${HOSTS_MARK}/" /etc/hosts
  echo "→ /etc/hosts actualizado"
fi

RIS_IP="${RIS_IP}" RIS_HOST="${RIS_HOST}" bash "${ROOT}/scripts/trust-ris-lan-cert.sh"

echo ""
echo "=== Comprobación SpeechMike / WebHID ==="
echo "  URL:     https://${RIS_HOST}/pages/radiologist.html"
echo "  HTTPS:   $(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 8 "https://${RIS_HOST}/" 2>/dev/null || echo FAIL)"
echo "  API:     $(curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 8 "https://${RIS_HOST}/api/health" 2>/dev/null || echo FAIL)"
echo ""
echo "En el navegador (Chrome/Edge en Windows; Chrome/Edge/Safari en Mac):"
echo "  1. Cierre SpeechControl / apps Philips"
echo "  2. Radiólogo → Conectar SpeechMike"
echo "  3. Doble clic «Probar teclas» = diagnóstico"
echo ""
echo "macOS: si Safari no muestra WebHID, use Chrome."
echo "Windows: instale cert también en «Equipo local» si solo usó este script en WSL."
