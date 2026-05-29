@servers([
    'nube'        => 'userit@100.104.4.114',

    {{-- Laboratorios: cada uno con su PROPIA IP Tailscale. --}}
    {{-- El destino se elige con --lab=<alias> al desplegar. Agregue uno por lab: --}}
    'lab_lautaro' => 'admin@100.104.4.20',
    // 'lab_osorno'  => 'admin@100.104.4.30',
    // 'lab_temuco'  => 'admin@100.104.4.40',
])

@setup
    $app_dir = '/var/www/ris.healthticloud.cl';
    $backend_dir = $app_dir . '/backend';

    // Stack Docker de los laboratorios (LAN)
    $compose = 'docker compose -f docker-compose.lan.yml';

    // Rama desplegada en el servidor central (nube)
    $branch_nube = 'nube';

    // Rama desplegada en laboratorios / clínicas locales
    $branch_laboratorio = 'laboratorios';

    // Rama base desde la que se crean ramas que aún no existen en origin
    $branch_fallback = 'main';

    // Lab destino (alias de @servers). Uso: envoy run deploy-lab --lab=lab_lautaro
    $lab = isset($lab) ? $lab : 'lab_lautaro';
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

{{-- Despliegue a un laboratorio: envoy run deploy-lab --lab=lab_lautaro --}}
@story('deploy-lab')
    git_pull_lab
    rebuild_lab
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

{{-- --- TAREAS PARA LABORATORIOS (Docker Desktop, stack docker-compose.lan.yml) --- --}}
{{--                                                                                 --}}
{{-- Requisitos en el servidor del lab (una sola vez):                              --}}
{{--   • Tailscale activo (este PC llega por SSH vía su IP Tailscale).              --}}
{{--   • SSH con shell bash (Linux, o WSL/Git-Bash en Windows).                     --}}
{{--   • Token de GitHub guardado para git (repo privado):                          --}}
{{--       git config --global credential.helper store                              --}}
{{--       git clone https://<TOKEN>@github.com/Raysus/ris.git {{ $app_dir }}        --}}
{{--     (tras el primer clone, los pull usan el token guardado sin volver a pedirlo)--}}

@task('git_pull_lab', ['on' => $lab])
    echo "🏥 Lab {{ $lab }}: actualizando código (rama {{ $branch_laboratorio }}, solo lectura)..."
    cd {{ $app_dir }}

    BRANCH="{{ $branch_laboratorio }}"
    FALLBACK="{{ $branch_fallback }}"

    git fetch origin --prune

    if git rev-parse --verify "origin/${BRANCH}" >/dev/null 2>&1; then
        echo "✓ Rama origin/${BRANCH} encontrada."
        git checkout "${BRANCH}" 2>/dev/null || git checkout -b "${BRANCH}" "origin/${BRANCH}"
        git reset --hard "origin/${BRANCH}"
    else
        echo "⚠ origin/${BRANCH} no existe; desplegando ${FALLBACK} (solo lectura)."
        echo "  Cree y empuje la rama ${BRANCH} desde su equipo, no desde el servidor."
        git checkout "${FALLBACK}" 2>/dev/null || git checkout -b "${FALLBACK}" "origin/${FALLBACK}"
        git reset --hard "origin/${FALLBACK}"
    fi
@endtask

@task('rebuild_lab', ['on' => $lab])
    echo "🐳 Lab {{ $lab }}: reconstruyendo y levantando contenedores..."
    cd {{ $backend_dir }}

    {{ $compose }} up -d --build

    echo "🗃️  Aplicando migraciones..."
    {{ $compose }} exec -T api php artisan migrate --force

    echo "⚙️  Optimizando..."
    {{ $compose }} exec -T api php artisan optimize

    echo "🔁 Reiniciando colas..."
    {{ $compose }} restart queue scheduler

    echo "🧹 Limpiando imágenes Docker antiguas..."
    docker image prune -f >/dev/null 2>&1 || true

    echo "✅ Lab {{ $lab }} actualizado (rama {{ $branch_laboratorio }})."
@endtask
