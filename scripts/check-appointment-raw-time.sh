#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT accession_number, start_time, end_time,
       start_time AT TIME ZONE 'UTC' AS utc_wall,
       timezone('America/Santiago', start_time) AS santiago
FROM appointments
WHERE accession_number LIKE '%019EAD93%' OR created_at::date >= '2026-06-15'
ORDER BY created_at DESC LIMIT 5;
"
