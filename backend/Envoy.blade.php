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
    ensure_frontend_config_nube
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
    if sudo -n chown -R "${DEPLOY_USER}:${DEPLOY_USER}" storage bootstrap/cache 2>/dev/null; then
        echo "✅ chown para git (sudo -n)."
    else
        echo "⚠ sudo pide contraseña o no está permitido."
        echo "  En el servidor (root, una vez): sudo bash {{ $app_dir }}/deploy/scripts/server-setup-deploy-user.sh"
        echo "  O: sudo cp {{ $app_dir }}/deploy/sudoers-ris-deploy.example /etc/sudoers.d/ris-deploy && sudo visudo -c -f /etc/sudoers.d/ris-deploy"
    fi
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

@task('ensure_frontend_config_nube', ['on' => 'nube'])
    CONFIG="{{ $app_dir }}/frontend/js/config.js"
    if [ ! -f "${CONFIG}" ]; then
        echo "⚠ config.js ausente; usando config.example.js"
        cp "{{ $app_dir }}/frontend/js/config.example.js" "${CONFIG}"
    fi
    if ! grep -q 'API_URL' "${CONFIG}" 2>/dev/null; then
        echo "⚠ config.js inválido; restaurando desde config.example.js"
        cp "{{ $app_dir }}/frontend/js/config.example.js" "${CONFIG}"
    fi
    echo "✅ frontend/js/config.js presente."
@endtask

@task('composer_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    composer install --no-interaction --quiet --optimize-autoloader --no-dev
@endtask

@task('migrate_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    ARTISAN="{{ $backend_dir }}/artisan"
    if sudo -n -u www-data /usr/bin/php "${ARTISAN}" migrate --force; then
        echo "✅ migrate (www-data)."
    elif php artisan migrate --force; then
        echo "✅ migrate (${USER:-deploy}, ACL storage; sudo-rs puede ignorar NOPASSWD -u www-data)."
    else
        echo "❌ migrate falló. Revise deploy/sudoers-ris-deploy.example o permisos storage."
        exit 1
    fi
@endtask

@task('optimize_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    ARTISAN="{{ $backend_dir }}/artisan"
    if sudo -n -u www-data /usr/bin/php "${ARTISAN}" optimize; then
        echo "✅ Nube optimizada (www-data, rama {{ $branch_nube }})."
    elif php artisan optimize; then
        echo "✅ Nube optimizada (${USER:-deploy}, rama {{ $branch_nube }})."
    else
        echo "❌ optimize falló. Revise permisos storage/bootstrap."
        exit 1
    fi
@endtask

@task('fix_permissions_nube', ['on' => 'nube'])
    echo "🔐 Restaurando permisos de storage..."
    cd {{ $backend_dir }}
    WEB_USER=www-data
    DEPLOY_USER=$(whoami)
    mkdir -p storage/logs storage/framework/cache storage/framework/sessions storage/framework/views storage/app/public bootstrap/cache
    if sudo -n chown -R "${DEPLOY_USER}:${WEB_USER}" storage bootstrap/cache 2>/dev/null \
        && sudo -n chmod -R ug+rwx storage bootstrap/cache 2>/dev/null; then
        find storage bootstrap/cache -type d -exec sudo -n chmod g+s {} \; 2>/dev/null || true
        echo "✅ Permisos ${DEPLOY_USER}:${WEB_USER}."
    elif sudo -n chown -R www-data:www-data storage bootstrap/cache 2>/dev/null \
        && sudo -n chmod -R ug+rwx storage bootstrap/cache 2>/dev/null; then
        echo "✅ Permisos www-data (sudo -n)."
    else
        chmod -R ug+rwx storage bootstrap/cache 2>/dev/null || true
        echo "⚠ Sin sudo -n: chmod local solo. Ejecute server-setup-deploy-user.sh en el servidor."
    fi
@endtask

@task('restart_queue_nube', ['on' => 'nube'])
    cd {{ $backend_dir }}
    ARTISAN="{{ $backend_dir }}/artisan"
    if sudo -n -u www-data /usr/bin/php "${ARTISAN}" queue:restart; then
        echo "✅ Colas reiniciadas (www-data)."
    elif php artisan queue:restart; then
        echo "✅ Colas reiniciadas (${USER:-deploy})."
    else
        echo "❌ queue:restart falló."
        exit 1
    fi
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
