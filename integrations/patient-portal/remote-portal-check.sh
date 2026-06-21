#!/bin/bash
set -euo pipefail
echo "=== RIS DB test ==="
sudo docker exec portal_app_nuevo php artisan tinker --execute="echo DB::connection('ris_db')->selectOne('select 1 as ok')->ok;"
echo
echo "=== Entregables count ==="
sudo docker exec portal_app_nuevo php artisan tinker --execute="echo DB::connection('ris_db')->table('appointments')->whereIn('status',['entregable','entregado'])->count();"
echo
echo "=== Caddy portal block ==="
grep -A6 'portal.healthticloud.cl' /home/debuser/caddy/config/Caddyfile
