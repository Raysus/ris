#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Pacientes Vasquez/Vásquez ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.status, a.start_time::date, m.ae_title
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
LEFT JOIN appointments a ON a.patient_id = pat.id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE per.last_name_1 ILIKE '%vasquez%' OR per.last_name_2 ILIKE '%vasquez%'
   OR per.names ILIKE '%vasquez%' OR per.last_name_1 ILIKE '%vasquez%'
ORDER BY a.start_time DESC NULLS LAST
LIMIT 20;
"

echo ""
echo "=== Pacientes con Enrique en nombre ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time::date, m.ae_title
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
LEFT JOIN appointments a ON a.patient_id = pat.id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE per.names ILIKE '%enrique%'
ORDER BY a.start_time DESC NULLS LAST
LIMIT 15;
"
