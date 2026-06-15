#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== Citas recientes (últimos 7 días, salas DICOM SIRESA) ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "
SELECT a.accession_number, m.ae_title, a.status, a.start_time::date AS fecha
FROM appointments a
JOIN machines m ON m.id = a.machine_id
WHERE m.ae_title IN ('FCR_PANO','FCR_MAMO','FCR_PACS','HDI5000')
  AND a.start_time >= CURRENT_DATE - INTERVAL '7 days'
  AND a.status NOT IN ('cancelada','cancelado')
  AND a.accession_number IS NOT NULL
ORDER BY a.start_time DESC;
"

echo ""
echo "=== Reenvío por sala (última cita de cada una) ==="
for ST in FCR_PANO FCR_MAMO FCR_PACS HDI5000; do
  ACC=$($C exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
    SELECT a.accession_number FROM appointments a
    JOIN machines m ON m.id = a.machine_id
    WHERE m.ae_title = '$ST'
      AND a.accession_number IS NOT NULL
    ORDER BY a.start_time DESC LIMIT 1;
  " | tr -d '\r' | head -1)
  if [ -n "$ACC" ]; then
    echo "--- $ST → $ACC ---"
    $C exec -T api php scripts/resend-worklist.php "$ACC" || true
  else
    echo "--- $ST → sin citas ---"
  fi
done

echo ""
echo "=== Archivos .wl ==="
$C --profile mwl exec -T mwl ls -la /var/lib/orthanc/worklists/SIRESA_MWL/

probe() {
  local station=$1 modality=$2 date=$3 calling=$4
  echo ""
  echo "=== C-FIND $station fecha=$date ==="
  docker run --rm --network host darthunix/dcmtk sh -c "
    dump2dcm /dev/stdin /tmp/q.dcm <<'EOF'
# Dicom-Data-Set
(0040,0100) SQ
  (fffe,e000) na
    (0008,0060) CS [$modality]
    (0040,0001) AE [$station]
    (0040,0002) DA [$date]
  (fffe,e00d) na
  (fffe,e0dd) na

EOF
    findscu -W -v -aet $calling -aec SIRESA_MWL 127.0.0.1 4242 /tmp/q.dcm 2>&1
  " | tail -20
}

# Fechas según última cita reenviada por sala
probe FCR_PANO CR 20260609 FCR_PANO
probe FCR_MAMO MG 20260608 FCR_MAMO
probe HDI5000 US 20260608 HDI5000

echo ""
echo "=== Logs MWL (últimas conexiones DICOM) ==="
$C --profile mwl logs mwl --since 30m 2>&1 | grep -iE 'DICOM|association|FCR|HDI|192\.168\.0\.|Find|reject|error|Pending' | tail -40 || echo "(sin actividad DICOM reciente desde la pano)"
