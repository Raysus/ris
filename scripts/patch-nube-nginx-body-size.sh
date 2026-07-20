#!/usr/bin/env bash
# Sube client_max_body_size en nginx de la nube (evita HTTP 413 al sync de audios/PDFs).
# Uso en ris-nube: sudo bash scripts/patch-nube-nginx-body-size.sh
set -euo pipefail

CONF="${NGINX_CONF:-/etc/nginx/sites-available/healthticloud}"
SIZE="${BODY_SIZE:-64M}"

if [[ ! -f "$CONF" ]]; then
  echo "No existe $CONF" >&2
  exit 1
fi

if grep -q "client_max_body_size" "$CONF"; then
  echo "Ya hay client_max_body_size en $CONF"
else
  python3 - "$CONF" "$SIZE" <<'PY'
import sys
from pathlib import Path
conf, size = Path(sys.argv[1]), sys.argv[2]
text = conf.read_text()
# Bloque proxy api.healthticloud.cl → :8000
proxy = text.find("proxy_pass http://127.0.0.1:8000;")
if proxy < 0:
    raise SystemExit("No se encontró proxy_pass a :8000")
block = text.rfind("server {", 0, proxy)
sn = text.find("server_name api.healthticloud.cl;", block, proxy)
if sn < 0:
    raise SystemExit("No se encontró server_name api en el bloque proxy")
end = text.find("\n", sn)
text = text[: end + 1] + f"\n    client_max_body_size {size};\n" + text[end + 1 :]
# Bloque interno :8000
listen = text.find("listen 127.0.0.1:8000;")
if listen > 0:
    end2 = text.find("\n", listen)
    text = text[: end2 + 1] + f"\n    client_max_body_size {size};\n" + text[end2 + 1 :]
conf.write_text(text)
print(f"Parcheado {conf} → client_max_body_size {size}")
PY
fi

echo "upload_max_filesize = 64M
post_max_size = 64M
memory_limit = 512M" > /etc/php/8.4/fpm/conf.d/99-ris-uploads.ini
cp /etc/php/8.4/fpm/conf.d/99-ris-uploads.ini /etc/php/8.4/cli/conf.d/99-ris-uploads.ini

nginx -t
systemctl reload nginx
systemctl reload php8.4-fpm
echo "OK: nginx + php-fpm recargados (${SIZE})"
