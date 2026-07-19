#!/usr/bin/env bash
# Aplica ACLs PACS (nginx en ris-nube + Caddy en 172.16.66.11) vía WireGuard housing.
# Requisitos: ruta a 172.16.66.10 (WG o LAN) y sudo de userit en host-172-83.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
KEY="${SSH_KEY:-$HOME/.ssh/id_ed25519_ris}"
NUBE="${NUBE_SSH:-userit@172.16.66.10}"
PACS_HOST="${PACS_SSH:-debuser@172.16.66.11}"
SSH=(ssh -o BatchMode=yes -o ConnectTimeout=12 -i "$KEY" -o IdentitiesOnly=yes)
SCP=(scp -o BatchMode=yes -o ConnectTimeout=12 -i "$KEY" -o IdentitiesOnly=yes)

echo "→ Staging configs en ${NUBE}"
"${SCP[@]}" \
  "$ROOT/deploy/nginx/pacs-healthticloud.conf" \
  "$ROOT/deploy/caddy/pacs.Caddyfile" \
  "$ROOT/deploy/sudoers-ris-deploy.example" \
  "${NUBE}:/tmp/"

echo "→ Instalando sudoers + nginx PACS en ris-nube"
"${SSH[@]}" "$NUBE" 'bash -s' <<'REMOTE'
set -euo pipefail
if ! sudo -n true 2>/dev/null; then
  echo "Falta sudo sin contraseña para userit. Instale sudoers primero:" >&2
  echo "  sudo cp /tmp/sudoers-ris-deploy.example /etc/sudoers.d/ris-deploy" >&2
  echo "  sudo chmod 440 /etc/sudoers.d/ris-deploy && sudo visudo -c -f /etc/sudoers.d/ris-deploy" >&2
  exit 1
fi
sudo cp /tmp/sudoers-ris-deploy.example /etc/sudoers.d/ris-deploy
sudo chmod 440 /etc/sudoers.d/ris-deploy
sudo visudo -c -f /etc/sudoers.d/ris-deploy
sudo cp /tmp/pacs-healthticloud.conf /etc/nginx/sites-available/pacs-healthticloud
sudo ln -sf /etc/nginx/sites-available/pacs-healthticloud /etc/nginx/sites-enabled/pacs-healthticloud
sudo nginx -t
sudo systemctl reload nginx
echo "nginx PACS OK"
grep -E 'allow |deny ' /etc/nginx/sites-available/pacs-healthticloud
REMOTE

echo "→ Caddy en ${PACS_HOST} (ProxyJump ${NUBE})"
if "${SSH[@]}" -J "$NUBE" "$PACS_HOST" 'echo CADDY_HOST_OK' 2>/dev/null; then
  "${SCP[@]}" -o "ProxyJump=${NUBE}" \
    "$ROOT/deploy/caddy/pacs.Caddyfile" \
    "${PACS_HOST}:/home/debuser/opt/Caddyfile"
  "${SSH[@]}" -J "$NUBE" "$PACS_HOST" 'bash -s' <<'REMOTE'
set -euo pipefail
cd /home/debuser/opt
sudo docker exec caddy_pacs caddy validate --config /etc/caddy/Caddyfile --adapter caddyfile
sudo docker restart caddy_pacs
sleep 2
curl -fsS -o /dev/null -w "caddy_https:%{http_code}\n" -k \
  --resolve pacs.healthticloud.cl:443:127.0.0.1 \
  https://pacs.healthticloud.cl/system
head -8 Caddyfile
REMOTE
else
  echo "⚠ Sin SSH a ${PACS_HOST}. Nginx en ris-nube sí quedó aplicado."
  echo "  Autorice la clave de esta estación en debuser@.ssh/authorized_keys"
fi

echo "Listo."
