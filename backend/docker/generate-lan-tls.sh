#!/usr/bin/env bash
# CA + certificado de servidor para HTTPS en laboratorio LAN.
# Las PCs deben instalar ca.pem UNA vez (https://IP/ris-lan-ca.pem) → candado verde.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CERT_DIR="${ROOT}/docker/certs"
ENV_FILE="${ROOT}/.env"
LAB_DNS="${RIS_LAB_DNS:-siresamatriz.healthticloud.cl}"

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

APP_URL="$(read_env APP_URL "https://192.168.0.127")"
HOST="$(echo "${APP_URL}" | sed -E 's#^https?://##' | cut -d/ -f1 | cut -d: -f1)"

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
    echo "No se detectó IP LAN. Pase la IP: bash docker/generate-lan-tls.sh 192.168.0.127" >&2
    exit 1
fi

CA_KEY="${CERT_DIR}/ca-key.pem"
CA_CERT="${CERT_DIR}/ca.pem"
SERVER_KEY="${CERT_DIR}/privkey.pem"
SERVER_CSR="${CERT_DIR}/server.csr"
SERVER_CERT="${CERT_DIR}/server.pem"
FULLCHAIN="${CERT_DIR}/fullchain.pem"

# --- CA raíz del laboratorio (reutilizar si ya existe) ---
if [[ ! -f "${CA_KEY}" || ! -f "${CA_CERT}" ]]; then
    echo "Creando CA raíz del laboratorio..."
    openssl req -x509 -nodes -days 3650 -newkey rsa:2048 \
        -keyout "${CA_KEY}" \
        -out "${CA_CERT}" \
        -subj "/CN=HealthTiCloud RIS LAN CA/O=HealthTiCloud/C=CL"
fi

# --- SAN: IP LAN + alias DNS local + localhost ---
SAN_PARTS=("DNS:localhost" "DNS:${LAB_DNS}" "IP:127.0.0.1" "IP:${PRIMARY_IP}")
[[ -n "${HOST}" && "${HOST}" != "${PRIMARY_IP}" && "${HOST}" != "${LAB_DNS}" ]] && SAN_PARTS+=("DNS:${HOST}")
for ip in "${ALL_IPS[@]}"; do
    found=0
    for existing in "${SAN_PARTS[@]}"; do
        [[ "${existing}" == "IP:${ip}" ]] && found=1 && break
    done
    [[ "${found}" -eq 0 ]] && SAN_PARTS+=("IP:${ip}")
done

SAN="$(IFS=,; echo "${SAN_PARTS[*]}")"
CN="${PRIMARY_IP}"

echo "Generando certificado de servidor CN=${CN}"
echo "SAN: ${SAN}"

openssl req -new -nodes -newkey rsa:2048 \
    -keyout "${SERVER_KEY}" \
    -out "${SERVER_CSR}" \
    -subj "/CN=${CN}/O=HealthTiCloud RIS LAN/C=CL" \
    -addext "subjectAltName=${SAN}"

openssl x509 -req -in "${SERVER_CSR}" \
    -CA "${CA_CERT}" -CAkey "${CA_KEY}" -CAcreateserial \
    -out "${SERVER_CERT}" -days 825 \
    -copy_extensions copyall

cat "${SERVER_CERT}" "${CA_CERT}" > "${FULLCHAIN}"
rm -f "${SERVER_CSR}"

chmod 640 "${CA_KEY}" "${SERVER_KEY}"
chmod 644 "${CA_CERT}" "${SERVER_CERT}" "${FULLCHAIN}"

echo ""
echo "Certificados en ${CERT_DIR}/"
echo "  ca.pem          → instalar en cada PC (autoridad raíz)"
echo "  fullchain.pem   → nginx (servidor + CA)"
echo ""
echo "Acceso: https://${PRIMARY_IP}/"
echo ""
echo "En cada PC (una sola vez):"
echo "  1. Descargar https://${PRIMARY_IP}/ris-lan-ca.pem"
echo "  2. Instalar en «Autoridades de certificación raíz de confianza»"
echo "  O ejecutar: scripts/trust-ris-lan-cert-windows.ps1 (Windows, como Admin)"
echo ""
echo "Reinicie nginx: docker compose -f docker-compose.lan.yml up -d --force-recreate web"
