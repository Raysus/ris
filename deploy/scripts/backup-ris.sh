#!/usr/bin/env bash
# Respaldos manuales o vía cron (servidor sin Docker).
# Ejemplo cron: 30 2 * * * /var/www/ris.healthticloud.cl/deploy/scripts/backup-ris.sh >> /var/log/ris-backup.log 2>&1

set -euo pipefail

APP_DIR="${RIS_APP_DIR:-/var/www/ris.healthticloud.cl/backend}"
cd "$APP_DIR"

php artisan ris:backup --keep="${BACKUP_KEEP_DAYS:-14}"
