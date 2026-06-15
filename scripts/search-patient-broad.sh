#!/usr/bin/env bash
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

for TERM in vasquez vásquez enrique hertling VASQUEZ ENRIQUE; do
  echo "=== Busqueda: $TERM ==="
  $C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
    SELECT per.names||' '||per.last_name_1||' '||COALESCE(per.last_name_2,'')||' | '||COALESCE(a.accession_number,'sin cita')||' | '||COALESCE(m.ae_title,'')
    FROM personas per
    JOIN patients pat ON pat.persona_id = per.id
    LEFT JOIN appointments a ON a.patient_id = pat.id
    LEFT JOIN machines m ON m.id = a.machine_id
    WHERE per.names ILIKE '%$TERM%' OR per.last_name_1 ILIKE '%$TERM%' OR per.last_name_2 ILIKE '%$TERM%'
    LIMIT 10;
  "
done

echo "=== Citas recientes (cualquier paciente, últimos 3 días) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, a.accession_number, a.start_time::date, m.ae_title, a.status
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN machines m ON m.id = a.machine_id
WHERE a.start_time >= CURRENT_DATE - 3
ORDER BY a.start_time DESC
LIMIT 25;
"
