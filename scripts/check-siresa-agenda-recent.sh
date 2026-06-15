#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"
echo "=== Citas creadas/actualizadas hoy ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, a.start_time AT TIME ZONE 'UTC' AS start_utc,
       a.start_time AT TIME ZONE 'America/Santiago' AS start_cl,
       a.status, m.name, a.created_at
FROM appointments a
JOIN patients pat ON pat.id=a.patient_id
JOIN personas per ON per.id=pat.persona_id
JOIN machines m ON m.id=a.machine_id
WHERE a.created_at::date = CURRENT_DATE OR a.updated_at::date = CURRENT_DATE
ORDER BY a.created_at DESC
LIMIT 15;
"
echo "=== Citas con hora 14:00 hoy (UTC o local) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, a.start_time, a.end_time, m.name
FROM appointments a
JOIN patients pat ON pat.id=a.patient_id
JOIN personas per ON per.id=pat.persona_id
JOIN machines m ON m.id=a.machine_id
WHERE (a.start_time AT TIME ZONE 'America/Santiago')::time BETWEEN '13:00' AND '15:00'
  AND (a.start_time AT TIME ZONE 'America/Santiago')::date >= CURRENT_DATE - 1
ORDER BY a.start_time DESC
LIMIT 10;
"
