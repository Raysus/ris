#!/usr/bin/env bash
# Registra la clave pública de una estación de deploy en nube y Siresa.
# Uso:
#   bash scripts/agregar-clave-estacion.sh                    # esta estación
#   bash scripts/agregar-clave-estacion.sh /ruta/clave.pub    # otra estación (ej. Fer)
set -euo pipefail

PUB_FILE="${1:-$HOME/.ssh/id_ed25519_ris.pub}"
SSH_KEY="${PUB_FILE%.pub}"

if [ ! -f "$PUB_FILE" ]; then
  echo "No existe: $PUB_FILE" >&2
  exit 1
fi

PUB="$(tr -d '\n\r' < "$PUB_FILE")"
SSH_OPTS=(-o BatchMode=yes -o ConnectTimeout=10)
[ -f "$SSH_KEY" ] && SSH_OPTS+=(-i "$SSH_KEY")

install_key() {
  local host="$1"
  echo "→ ${host}"
  ssh "${SSH_OPTS[@]}" "$host" "mkdir -p ~/.ssh && chmod 700 ~/.ssh && touch ~/.ssh/authorized_keys && chmod 600 ~/.ssh/authorized_keys && grep -qxF '${PUB}' ~/.ssh/authorized_keys || echo '${PUB}' >> ~/.ssh/authorized_keys"
}

echo "=== Registrar clave en servidores de deploy ==="
echo "Clave: $(basename "$PUB_FILE")"
echo ""

for host in ris-nube ris-siresa; do
  if install_key "$host"; then
    echo "   OK ${host}"
  else
    echo "   FALLO ${host} — use: ssh-copy-id -i ${PUB_FILE} ${host}" >&2
  fi
done

echo ""
echo "Probar:"
echo "  ssh ris-nube whoami"
echo "  ssh ris-siresa whoami"
