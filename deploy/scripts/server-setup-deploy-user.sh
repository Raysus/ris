#!/usr/bin/env bash
# Ejecutar UNA VEZ en el servidor como root (SSH).
# Deja storage/bootstrap compartido entre userit (deploy/git) y www-data (PHP).
#
#   sudo bash deploy/scripts/server-setup-deploy-user.sh

set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/ris.healthticloud.cl}"
BACKEND="${APP_DIR}/backend"
DEPLOY_USER="${DEPLOY_USER:-userit}"
WEB_USER="${WEB_USER:-www-data}"

if [ "$(id -u)" -ne 0 ]; then
  echo "Ejecute como root: sudo bash $0"
  exit 1
fi

echo "==> Repo: ${APP_DIR}"
echo "==> Deploy: ${DEPLOY_USER} · Web: ${WEB_USER}"

mkdir -p "${BACKEND}/storage/logs" \
  "${BACKEND}/storage/framework/cache" \
  "${BACKEND}/storage/framework/sessions" \
  "${BACKEND}/storage/framework/views" \
  "${BACKEND}/storage/app/public" \
  "${BACKEND}/bootstrap/cache"

# Propiedad mixta: userit dueño, grupo www-data (PHP escribe por grupo)
chown -R "${DEPLOY_USER}:${WEB_USER}" "${BACKEND}/storage" "${BACKEND}/bootstrap/cache"
chmod -R ug+rwx "${BACKEND}/storage" "${BACKEND}/bootstrap/cache"
find "${BACKEND}/storage" "${BACKEND}/bootstrap/cache" -type d -exec chmod g+s {} \;

# ACL: archivos nuevos (logs, cache) editables por userit y www-data
if command -v setfacl >/dev/null 2>&1; then
  setfacl -R -m "u:${DEPLOY_USER}:rwx" -m "u:${WEB_USER}:rwx" \
    "${BACKEND}/storage" "${BACKEND}/bootstrap/cache"
  setfacl -R -d -m "u:${DEPLOY_USER}:rwx" -m "u:${WEB_USER}:rwx" \
    "${BACKEND}/storage" "${BACKEND}/bootstrap/cache"
  echo "==> ACL aplicadas."
fi

# Sudo sin contraseña para Envoy (sin Defaults requiretty: incompatible con sudo-rs)
SUDOERS_SRC="${APP_DIR}/deploy/sudoers-ris-deploy.example"
if [ -f "${SUDOERS_SRC}" ]; then
  cp "${SUDOERS_SRC}" /etc/sudoers.d/ris-deploy
  chmod 440 /etc/sudoers.d/ris-deploy
  if visudo -c -f /etc/sudoers.d/ris-deploy 2>/dev/null; then
    echo "==> /etc/sudoers.d/ris-deploy instalado (visudo OK)."
  else
    echo "⚠ visudo reportó error; valide manualmente el archivo."
    echo "  Si el script dejó storage/ACL listos, puede continuar y corregir sudoers después."
  fi
else
  echo "⚠ No se encontró ${SUDOERS_SRC}. Copie manualmente deploy/sudoers-ris-deploy.example"
fi

echo "✅ Listo. Pruebe: sudo -n -u ${WEB_USER} php ${BACKEND}/artisan --version"
