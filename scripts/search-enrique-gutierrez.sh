#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Buscar Enrique + Gutierrez/Vasquez ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time, m.ae_title, m.name, a.status
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
LEFT JOIN appointments a ON a.patient_id = pat.id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE per.names ILIKE '%enrique%'
  AND (per.last_name_1 ILIKE '%gutierrez%' OR per.last_name_2 ILIKE '%gutierrez%'
       OR per.last_name_1 ILIKE '%vasquez%' OR per.last_name_1 ILIKE '%vásquez%')
ORDER BY a.start_time DESC NULLS LAST;
"

echo ""
echo "=== Buscar apellido Gutierrez + Enrique ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time, m.ae_title, a.status
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
JOIN appointments a ON a.patient_id = pat.id
JOIN machines m ON m.id = a.machine_id
WHERE per.names ILIKE '%enrique%' AND per.last_name_1 ILIKE '%gutierrez%'
ORDER BY a.start_time DESC;
"
