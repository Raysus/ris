#!/usr/bin/env bash
# Aplica URLs y secretos al .env LAN del backend (secretos vía variables de entorno).
# Uso:
#   export CLOUD_SYNC_SECRET='...'
#   export KEYCLOAK_ADMIN_USER='...'
#   export KEYCLOAK_ADMIN_PASSWORD='...'
#   bash scripts/apply-lan-secrets.sh [IP_LAN]
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
ENV_FILE="$ROOT/backend/.env"
LAN_IP="${1:-$(hostname -I 2>/dev/null | awk '{print $1}')}"

if [ ! -f "$ENV_FILE" ]; then
  cp "$ROOT/backend/.env.lan.example" "$ENV_FILE"
  echo "Creado $ENV_FILE desde .env.lan.example"
fi

if [ -n "$LAN_IP" ]; then
  sed -i "s/192\.168\.1\.50/${LAN_IP}/g" "$ENV_FILE"
  sed -i "s|http://siresamatriz.healthticloud.cl|http://${LAN_IP}|g" "$ENV_FILE"
fi

set_kv() {
  local key="$1" val="$2"
  if grep -q "^${key}=" "$ENV_FILE"; then
    sed -i "s|^${key}=.*|${key}=${val}|" "$ENV_FILE"
  else
    echo "${key}=${val}" >> "$ENV_FILE"
  fi
}

set_kv 'ORTHANC_URL' 'https://pacs.healthticloud.cl'
set_kv 'PACS_DICOM_HOST' '170.246.172.84'
set_kv 'VIEWER_URL' 'https://viewer.healthticloud.cl'
set_kv 'PATIENT_PORTAL_URL' 'https://portal.healthticloud.cl'
set_kv 'KEYCLOAK_BASE_URL' 'https://sso.healthticloud.cl'
set_kv 'KEYCLOAK_REALM' 'patient-portal'

if [ -z "${CLOUD_SYNC_SECRET:-}" ]; then
  echo "AVISO: CLOUD_SYNC_SECRET no definido; no se modifica en .env" >&2
else
  set_kv 'CLOUD_SYNC_SECRET' "$CLOUD_SYNC_SECRET"
fi

if [ -n "${KEYCLOAK_ADMIN_USER:-}" ]; then
  set_kv 'KEYCLOAK_ADMIN_USER' "$KEYCLOAK_ADMIN_USER"
fi

if [ -n "${KEYCLOAK_ADMIN_PASSWORD:-}" ]; then
  set_kv 'KEYCLOAK_ADMIN_PASSWORD' "$KEYCLOAK_ADMIN_PASSWORD"
fi

echo "Configuración LAN aplicada en $ENV_FILE (IP: ${LAN_IP:-sin cambio})"
echo "Revise APP_KEY, DB_PASSWORD y DB_SEED_CLASS si es instalación nueva."
