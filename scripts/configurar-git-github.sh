#!/usr/bin/env bash
# Configura git para usar su token de GitHub (read + write) sin dejarlo en la URL del remoto.
set -euo pipefail

REPO_DIR="${1:-/opt/RIS}"
cd "$REPO_DIR"

if [[ -z "${GITHUB_TOKEN:-}" ]]; then
  echo "Pegue su token de GitHub (github_pat_... o ghp_...) y pulse Enter:"
  read -rs GITHUB_TOKEN
  echo
fi

if [[ -z "$GITHUB_TOKEN" ]]; then
  echo "Error: token vacío." >&2
  exit 1
fi

git remote set-url origin https://github.com/Raysus/ris.git
git config --global credential.helper store

CRED_FILE="${HOME}/.git-credentials"
# Formato recomendado para PAT fine-grained / classic
printf 'https://x-access-token:%s@github.com\n' "$GITHUB_TOKEN" > "$CRED_FILE"
chmod 600 "$CRED_FILE"

echo "Probando acceso a GitHub..."
if GIT_TERMINAL_PROMPT=0 git ls-remote origin HEAD >/dev/null 2>&1; then
  echo "OK: credenciales válidas."
  echo ""
  echo "Siguiente paso (si tiene cambios locales):"
  echo "  cd $REPO_DIR"
  echo "  git add ... && git commit -m 'su mensaje'"
  echo "  git push origin laboratorios"
else
  echo "Falló la autenticación. Revise:" >&2
  echo "  - Token con Contents Read and write en Raysus/ris" >&2
  echo "  - Si la org exige SSO: autorice el token en GitHub → Settings → Tokens" >&2
  exit 1
fi
