#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Laboratorios en este servidor ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT id, name, is_active FROM laboratories ORDER BY name;
"

echo ""
echo "=== Busqueda: Enrique + Vasquez/Vásquez (todos los labs) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT l.name AS laboratorio, per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time,
       a.start_time AT TIME ZONE 'America/Santiago' AS hora_chile,
       a.status, m.name AS sala, m.ae_title
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN laboratories l ON l.id = a.laboratory_id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE (
  (per.names ILIKE '%enrique%' AND (per.last_name_1 ILIKE '%vasquez%' OR per.last_name_1 ILIKE '%vásquez%' OR per.last_name_2 ILIKE '%vasquez%' OR per.last_name_2 ILIKE '%vásquez%'))
  OR (per.names ILIKE '%enrique%' AND per.last_name_1 ILIKE '%vasq%')
  OR (per.last_name_1 ILIKE '%vasquez%' OR per.last_name_1 ILIKE '%vásquez%') AND per.names ILIKE '%enrique%'
)
ORDER BY a.start_time DESC;
"

echo ""
echo "=== Busqueda amplia: cualquier Vasquez/Vásquez (todos los labs, citas recientes) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT l.name AS laboratorio, per.names, per.last_name_1, per.last_name_2,
       a.start_time AT TIME ZONE 'America/Santiago' AS hora_chile,
       a.status, m.name AS sala
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN laboratories l ON l.id = a.laboratory_id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE per.last_name_1 ILIKE '%vasq%' OR per.last_name_2 ILIKE '%vasq%'
ORDER BY a.start_time DESC
LIMIT 25;
"

echo ""
echo "=== Citas HOY (todos los laboratorios) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT l.name AS laboratorio, per.names, per.last_name_1,
       a.start_time AT TIME ZONE 'America/Santiago' AS hora_chile,
       a.status, m.name AS sala
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN laboratories l ON l.id = a.laboratory_id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE (a.start_time AT TIME ZONE 'America/Santiago')::date = (NOW() AT TIME ZONE 'America/Santiago')::date
ORDER BY a.start_time;
"

echo ""
echo "=== Usuarios/personas con nombre Enrique (sin filtro apellido) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut, l.name AS lab_paciente
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
JOIN laboratories l ON l.id = pat.laboratory_id
WHERE per.names ILIKE '%enrique%'
ORDER BY per.last_name_1, l.name
LIMIT 20;
"
