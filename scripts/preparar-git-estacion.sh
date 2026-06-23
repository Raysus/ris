#!/usr/bin/env bash
# Deja Git listo en esta estación: identidad del repo + token GitHub + remoto seguro.
# Uso:
#   export GITHUB_TOKEN='github_pat_...'   # opcional si ya está en ~/.git-credentials
#   bash scripts/preparar-git-estacion.sh
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

GIT_NAME="${GIT_USER_NAME:-Raul}"
GIT_EMAIL="${GIT_USER_EMAIL:-raul@healthticloud.cl}"

echo "=== Preparar Git — HealthTiCloud RIS ==="
echo "Repositorio: $ROOT"
echo ""

git config --local user.name "$GIT_NAME"
git config --local user.email "$GIT_EMAIL"
echo "→ Identidad local del repo: $GIT_NAME <$GIT_EMAIL>"

git remote set-url origin https://github.com/Raysus/ris.git
echo "→ Remoto: $(git remote get-url origin)"

if [[ -n "${GITHUB_TOKEN:-}" ]]; then
  bash "$ROOT/scripts/configurar-git-github.sh" "$ROOT"
elif ! GIT_TERMINAL_PROMPT=0 git ls-remote origin HEAD >/dev/null 2>&1; then
  echo ""
  echo "GitHub no autenticado. Opciones:"
  echo "  export GITHUB_TOKEN='github_pat_...'"
  echo "  bash $ROOT/scripts/configurar-git-github.sh $ROOT"
  echo ""
  echo "Guía: docs/GIT_ACCESO_GITHUB.md"
  exit 1
else
  echo "→ Credenciales GitHub: OK"
fi

CURRENT="$(git branch --show-current)"
echo ""
echo "Rama actual: $CURRENT"
echo "Último commit: $(git log -1 --oneline)"
echo ""
echo "Comandos útiles:"
echo "  bash scripts/git-pull.sh laboratorios"
echo "  git push origin laboratorios"
echo "  bash scripts/deploy-desde-aqui.sh"
echo ""
echo "Listo."
