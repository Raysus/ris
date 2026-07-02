#!/usr/bin/env bash
# Genera certificado autofirmado para HTTPS en laboratorio LAN (micrófono / dictado web).
# Incluye todas las IPs del servidor (LAN, Tailscale) para acceso directo por IP.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CERT_DIR="${ROOT}/docker/certs"
ENV_FILE="${ROOT}/.env"

mkdir -p "${CERT_DIR}"

read_env() {
    local key="$1"
    local default="${2:-}"
    if [[ -f "${ENV_FILE}" ]]; then
        local val
        val="$(grep -E "^${key}=" "${ENV_FILE}" | tail -1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
        if [[ -n "${val}" ]]; then
            echo "${val}"
            return
        fi
    fi
    echo "${default}"
}

APP_URL="$(read_env APP_URL "https://siresamatriz.healthticloud.cl")"
HOST="$(echo "${APP_URL}" | sed -E 's#^https?://##' | cut -d/ -f1 | cut -d: -f1)"

# Todas las IPv4 del host (LAN + Tailscale), sin rangos Docker.
mapfile -t ALL_IPS < <(
    hostname -I 2>/dev/null | tr ' ' '\n' | grep -E '^[0-9]+\.[0-9]+\.[0-9]+\.[0-9]+$' | grep -Ev '^(127\.|172\.(1[6-9]|2[0-9]|3[01])\.)' | sort -u
)

pick_primary_ip() {
    local ip
    for ip in "${ALL_IPS[@]}"; do
        if [[ "${ip}" =~ ^192\.168\. ]]; then
            echo "${ip}"
            return
        fi
    done
    for ip in "${ALL_IPS[@]}"; do
        if [[ "${ip}" =~ ^10\. ]]; then
            echo "${ip}"
            return
        fi
    done
    for ip in "${ALL_IPS[@]}"; do
        if [[ "${ip}" =~ ^100\. ]]; then
            echo "${ip}"
            return
        fi
    done
    echo "${ALL_IPS[0]:-}"
}

PRIMARY_IP="${1:-$(pick_primary_ip)}"
if [[ -z "${PRIMARY_IP}" ]]; then
    echo "No se detectó IP LAN. Pase la IP como argumento: bash docker/generate-lan-tls.sh 192.168.x.x" >&2
    exit 1
fi

SAN="DNS:localhost,IP:127.0.0.1"
if [[ -n "${HOST}" && "${HOST}" != "${PRIMARY_IP}" ]]; then
    SAN="DNS:${HOST},${SAN}"
fi
for ip in "${ALL_IPS[@]}"; do
    SAN="${SAN},IP:${ip}"
done

# CN = IP principal: los usuarios que entran por IP ven un certificado coherente.
CN="${PRIMARY_IP}"
echo "Generando certificado CN=${CN}"
echo "SAN: ${SAN}"

openssl req -x509 -nodes -days 825 -newkey rsa:2048 \
    -keyout "${CERT_DIR}/privkey.pem" \
    -out "${CERT_DIR}/fullchain.pem" \
    -subj "/CN=${CN}/O=HealthTiCloud RIS LAN" \
    -addext "subjectAltName=${SAN}"

chmod 640 "${CERT_DIR}/privkey.pem"
chmod 644 "${CERT_DIR}/fullchain.pem"

echo ""
echo "Certificados en ${CERT_DIR}/"
echo "Acceso recomendado desde otras PCs:"
echo "  https://${PRIMARY_IP}/"
if [[ -n "${HOST}" && "${HOST}" != "${PRIMARY_IP}" ]]; then
    echo "  https://${HOST}/  (si el DNS/hosts apunta a este servidor)"
fi
echo ""
echo "Primera vez en cada PC: Avanzado → Continuar al sitio (certificado autofirmado)."
echo "O instale el certificado: https://${PRIMARY_IP}/ris-lan-cert.pem"
echo ""
echo "Reinicie nginx: docker compose -f docker-compose.lan.yml up -d --force-recreate web"
