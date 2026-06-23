#!/bin/sh
set -e

# Fallback: si no se definió APP_KEY en .env, genera una temporal para este
# contenedor. RECOMENDADO: fijar APP_KEY en .env (ver guía LAN, paso 3) para que
# api, queue y scheduler compartan la misma clave.
if [ -z "${APP_KEY}" ]; then
    APP_KEY="$(php artisan key:generate --show)"
    export APP_KEY
    echo "AVISO: APP_KEY no definido en .env; usando una clave temporal generada."
fi

case "$1" in
  serve)
    echo "Esperando a PostgreSQL en ${DB_HOST:-pgsql}:${DB_PORT:-5432}..."
    until pg_isready -h "${DB_HOST:-pgsql}" -p "${DB_PORT:-5432}" >/dev/null 2>&1; do
        sleep 2
    done

    php artisan config:clear
    php artisan migrate --force

    # Sembrar datos solo si se pide explícitamente (evita re-seed en cada reinicio).
    if [ "${DB_AUTO_SEED}" = "true" ]; then
        SEED_CLASS="${DB_SEED_CLASS:-DatabaseSeeder}"
        php artisan db:seed --class="${SEED_CLASS}" --force || true
    fi

    php artisan storage:link || true
    php artisan optimize
    exec php artisan serve --host=0.0.0.0 --port=8000
    ;;
  queue)
    exec php artisan queue:work --sleep=3 --tries=3 --timeout=120
    ;;
  scheduler)
    exec php artisan schedule:work
    ;;
  *)
    exec "$@"
    ;;
esac
