#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Busqueda amplia nombre completo ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time, m.ae_title, a.status
FROM personas per
JOIN patients pat ON pat.persona_id = per.id
LEFT JOIN appointments a ON a.patient_id = pat.id
LEFT JOIN machines m ON m.id = a.machine_id
WHERE lower(per.names || ' ' || per.last_name_1 || ' ' || COALESCE(per.last_name_2,'')) LIKE '%enri%'
  AND lower(per.names || ' ' || per.last_name_1 || ' ' || COALESCE(per.last_name_2,'')) LIKE '%vasq%'
ORDER BY a.start_time DESC NULLS LAST;
"

echo "=== Citas futuras / hoy (todas) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, a.accession_number, a.start_time, m.ae_title, a.status
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN machines m ON m.id = a.machine_id
WHERE a.start_time::date >= CURRENT_DATE - 1
ORDER BY a.start_time;
"
