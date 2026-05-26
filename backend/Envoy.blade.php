@servers([
    'nube'            => 'userit@100.104.4.114',
    'clinica_lautaro' => 'admin@100.104.4.114',
])

@setup
    $app_dir = '/var/www/ris.healthticloud.cl';
    $backend_dir = $app_dir . '/backend';

    // Rama desplegada en el servidor central (nube)
    $branch_nube = 'nube';

    // Rama desplegada en laboratorios / clínicas locales
    $branch_laboratorio = 'laboratorios';

    // Rama base desde la que se crean ramas que aún no existen en origin
    $branch_fallback = 'main';
@endsetup

@story('deploy-nube')
    prepare_git_nube
    git_pull_nube
    composer_nube
    fix_permissions_nube
    migrate_nube
    optimize_nube
    fix_permissions_nube
    restart_queue_nube
@endstory

@story('deploy-clinica')
    git_pull_clinica
    composer_clinica
    migrate_clinica
    optimize_clinica
    restart_worklist
@endstory

{{-- --- TAREAS PARA LA NUBE (PostgreSQL + PHP nativos, sin Docker) --- --}}

@task('prepare_git_nube', ['on' => 'nube'])
    echo "🔓 Permisos temporales para git (storage/bootstrap)..."
    cd {{ $backend_dir }}
    DEPLOY_USER=$(whoami)
    mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache
    sudo chown -R "${DEPLOY_USER}:${DEPLOY_USER}" storage bootstrap/cache
    echo "✅ ${DEPLOY_USER} puede actualizar archivos con git."
@endtask

@task('git_pull_nube', ['on' => 'nube'])
    echo "📥 Actualizando código en la Nube (rama {{ $branch_nube }})..."
    cd {{ $app_dir }}

    BRANCH="{{ $branch_nube }}"
    FALLBACK="{{ $branch_fallback }}"

    git fetch origin --prune

    if git rev-parse --verify "origin/${BRANCH}" >/dev/null 2>&1; then
        echo "✓ Rama origin/${BRANCH} encontrada."
        git checkout "${BRANCH}" 2>/dev/null || git checkout -b "${BRANCH}" "origin/${BRANCH}"
        git reset --hard "origin/${BRANCH}"
        git pull origin "${BRANCH}"
    elif git rev-parse --verify "${BRANCH}" >/dev/null 2>&1; then
        echo "✓ Rama local ${BRANCH} encontrada."
        git checkout "${BRANCH}"
        git reset --hard HEAD
        git pull origin "${BRANCH}" || git push -u origin "${BRANCH}"
    else
        echo "⚠ Rama ${BRANCH} no existe. Creando desde origin/${FALLBACK}..."
        git checkout "${FALLBACK}" 2>/dev/null || git checkout -b "${FALLBACK}" "origin/${FALLBACK}"
        git reset --hard "origin/${FALLBACK}"
        git pull origin "${FALLBACK}"
        git checkout -b "${BRANCH}"
        git push -u origin "${BRANCH}"
    fi
@endtask

@task('composer_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    composer install --no-interaction --quiet --optimize-autoloader --no-dev
@endtask

@task('migrate_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    sudo -u www-data php artisan migrate --force
@endtask

@task('optimize_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    sudo -u www-data php artisan optimize
    echo "✅ Nube optimizada (rama {{ $branch_nube }})."
@endtask

@task('fix_permissions_nube', ['on' => 'nube'])
    echo "🔐 Restaurando permisos de storage..."
    cd {{ $backend_dir }}
    mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache
    sudo chown -R www-data:www-data storage bootstrap/cache
    sudo chmod -R ug+rwx storage bootstrap/cache
    if [ -f storage/logs/laravel.log ]; then
        sudo chown www-data:www-data storage/logs/laravel.log
    fi
    echo "✅ Permisos listos."
@endtask

@task('restart_queue_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    sudo -u www-data php artisan queue:restart
    echo "✅ Colas de la nube reiniciadas (systemd debe levantar el worker)."
@endtask

{{-- --- TAREAS PARA LABORATORIO / CLÍNICA (Docker / Sail) --- --}}

@task('git_pull_clinica', ['on' => 'clinica_lautaro'])
    echo "🏥 Actualizando código en Laboratorio (rama {{ $branch_laboratorio }})..."
    cd {{ $app_dir }}

    BRANCH="{{ $branch_laboratorio }}"
    FALLBACK="{{ $branch_fallback }}"

    git fetch origin --prune

    if git rev-parse --verify "origin/${BRANCH}" >/dev/null 2>&1; then
        echo "✓ Rama origin/${BRANCH} encontrada."
        git checkout "${BRANCH}" 2>/dev/null || git checkout -b "${BRANCH}" "origin/${BRANCH}"
        git reset --hard "origin/${BRANCH}"
        git pull origin "${BRANCH}"
    elif git rev-parse --verify "${BRANCH}" >/dev/null 2>&1; then
        echo "✓ Rama local ${BRANCH} encontrada."
        git checkout "${BRANCH}"
        git reset --hard HEAD
        git pull origin "${BRANCH}" || git push -u origin "${BRANCH}"
    else
        echo "⚠ Rama ${BRANCH} no existe. Creando desde origin/${FALLBACK}..."
        git checkout "${FALLBACK}" 2>/dev/null || git checkout -b "${FALLBACK}" "origin/${FALLBACK}"
        git reset --hard "origin/${FALLBACK}"
        git pull origin "${FALLBACK}"
        git checkout -b "${BRANCH}"
        git push -u origin "${BRANCH}"
    fi
@endtask

@task('composer_clinica', ['on' => 'clinica_lautaro'])
    cd {{ $app_dir }}
    ./vendor/bin/sail composer install --no-interaction --quiet --optimize-autoloader
@endtask

@task('migrate_clinica', ['on' => 'clinica_lautaro'])
    cd {{ $app_dir }}
    ./vendor/bin/sail artisan migrate --force
@endtask

@task('optimize_clinica', ['on' => 'clinica_lautaro'])
    cd {{ $app_dir }}
    ./vendor/bin/sail artisan optimize
@endtask

@task('restart_worklist', ['on' => 'clinica_lautaro'])
    echo "⚙️ Reiniciando colas para asegurar generación de Worklist..."
    cd {{ $app_dir }}
    ./vendor/bin/sail artisan queue:restart
    echo "✅ Laboratorio actualizado (rama {{ $branch_laboratorio }})."
@endtask
