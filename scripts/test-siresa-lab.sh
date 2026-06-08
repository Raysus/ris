#!/usr/bin/env bash
# Pruebas operativas — laboratorio SIRESA (servidor LAN /opt/RIS)
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/RIS}"
BACKEND="${APP_DIR}/backend"
COMPOSE="docker compose -f docker-compose.lan.yml"
DATE="${DATE:-$(date +%Y%m%d)}"

cd "$BACKEND"

echo "=== 1. Contenedores ==="
$COMPOSE ps

echo ""
echo "=== 2. API health (nginx :80) ==="
curl -sS -m 10 http://127.0.0.1/api/health | head -c 400
echo ""

echo ""
echo "=== 3. Variables PACS (.env) ==="
grep -E '^ORTHANC_|^PACS_DICOM' .env || true

ORTHANC_URL="$(grep -E '^ORTHANC_URL=' .env | cut -d= -f2- | tr -d '"' || true)"
PACS_DICOM_HOST="$(grep -E '^PACS_DICOM_HOST=' .env | cut -d= -f2- | tr -d '"' || true)"
PACS_DICOM_HOST="${PACS_DICOM_HOST:-$(grep -E '^ORTHANC_HOST=' .env | cut -d= -f2- | tr -d '"' || true)}"
ORTHANC_PORT="$(grep -E '^ORTHANC_PORT=' .env | cut -d= -f2- | tr -d '"' || echo 4242)"

echo ""
echo "=== 4. Orthanc HTTP ==="
if [[ -n "$ORTHANC_URL" ]]; then
  curl -sS -m 10 "${ORTHANC_URL%/}/system" | head -c 300 || echo "FAIL HTTP Orthanc"
  echo ""
else
  echo "ORTHANC_URL vacío"
fi

echo ""
echo "=== 5. Puerto DICOM MWL (${PACS_DICOM_HOST}:${ORTHANC_PORT}) ==="
if timeout 3 bash -c "echo >/dev/tcp/${PACS_DICOM_HOST}/${ORTHANC_PORT}" 2>/dev/null; then
  echo "TCP OK"
else
  echo "TCP FAIL — Fuji no podrá bajar worklist hasta que este puerto sea alcanzable"
fi

echo ""
echo "=== 6. C-FIND worklist (salas Fuji) ==="
for spec in "FCR_PANO:CR" "FCR_MAMO:MG" "FCR_PACS:CR" "HDI5000:CR"; do
  station="${spec%%:*}"
  modality="${spec##*:}"
  echo "--- ${station} / ${modality} / ${DATE} ---"
  $COMPOSE exec -T api php artisan pacs:probe-mwl \
    --station="$station" --modality="$modality" --date="$DATE" || true
  echo ""
done

echo "=== Fin ==="
