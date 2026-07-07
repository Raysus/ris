#!/usr/bin/env bash
set -euo pipefail
cd /opt/RIS/backend
cp .env ".env.bak.$(date +%Y%m%d%H%M%S)"

sed -i 's/^MWL_PROVIDER=wlmscpfs/MWL_PROVIDER=orthanc/' .env
grep -q '^MWL_ORTHANC_MODE=' .env || echo 'MWL_ORTHANC_MODE=files' >> .env
sed -i 's/^MWL_ORTHANC_MODE=.*/MWL_ORTHANC_MODE=files/' .env
grep -q '^MWL_ORTHANC_URL=' .env || echo 'MWL_ORTHANC_URL=http://mwl:8042' >> .env
sed -i 's|^MWL_ORTHANC_URL=.*|MWL_ORTHANC_URL=http://mwl:8042|' .env
sed -i 's/^MWL_DICOM_HOST=.*/MWL_DICOM_HOST=192.168.0.127/' .env

grep -q '^WORKLIST_TIMEZONE=' .env || echo 'WORKLIST_TIMEZONE=America/Santiago' >> .env
sed -i 's/^WORKLIST_TIMEZONE=.*/WORKLIST_TIMEZONE=America\/Santiago/' .env
grep -q '^APP_LAB_TIMEZONE=' .env || echo 'APP_LAB_TIMEZONE=America/Santiago' >> .env
sed -i 's/^APP_LAB_TIMEZONE=.*/APP_LAB_TIMEZONE=America\/Santiago/' .env

sed -i 's/192.168.0.130/192.168.0.127/g' .env
sed -i 's/192.168.0.131/192.168.0.127/g' .env
grep -q '192.168.0.127' .env || sed -i 's/^SANCTUM_STATEFUL_DOMAINS=/SANCTUM_STATEFUL_DOMAINS=192.168.0.127,/' .env

echo '=== MWL / SANCTUM / TZ ==='
grep -E '^(MWL_|SANCTUM_STATEFUL|WORKLIST_TIMEZONE|APP_LAB_TIMEZONE)' .env

docker compose -f docker-compose.lan.yml --profile mwl up -d --force-recreate mwl
docker compose -f docker-compose.lan.yml up -d --force-recreate api queue
docker compose -f docker-compose.lan.yml exec -T api php artisan config:clear
docker compose -f docker-compose.lan.yml exec -T api php artisan optimize
docker compose -f docker-compose.lan.yml restart queue scheduler

docker compose -f docker-compose.lan.yml exec -T api php -r "require 'vendor/autoload.php'; \$a=require 'bootstrap/app.php'; \$a->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); echo 'provider='.config('services.mwl.provider').' mode='.config('services.mwl.orthanc_mode').' host='.config('services.mwl.dicom_host').PHP_EOL;"
