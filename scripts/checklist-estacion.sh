#!/usr/bin/env bash
# Verifica pendientes de la estación de sistemas y aplica lo posible sin sudo.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
BACKEND="$ROOT/backend"
OK=0
WARN=0
FAIL=0

pass() { echo "  [OK]   $1"; OK=$((OK+1)); }
warn() { echo "  [PEND] $1"; WARN=$((WARN+1)); }
fail() { echo "  [FAIL] $1"; FAIL=$((FAIL+1)); }

echo "=== Checklist estación sistemas — $(hostname) ==="
echo ""

# Tailscale
if tailscale status >/dev/null 2>&1; then
  pass "Tailscale: $(tailscale ip -4 2>/dev/null)"
else
  fail "Tailscale offline"
fi

# SSH deploy
for h in ris-nube ris-siresa; do
  if ssh -o BatchMode=yes -o ConnectTimeout=6 "$h" "echo ok" >/dev/null 2>&1; then
    pass "SSH $h"
  else
    fail "SSH $h — bash scripts/agregar-clave-estacion.sh"
  fi
done

# GitHub
if ssh -o BatchMode=yes -T git@github.com 2>&1 | grep -q 'successfully authenticated'; then
  pass "GitHub SSH"
else
  warn "GitHub SSH — añada ~/.ssh/id_ed25519_ris.pub en github.com/settings/keys"
fi

export PATH="/home/raul/.local/git-root/usr/bin:${PATH:-}"
export GIT_EXEC_PATH="/home/raul/.local/git-root/usr/lib/git-core:${GIT_EXEC_PATH:-}"
cd "$ROOT"
if GIT_TERMINAL_PROMPT=0 git ls-remote origin HEAD >/dev/null 2>&1; then
  pass "Git fetch (HTTPS/token)"
else
  warn "Git sin credenciales — bash scripts/configurar-git-github.sh"
fi

# RIS local
if curl -sf http://127.0.0.1/api/health | grep -q '"status":"ok"'; then
  pass "RIS local :80 /api/health"
else
  fail "RIS local no responde"
fi

if timeout 2 bash -c 'echo >/dev/tcp/127.0.0.1/4242' 2>/dev/null; then
  pass "MWL puerto 4242"
else
  warn "Puerto 4242 cerrado — bash scripts/start-mwl.sh (requiere docker)"
fi

# .env
if grep -q '^DB_AUTO_SEED=false' "$BACKEND/.env" 2>/dev/null; then
  pass "DB_AUTO_SEED=false"
else
  warn "DB_AUTO_SEED=true — cambiar a false tras primer seed"
fi

if grep -q '^CLOUD_SYNC_SECRET=.' "$BACKEND/.env" 2>/dev/null && ! grep -q '^CLOUD_SYNC_SECRET=$' "$BACKEND/.env"; then
  pass "CLOUD_SYNC_SECRET configurado"
else
  warn "CLOUD_SYNC_SECRET vacío"
fi

# Docker CLI
if docker info >/dev/null 2>&1; then
  pass "Grupo docker (CLI)"
elif id -nG "$USER" 2>/dev/null | grep -qw docker; then
  warn "Grupo docker OK pero sesión antigua — cierre sesión y vuelva a entrar (o reinicie)"
else
  warn "Sin grupo docker — ejecute: sudo usermod -aG docker $USER && cierre sesión"
fi

# PHP Envoy
if [ -x "$BACKEND/vendor/bin/envoy" ] && source "$ROOT/scripts/dev-env.sh" 2>/dev/null && php -v >/dev/null 2>&1; then
  pass "PHP + Envoy listos"
else
  warn "PHP/Envoy — source scripts/dev-env.sh"
fi

echo ""
echo "Resumen: $OK OK, $WARN pendientes menores, $FAIL fallos"
echo ""
if [ "$FAIL" -eq 0 ]; then
  echo "Deploy: bash $ROOT/scripts/deploy.sh all"
fi
