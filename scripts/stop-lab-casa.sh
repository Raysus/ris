#!/usr/bin/env bash
set -euo pipefail
NGINX="/home/raul/.local/nginx-root/usr/sbin/nginx"
CONF="/home/raul/Escritorio/RIS/backend/docker/lab-casa/nginx-main.conf"

if [ -f /home/raul/.local/nginx-run/run/nginx.pid ]; then
  "$NGINX" -p /home/raul/.local/nginx-run -c "$CONF" -s quit 2>/dev/null || true
fi
if [ -f /home/raul/.local/nginx-run/run/api.pid ]; then
  kill "$(cat /home/raul/.local/nginx-run/run/api.pid)" 2>/dev/null || true
  rm -f /home/raul/.local/nginx-run/run/api.pid
fi
pkill -f 'php.*0.0.0.0:8000' 2>/dev/null || true
echo "RIS laboratorio (casa) detenido."
