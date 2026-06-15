#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Citas Enrique Vasquez Gutierrez ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, a.accession_number, a.start_time, m.ae_title, m.name, a.status
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN machines m ON m.id = a.machine_id
WHERE per.names ILIKE '%ENRIQUE VASQUEZ%'
   OR (per.names ILIKE '%enrique%' AND per.last_name_1 ILIKE '%gutierrez%')
ORDER BY a.start_time DESC;
"

ACC=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT a.accession_number
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
WHERE per.names ILIKE '%ENRIQUE VASQUEZ%'
   OR (per.names ILIKE '%enrique%' AND per.last_name_1 ILIKE '%gutierrez%')
ORDER BY a.start_time DESC
LIMIT 1;
" | tr -d '\r' | head -1)

if [ -z "$ACC" ]; then
  echo "Sin citas para Enrique Vasquez Gutierrez"
  exit 1
fi

echo ""
echo "=== Reenviando: $ACC ==="
$C exec -T api php scripts/resend-worklist.php "$ACC"

FECHA=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT to_char(a.start_time,'YYYYMMDD') FROM appointments a WHERE accession_number='$ACC';
" | tr -d '\r')
STATION=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT m.ae_title FROM appointments a JOIN machines m ON m.id=a.machine_id WHERE a.accession_number='$ACC';
" | tr -d '\r')
MOD=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT CASE WHEN m.ae_title LIKE 'FCR_MAMO%' THEN 'MG' WHEN m.ae_title LIKE 'HDI%' THEN 'US' ELSE 'CR' END
FROM appointments a JOIN machines m ON m.id=a.machine_id WHERE a.accession_number='$ACC';
" | tr -d '\r')

echo ""
echo "=== C-FIND $STATION fecha $FECHA ==="
docker run --rm --network host darthunix/dcmtk sh -c "
dump2dcm /dev/stdin /tmp/q.dcm <<EOF
# Dicom-Data-Set
(0008,0060) CS [$MOD]
(0040,0001) AE [$STATION]
(0040,0002) DA [$FECHA]
EOF
findscu -W -v -aet $STATION -aec SIRESA_MWL 127.0.0.1 4242 /tmp/q.dcm 2>&1
" | grep -E 'PatientName|PatientID|Find Response|Pending|ScheduledStation|Failed|Success' | head -12

echo ""
echo "=== Archivo .wl ==="
$C --profile mwl exec -T mwl ls -la /var/lib/orthanc/worklists/SIRESA_MWL/ | grep -i "${STATION}\|$(echo $ACC | tr -d '-')" || ls storage/app/mwl-worklists/SIRESA_MWL/
