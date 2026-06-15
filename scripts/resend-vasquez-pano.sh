#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"
ACC="ACC-20260608-019EA89A"

echo "=== Paciente de la cita $ACC ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT per.names, per.last_name_1, per.last_name_2, per.rut,
       a.accession_number, a.start_time, m.ae_title, a.status
FROM appointments a
JOIN patients pat ON pat.id = a.patient_id
JOIN personas per ON per.id = pat.persona_id
JOIN machines m ON m.id = a.machine_id
WHERE a.accession_number = '$ACC';
"

echo ""
echo "=== Reenviando worklist ==="
$C exec -T api php scripts/resend-worklist.php "$ACC"

echo ""
echo "=== C-FIND pano (Broad Query, 08-jun-2026) ==="
docker run --rm --network host darthunix/dcmtk sh -c '
dump2dcm /dev/stdin /tmp/q.dcm <<EOF
# Dicom-Data-Set
(0008,0060) CS [CR]
(0040,0001) AE [FCR_PANO]
(0040,0002) DA [20260608]
EOF
findscu -W -v -aet FCR_PANO -aec SIRESA_MWL 127.0.0.1 4242 /tmp/q.dcm 2>&1
' | grep -E 'PatientName|PatientID|Find Response|Pending|ProcedureStepDescription|Failed' | head -10
