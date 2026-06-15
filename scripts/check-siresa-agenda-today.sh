#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"
echo "=== TZ config ==="
$C exec -T api php artisan tinker --execute="echo 'lab='.config('app.lab_timezone').' app='.config('app.timezone');"
echo "=== Citas hoy ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, a.start_time, a.end_time, a.status, m.name
FROM appointments a
JOIN patients pat ON pat.id=a.patient_id
JOIN personas per ON per.id=pat.persona_id
JOIN machines m ON m.id=a.machine_id
WHERE a.start_time::date = CURRENT_DATE
ORDER BY a.start_time;
"
