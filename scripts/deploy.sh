#!/usr/bin/env bash
# Despliegue unificado (nube / Siresa) — Linux o Git Bash en Windows.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
BRANCH="${BRANCH:-laboratorios}"
TARGET="${1:-}"

cd "$BACKEND"

if ! command -v tailscale >/dev/null 2>&1 || ! tailscale status >/dev/null 2>&1; then
  echo "Tailscale offline. Active VPN antes de desplegar." >&2
  exit 1
fi

for host in ris-nube ris-siresa; do
  if ! ssh -o BatchMode=yes -o ConnectTimeout=8 "$host" "echo ok" >/dev/null 2>&1; then
    echo "SSH falló en ${host}. Ejecute: bash $ROOT/scripts/agregar-clave-estacion.sh" >&2
    exit 1
  fi
done

if [ "${SKIP_GIT_PUSH:-0}" != "1" ]; then
  cd "$ROOT"
  export PATH="/home/raul/.local/git-root/usr/bin:${PATH:-}"
  export GIT_EXEC_PATH="/home/raul/.local/git-root/usr/lib/git-core:${GIT_EXEC_PATH:-}"
  git pull origin "$BRANCH" || true
  git push origin "$BRANCH"
  cd "$BACKEND"
fi

case "$TARGET" in
  nube)
    php vendor/bin/envoy run deploy-nube
    ;;
  siresa|lab)
    php vendor/bin/envoy run deploy-lab --lab=siresa_centro
    ;;
  all|"")
    php vendor/bin/envoy run deploy-nube
    php vendor/bin/envoy run deploy-lab --lab=siresa_centro
    ;;
  *)
    echo "Uso: $0 [nube|siresa|all]" >&2
    exit 1
    ;;
esac

echo "Deploy completado desde $(hostname)."
