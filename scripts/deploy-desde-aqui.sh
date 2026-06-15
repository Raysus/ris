#!/usr/bin/env bash
# Desde ESTE equipo (con Tailscale + SSH): push git y deploy nube / Siresa vía Envoy.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
BRANCH="${BRANCH:-laboratorios}"

cd "$BACKEND"

# Tailscale
if ! command -v tailscale >/dev/null 2>&1; then
  echo "Instale Tailscale: sudo bash $ROOT/scripts/setup-tailscale.sh" >&2
  exit 1
fi
if ! tailscale status >/dev/null 2>&1; then
  echo "Tailscale offline. Ejecute: sudo tailscale up" >&2
  exit 1
fi

# Git push (opcional)
if [ "${SKIP_GIT_PUSH:-0}" != "1" ]; then
  echo "=== git push origin ${BRANCH} ==="
  cd "$ROOT"
  bash "$ROOT/scripts/git-pull.sh" "$BRANCH" 2>/dev/null || true
  git push origin "$BRANCH" || {
    echo "Push falló. Configure token: bash scripts/configurar-git-github.sh $ROOT" >&2
    exit 1
  }
  cd "$BACKEND"
fi

# SSH a destinos Envoy
echo ""
echo "=== Comprobando SSH vía Tailscale ==="
for target in ris-nube ris-siresa; do
  echo -n "  $target ... "
  if ssh -o BatchMode=yes -o ConnectTimeout=8 "$target" "echo ok" 2>/dev/null; then
    echo ""
  else
    echo "FALLO (bash scripts/agregar-clave-estacion.sh)"
  fi
done

echo ""
read -r -p "¿Desplegar a NUBE (api.healthticloud.cl)? [s/N] " deploy_nube
if [[ "$deploy_nube" =~ ^[sS]$ ]]; then
  php vendor/bin/envoy run deploy-nube
fi

echo ""
read -r -p "¿Desplegar a SIRESA centro (--lab=siresa_centro)? [s/N] " deploy_siresa
if [[ "$deploy_siresa" =~ ^[sS]$ ]]; then
  php vendor/bin/envoy run deploy-lab --lab=siresa_centro
fi

echo ""
echo "Listo. Sync operativo (datos citas/pacientes): Admin → Sync Nube en el RIS local."
