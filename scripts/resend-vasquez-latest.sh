#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Todos con apellido Vásquez ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time, m.ae_title, a.status
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
JOIN appointments a ON a.patient_id = pat.id
JOIN machines m ON m.id = a.machine_id
WHERE per.last_name_1 ILIKE '%vásquez%' OR per.last_name_1 ILIKE '%vasquez%'
ORDER BY a.start_time DESC;
"

echo "=== Cita más reciente Vásquez (para reenvío) ==="
ACC=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT a.accession_number
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN machines m ON m.id = a.machine_id
WHERE per.last_name_1 ILIKE '%vásquez%'
ORDER BY a.start_time DESC
LIMIT 1;
" | tr -d '\r')
echo "Accession: $ACC"

if [ -n "$ACC" ]; then
  echo ""
  echo "=== Reenviando ==="
  $C exec -T api php scripts/resend-worklist.php "$ACC"
fi
