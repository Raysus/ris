#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
C="docker compose -f docker-compose.lan.yml"

echo "=== SALAS ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "SELECT name, ae_title, ip_address FROM machines WHERE deleted_at IS NULL ORDER BY name;"

echo "=== CITAS HOY ==="
$C exec -T pgsql psql -U risuserdb -d ris_db -c "SELECT a.accession_number, m.ae_title, m.name, a.status, a.start_time FROM appointments a JOIN machines m ON m.id=a.machine_id WHERE a.start_time::date=CURRENT_DATE ORDER BY a.start_time;"

echo "=== WL FILES ==="
$C --profile mwl exec -T mwl ls -la /var/lib/orthanc/worklists/SIRESA_MWL/
ls -la storage/app/mwl-worklists/SIRESA_MWL/ 2>/dev/null || true

echo "=== C-FIND 20260609 FCR_PANO (cita reenviada) ==="
docker run --rm --network host darthunix/dcmtk findscu -S -aec SIRESA_MWL -aet FCR_PANO 127.0.0.1 4242 -k ScheduledStationAETitle=FCR_PANO -k Modality=CR -k ScheduledProcedureStepStartDate=20260609 2>&1 | tail -25

echo "=== C-FIND 20260615 FCR_PANO (hoy) ==="
docker run --rm --network host darthunix/dcmtk findscu -S -aec SIRESA_MWL -aet FCR_PANO 127.0.0.1 4242 -k ScheduledStationAETitle=FCR_PANO -k Modality=CR -k ScheduledProcedureStepStartDate=20260615 2>&1 | tail -15

echo "=== LOGS MWL ==="
$C --profile mwl logs mwl --tail 50 2>&1 | tail -30
