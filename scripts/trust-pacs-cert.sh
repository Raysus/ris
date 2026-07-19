#!/usr/bin/env bash
# Instala la CA mkcert del PACS en esta estación (elimina aviso de certificado en el navegador).
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
REMOTE="${NUBE_SSH:-ris-nube}"
CA_LOCAL="/tmp/healthticloud-pacs-rootCA.pem"
CA_NAME="healthticloud-pacs-mkcert.crt"

echo "→ Descargando CA desde ${REMOTE}..."
scp "${REMOTE}:/etc/ssl/mkcert/rootCA.pem" "$CA_LOCAL"

if [ "$(id -u)" -ne 0 ]; then
  echo "Ejecute con sudo: sudo bash $0" >&2
  exit 1
fi

install -m 0644 "$CA_LOCAL" "/usr/local/share/ca-certificates/${CA_NAME}"
update-ca-certificates --fresh >/dev/null

# Chrome/Chromium en Linux suele usar el almacén del sistema; Firefox usa NSS.
if command -v certutil >/dev/null 2>&1; then
  for db in /etc/pki/nssdb "$HOME/.pki/nssdb"; do
    [ -d "$db" ] || continue
    certutil -d "sql:${db}" -D -n "healthticloud-pacs-mkcert" 2>/dev/null || true
    certutil -d "sql:${db}" -A -t "C,," -n "healthticloud-pacs-mkcert" -i "$CA_LOCAL" 2>/dev/null || true
  done
fi

rm -f "$CA_LOCAL"

if curl -sS -o /dev/null -w '%{http_code}' --connect-timeout 8 https://pacs.healthticloud.cl/system | grep -q 200; then
  echo "Listo. Reinicie el navegador y abra https://pacs.healthticloud.cl"
else
  echo "CA instalada. Ejecute antes: sudo bash ${ROOT}/scripts/setup-pacs-directo.sh"
fi
