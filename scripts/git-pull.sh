#!/usr/bin/env bash
# Actualiza el repo desde GitHub (rama laboratorios) y opcionalmente otras ramas.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
cd "$ROOT"

export PATH="/home/raul/.local/git-root/usr/bin:${PATH:-}"
export GIT_EXEC_PATH="/home/raul/.local/git-root/usr/lib/git-core:${GIT_EXEC_PATH:-}"

if ! GIT_TERMINAL_PROMPT=0 git ls-remote origin HEAD >/dev/null 2>&1; then
  echo "GitHub no autenticado. Configure el token primero:"
  echo "  export GITHUB_TOKEN='github_pat_...'"
  echo "  bash $ROOT/scripts/configurar-git-github.sh $ROOT"
  exit 1
fi

BRANCH="${1:-laboratorios}"
echo "=== git pull origin ${BRANCH} ==="

if git diff --quiet && git diff --cached --quiet; then
  :
else
  echo "→ Guardando cambios locales en stash..."
  git stash push -m "auto-stash antes de pull $(date +%F-%H%M)"
fi

git fetch origin --prune
git checkout "$BRANCH"
git pull origin "$BRANCH"

echo ""
echo "OK: $(git log -1 --oneline)"
echo ""
if git stash list | head -1 | grep -q .; then
  echo "Cambios locales guardados en stash. Recuperar:"
  echo "  git stash list"
  echo "  git stash pop"
fi
