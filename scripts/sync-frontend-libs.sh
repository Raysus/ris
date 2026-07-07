#!/usr/bin/env bash
# Descarga dependencias del frontend para operación LAN sin internet.
set -euo pipefail

ROOT="$(cd "$(dirname "$0")/.." && pwd)"
LIB="$ROOT/frontend/assets/lib"
mkdir -p "$LIB"

fetch() {
  local url="$1"
  local dest="$2"
  mkdir -p "$(dirname "$dest")"
  curl -fsSL "$url" -o "$dest"
  echo "  OK $(basename "$dest")"
}

echo "Sincronizando librerías frontend → frontend/assets/lib/"

fetch "https://cdn.jsdelivr.net/npm/jquery@3.7.1/dist/jquery.min.js" \
  "$LIB/jquery/jquery-3.7.1.min.js"

fetch "https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" \
  "$LIB/bootstrap/5.3.2/bootstrap.min.css"
fetch "https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js" \
  "$LIB/bootstrap/5.3.2/bootstrap.bundle.min.js"

fetch "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.min.css" \
  "$LIB/bootstrap-icons/1.11.1/bootstrap-icons.min.css"
fetch "https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/fonts/bootstrap-icons.woff2" \
  "$LIB/bootstrap-icons/1.11.1/fonts/bootstrap-icons.woff2"

fetch "https://cdn.jsdelivr.net/npm/fullcalendar-scheduler@6.1.11/index.global.min.js" \
  "$LIB/fullcalendar/6.1.11/scheduler.index.global.min.js"
fetch "https://cdn.jsdelivr.net/npm/@fullcalendar/list@6.1.11/index.global.min.js" \
  "$LIB/fullcalendar/6.1.11/list.index.global.min.js"
fetch "https://cdn.jsdelivr.net/npm/@fullcalendar/core@6.1.11/locales-all.global.min.js" \
  "$LIB/fullcalendar/6.1.11/locales-all.global.min.js"

fetch "https://cdnjs.cloudflare.com/ajax/libs/moment.js/2.29.4/moment.min.js" \
  "$LIB/moment/2.29.4/moment.min.js"
fetch "https://cdnjs.cloudflare.com/ajax/libs/html2pdf.js/0.10.1/html2pdf.bundle.min.js" \
  "$LIB/html2pdf/0.10.1/html2pdf.bundle.min.js"
fetch "https://cdn.jsdelivr.net/npm/jsbarcode@3.11.5/dist/JsBarcode.all.min.js" \
  "$LIB/jsbarcode/3.11.5/JsBarcode.all.min.js"
fetch "https://cdn.jsdelivr.net/npm/chart.js@4.4.1/dist/chart.umd.min.js" \
  "$LIB/chart.js/4.4.1/chart.umd.min.js"
fetch "https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js" \
  "$LIB/xlsx/0.18.5/xlsx.full.min.js"

echo "Listo. Actualice index.html y layout.html si cambian versiones."
