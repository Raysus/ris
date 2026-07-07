#!/usr/bin/env bash
# Instala la CA del RIS LAN en este equipo (Linux). Pide contraseña de administrador.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
CA_SRC="${ROOT}/backend/docker/certs/ca.pem"
CA_NAME="healthticloud-ris-lan.crt"
RIS_IP="${RIS_IP:-192.168.0.127}"
RIS_HOST="${RIS_HOST:-siresamatriz.healthticloud.cl}"

if [[ ! -f "${CA_SRC}" ]]; then
    echo "No se encontró ${CA_SRC}. Ejecute antes: cd ${ROOT}/backend && bash docker/generate-lan-tls.sh ${RIS_IP}" >&2
    exit 1
fi

install_system_ca() {
    install -m 0644 "${CA_SRC}" "/usr/local/share/ca-certificates/${CA_NAME}"
    update-ca-certificates --fresh >/dev/null
    echo "→ CA instalada en el sistema (curl, apt, etc.)"
}

install_browser_nss() {
    if ! command -v certutil >/dev/null 2>&1; then
        apt-get install -y -qq libnss3-tools >/dev/null 2>&1 || true
    fi
    if ! command -v certutil >/dev/null 2>&1; then
        echo "→ libnss3-tools no instalado; Chrome puede seguir avisando hasta instalarlo." >&2
        return 0
    fi
    local user="${SUDO_USER:-${PKEXEC_UID:+$(getent passwd "${PKEXEC_UID}" | cut -d: -f1)}}"
    user="${user:-${USER}}"
    local nss="/home/${user}/.pki/nssdb"
    mkdir -p "${nss}"
    chown -R "${user}:${user}" "/home/${user}/.pki" 2>/dev/null || true
    certutil -d "sql:${nss}" -D -n "healthticloud-ris-lan" 2>/dev/null || true
    sudo -u "${user}" certutil -d "sql:${nss}" -A -t "C,," -n "healthticloud-ris-lan" -i "${CA_SRC}" 2>/dev/null \
        || certutil -d "sql:${nss}" -A -t "C,," -n "healthticloud-ris-lan" -i "${CA_SRC}"
    echo "→ CA instalada para Chrome/Chromium (${nss})"
}

install_hosts() {
    local hosts="/etc/hosts"
    if grep -q "${RIS_HOST}" "${hosts}" 2>/dev/null; then
        echo "→ /etc/hosts ya contiene ${RIS_HOST}"
        return 0
    fi
    printf '\n# HealthTiCloud RIS LAN\n%s %s\n' "${RIS_IP}" "${RIS_HOST}" >> "${hosts}"
    echo "→ Añadido a /etc/hosts: ${RIS_IP} ${RIS_HOST}"
}

if [[ "$(id -u)" -ne 0 ]]; then
    if command -v pkexec >/dev/null 2>&1; then
        exec pkexec env RIS_IP="${RIS_IP}" RIS_HOST="${RIS_HOST}" bash "$0"
    fi
    echo "Ejecute: sudo bash $0" >&2
    exit 1
fi

install_system_ca
install_browser_nss
install_hosts

cp -f "${CA_SRC}" "/home/${SUDO_USER:-siresa-centro-servidor}/Descargas/${CA_NAME}" 2>/dev/null \
    || cp -f "${CA_SRC}" "/home/${SUDO_USER:-siresa-centro-servidor}/Downloads/${CA_NAME}" 2>/dev/null \
    || true

echo ""
echo "Listo. Cierre Chrome/Edge/Firefox por completo y abra:"
echo "  https://${RIS_IP}/"
echo "  o https://${RIS_HOST}/ (si usa el alias en hosts)"
