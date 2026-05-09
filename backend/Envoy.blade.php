@servers([
    'nube'            => 'userit@100.104.4.114',
    'clinica_lautaro' => 'admin@100.104.4.114',
])

@setup
    $app_dir = '/var/www/ris.healthticloud.cl';
    $branch  = 'main';
@endsetup

@story('deploy-nube')
    git_pull_nube
    composer_nube
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

{{-- --- TAREAS PARA LA NUBE (Instalación Tradicional) --- --}}

@task('git_pull_nube', ['on' => 'nube'])
    echo "📥 Actualizando código en la Nube..."
    cd {{ $app_dir }}
    
    # 🔒 BLINDAJE: Fuerza a descartar cualquier cambio manual local antes de descargar
    git reset --hard HEAD
    git pull origin {{ $branch }}
@endtask

@task('composer_nube', ['on' => 'nube'])
    cd {{ $app_dir }}
    composer install --no-interaction --quiet --optimize-autoloader --no-dev
@endtask

@task('migrate_nube', ['on' => 'nube'])
    cd {{ $app_dir }}
    php artisan migrate --force
@endtask

@task('optimize_nube', ['on' => 'nube'])
    cd {{ $app_dir }}
    php artisan optimize
    echo "✅ Nube optimizada."
@endtask

@task('fix_permissions_nube', ['on' => 'nube'])
    echo "🔐 Restaurando permisos de storage..."
    cd {{ $app_dir }}
    # Nota: userit debe tener permisos de sudo sin contraseña para que esto no detenga el script
    sudo chown -R www-data:www-data storage bootstrap/cache
    sudo chmod -R 775 storage bootstrap/cache
    echo "✅ Permisos listos."
@endtask

@task('restart_queue_nube', ['on' => 'nube'])
    cd {{ $app_dir }}
    php artisan queue:restart
    echo "✅ Colas de la nube reiniciadas."
@endtask


{{-- --- TAREAS PARA CLÍNICA (Entorno Docker / Sail) --- --}}

@task('git_pull_clinica', ['on' => 'clinica_lautaro'])
    echo "🏥 Actualizando código en Clínica (vía Tailscale)..."
    cd {{ $app_dir }}
    
    # 🔒 BLINDAJE: Fuerza a descartar cualquier cambio manual local antes de descargar
    git reset --hard HEAD
    git pull origin {{ $branch }}
@endtask

@task('composer_clinica', ['on' => 'clinica_lautaro'])
    cd {{ $app_dir }}
    # Ejecutamos composer a través del contenedor de Sail
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
    echo "✅ Clínica actualizada y operativa."
@endtask