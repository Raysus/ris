#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
COMPOSE="docker compose -f docker-compose.lan.yml"
TODAY=$(date +%Y%m%d)

echo "=== MWL config ==="
$COMPOSE exec -T api php artisan tinker --execute="echo config('services.mwl.provider').' mode='.config('services.mwl.orthanc_mode').' host='.config('services.mwl.dicom_host');"

echo ""
echo "=== Citas de hoy (salas DICOM) ==="
$COMPOSE exec -T pgsql psql -U risuserdb -d ris_db -t -A -F'|' -c "
SELECT a.accession_number, COALESCE(m.ae_title, m.name, 'SIN_SALA') AS station, a.status, a.start_time::date
FROM appointments a
LEFT JOIN machines m ON m.id = a.machine_id
WHERE a.start_time::date = CURRENT_DATE
  AND a.status NOT IN ('cancelada', 'cancelado', 'entregado')
  AND COALESCE(m.ae_title, m.name, '') ~* 'FCR_PANO|FCR_MAMO|FCR_PACS|HDI5000'
ORDER BY a.start_time;
"

echo ""
echo "=== Reenviando worklists de hoy ==="
ACCESSIONS=$($COMPOSE exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
SELECT a.accession_number
FROM appointments a
LEFT JOIN machines m ON m.id = a.machine_id
WHERE a.start_time::date = CURRENT_DATE
  AND a.status NOT IN ('cancelada', 'cancelado', 'entregado')
  AND COALESCE(m.ae_title, m.name, '') ~* 'FCR_PANO|FCR_MAMO|FCR_PACS|HDI5000'
  AND a.accession_number IS NOT NULL
  AND a.accession_number <> ''
ORDER BY a.start_time;
" | tr -d '\r' | sed '/^$/d')

if [ -z "$ACCESSIONS" ]; then
  echo "Sin citas hoy en salas DICOM; buscando últimas 5 citas FCR_PANO..."
  ACCESSIONS=$($COMPOSE exec -T pgsql psql -U risuserdb -d ris_db -t -A -c "
    SELECT a.accession_number FROM appointments a
    JOIN machines m ON m.id = a.machine_id
    WHERE COALESCE(m.ae_title, m.name, '') ILIKE '%FCR_PANO%'
      AND a.accession_number IS NOT NULL
    ORDER BY a.updated_at DESC LIMIT 5;
  " | tr -d '\r' | sed '/^$/d')
fi

OK=0
FAIL=0
for acc in $ACCESSIONS; do
  echo "--- Reenvío: $acc ---"
  if $COMPOSE exec -T api php scripts/resend-worklist.php "$acc"; then
    OK=$((OK+1))
  else
    FAIL=$((FAIL+1))
  fi
done
echo "Reenvíos OK=$OK FAIL=$FAIL"

echo ""
echo "=== Archivos .wl en MWL local ==="
$COMPOSE --profile mwl exec -T mwl ls -la /var/lib/orthanc/worklists/SIRESA_MWL/ 2>/dev/null | tail -20 || \
  ls -la storage/app/mwl-worklists/SIRESA_MWL/ 2>/dev/null | tail -20

echo ""
echo "=== Probe C-FIND FCR_PANO hoy ($TODAY) ==="
$COMPOSE exec -T api php artisan pacs:probe-mwl --station=FCR_PANO --modality=CR --date="$TODAY" --calling=FCR_PANO 2>&1 | tail -30

echo ""
echo "=== Probe directo findscu (dcmtk docker) ==="
docker run --rm --network backend_default darthunix/dcmtk findscu -v -S -aec SIRESA_MWL -aet FCR_PANO 192.168.0.131 4242 -k ScheduledStationAETitle=FCR_PANO -k Modality=CR -k ScheduledProcedureStepStartDate="$TODAY" 2>&1 | tail -40 || true

echo ""
echo "=== Logs MWL (asociaciones / C-FIND recientes) ==="
$COMPOSE --profile mwl logs mwl --tail 100 2>&1 | grep -iE 'association|C-FIND|FCR_PANO|192\.168\.0\.128|worklist|reject|error|Find' | tail -30 || true
