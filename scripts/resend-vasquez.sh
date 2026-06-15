#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Buscando Enrique Vasquez ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT a.accession_number, a.status, a.start_time, m.ae_title, m.name,
       per.names, per.last_name_1, per.last_name_2, per.rut
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN machines m ON m.id = a.machine_id
WHERE (
  per.names ILIKE '%enrique%'
  AND (per.last_name_1 ILIKE '%vasquez%' OR per.last_name_2 ILIKE '%vasquez%')
)
OR (
  (per.last_name_1 || ' ' || COALESCE(per.last_name_2,'') || ' ' || per.names) ILIKE '%vasquez%enrique%'
)
OR (
  (per.names || ' ' || per.last_name_1) ILIKE '%enrique%vasquez%'
)
ORDER BY a.start_time DESC
LIMIT 15;
"

ACC=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT a.accession_number
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
WHERE (
  per.names ILIKE '%enrique%'
  AND (per.last_name_1 ILIKE '%vasquez%' OR per.last_name_2 ILIKE '%vasquez%')
)
OR (per.names || ' ' || per.last_name_1) ILIKE '%enrique%vasquez%'
ORDER BY a.start_time DESC
LIMIT 1;
" | tr -d '\r' | head -1)

if [ -z "$ACC" ]; then
  echo "No se encontró cita para Enrique Vasquez"
  exit 1
fi

echo ""
echo "=== Reenviando worklist: $ACC ==="
$C exec -T api php scripts/resend-worklist.php "$ACC"

echo ""
echo "=== Verificación C-FIND (fecha de la cita) ==="
FECHA=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT to_char(a.start_time, 'YYYYMMDD')
FROM appointments a
WHERE a.accession_number = '$ACC' LIMIT 1;
" | tr -d '\r')
STATION=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT m.ae_title FROM appointments a JOIN machines m ON m.id=a.machine_id
WHERE a.accession_number = '$ACC' LIMIT 1;
" | tr -d '\r')
MOD=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT CASE WHEN m.ae_title LIKE 'FCR_MAMO%' THEN 'MG' WHEN m.ae_title LIKE 'HDI%' THEN 'US' ELSE 'CR' END
FROM appointments a JOIN machines m ON m.id=a.machine_id
WHERE a.accession_number = '$ACC' LIMIT 1;
" | tr -d '\r')

docker run --rm --network host darthunix/dcmtk sh -c "
dump2dcm /dev/stdin /tmp/q.dcm <<EOF
# Dicom-Data-Set
(0008,0060) CS [$MOD]
(0040,0001) AE [$STATION]
(0040,0002) DA [$FECHA]
EOF
findscu -W -v -aet $STATION -aec SIRESA_MWL 127.0.0.1 4242 /tmp/q.dcm 2>&1
" | grep -E 'PatientName|PatientID|Find Response|Pending|Success|Failed|ScheduledStation' | head -12
