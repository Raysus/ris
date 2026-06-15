#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT names, last_name_1, last_name_2, rut FROM personas
WHERE names ILIKE '%vasq%' OR last_name_1 ILIKE '%enrique%' OR names ILIKE '%enrique%'
LIMIT 30;
"
