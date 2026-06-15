#!/usr/bin/env bash
# Configura este PC como estación de deploy (Linux). Puede haber varias (ThinkCentre + Fer).
# Ejecutar una vez: bash scripts/setup-estacion-sistemas.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
LAN_IP="$(hostname -I | awk '{print $1}')"
TS_IP="$(tailscale ip -4 2>/dev/null || echo '')"
HOST="$(hostname)"
USER_NAME="$(whoami)"
SSH_KEY="$HOME/.ssh/id_ed25519_ris"

echo "╔══════════════════════════════════════════════════════════════╗"
echo "║  Estación de sistemas — ${HOST}                              "
echo "╚══════════════════════════════════════════════════════════════╝"
echo "  Usuario:    ${USER_NAME}"
echo "  IP LAN:     ${LAN_IP}"
echo "  Tailscale:  ${TS_IP:-(no conectado)}"
echo "  Repo:       ${ROOT}"
echo ""

# --- 1. Clave SSH (GitHub + nube + Siresa) ---
mkdir -p "$HOME/.ssh"
chmod 700 "$HOME/.ssh"
if [ ! -f "$SSH_KEY" ]; then
  echo "→ Generando clave SSH ${SSH_KEY}..."
  ssh-keygen -t ed25519 -C "ris-sistemas-${HOST}" -f "$SSH_KEY" -N ""
fi
chmod 600 "$SSH_KEY" "$HOME/.ssh/config" 2>/dev/null || true

echo ""
echo "=== CLAVE PÚBLICA (registrar en GitHub y servidores) ==="
cat "${SSH_KEY}.pub"
echo ""
echo "GitHub:  Settings → SSH keys → New SSH key (puede añadir varias — una por PC)"
echo "Servidores:"
echo "  bash ${ROOT}/scripts/agregar-clave-estacion.sh"
echo "  # o: ssh-copy-id -i ${SSH_KEY}.pub ris-nube && ssh-copy-id -i ${SSH_KEY}.pub ris-siresa"
echo ""
echo "Estaciones de deploy actuales en Tailscale:"
tailscale status 2>/dev/null | rg 'thinkcentre|fer|100\.104|100\.103' || true
echo ""

# --- 2. .env con IP actual ---
ENV_FILE="$BACKEND/.env"
if [ -f "$ENV_FILE" ]; then
  sed -i "s/192\.168\.100\.[0-9]\+/${LAN_IP}/g" "$ENV_FILE"
  if [ -n "$TS_IP" ]; then
    if grep -q '^SANCTUM_STATEFUL_DOMAINS=' "$ENV_FILE"; then
      sed -i "s|^SANCTUM_STATEFUL_DOMAINS=.*|SANCTUM_STATEFUL_DOMAINS=${LAN_IP},${TS_IP},localhost,127.0.0.1|" "$ENV_FILE"
    fi
  fi
  echo "→ .env actualizado (IP LAN ${LAN_IP})"
fi

# --- 3. Git remoto SSH (opcional) ---
read -r -p "¿Cambiar origin a SSH (git@github.com:Raysus/ris.git)? [s/N] " use_ssh
if [[ "$use_ssh" =~ ^[sS]$ ]]; then
  export PATH="/home/raul/.local/git-root/usr/bin:${PATH:-}"
  export GIT_EXEC_PATH="/home/raul/.local/git-root/usr/lib/git-core:${GIT_EXEC_PATH:-}"
  cd "$ROOT"
  git remote set-url origin git@github.com:Raysus/ris.git
  echo "→ origin = git@github.com:Raysus/ris.git"
fi

# --- 4. Probar Tailscale / SSH ---
echo ""
echo "=== Conectividad Tailscale ==="
tailscale status 2>/dev/null | rg '100\.104\.4\.114|100\.103\.135\.42' || echo "(revise tailscale status)"

echo ""
echo "=== Probar SSH (después de registrar la clave) ==="
for h in ris-nube ris-siresa; do
  echo -n "  $h ... "
  ssh -o BatchMode=yes -o ConnectTimeout=6 "$h" "echo ok" 2>/dev/null && echo "" || echo "pendiente (copie la clave pública)"
done

# --- 5. Docker lab local ---
echo ""
read -r -p "¿Levantar/recrear stack Docker local (puerto 80)? [s/N] " up_docker
if [[ "$up_docker" =~ ^[sS]$ ]]; then
  cd "$BACKEND"
  if docker info >/dev/null 2>&1; then
    docker compose -f docker-compose.lan.yml --profile mwl up -d --build
    docker compose -f docker-compose.lan.yml exec -T api php artisan config:clear
  else
    echo "  Docker sin permisos en esta sesión. Cierre sesión y entre de nuevo, o:"
    echo "  sudo docker compose -f docker-compose.lan.yml --profile mwl up -d --build"
  fi
fi

echo ""
echo "=== Comandos habituales (desde ${ROOT}/backend) ==="
echo "  bash ${ROOT}/scripts/deploy.sh all      # nube + Siresa"
echo "  bash ${ROOT}/scripts/deploy.sh nube"
echo "  bash ${ROOT}/scripts/deploy.sh siresa"
echo "  git pull / push origin laboratorios"
echo ""
echo "  RIS local:  http://${LAN_IP}"
echo "  MWL DICOM:  ${LAN_IP}:4242  AE SIRESA_MWL"
echo ""
