@servers([
    // Reemplaza esta IP por la IP real de Tailscale de la clínica
    'clinica_local' => 'userit@100.X.X.X'
])

@setup
$repository = 'git@github.com:Raysus/ris.git';
$app_dir = '/var/www/ris.healthticloud.cl/backend';
@endsetup

@story('deploy', ['on' => 'clinica_local'])
modo_mantenimiento
actualizar_codigo
instalar_dependencias
actualizar_base_datos
limpiar_cache
modo_en_linea
@endstory

@task('modo_mantenimiento')
echo "🚧 Poniendo la clínica en modo mantenimiento..."
cd {{ $app_dir }}
php artisan down --render="errors::503" || true
@endtask

@task('actualizar_codigo')
echo "⬇️ Descargando la última versión desde GitHub..."
cd {{ $app_dir }}
git fetch origin main
git reset --hard origin/main
@endtask

@task('instalar_dependencias')
echo "📦 Actualizando paquetes de Composer..."
cd {{ $app_dir }}
composer install --no-interaction --quiet --no-dev --optimize-autoloader
@endtask

@task('actualizar_base_datos')
echo "🗄️ Ejecutando migraciones de base de datos..."
cd {{ $app_dir }}
php artisan migrate --force
@endtask

@task('limpiar_cache')
echo "🧹 Limpiando y reconstruyendo caché..."
cd {{ $app_dir }}
php artisan optimize:clear
php artisan config:cache
php artisan route:cache
php artisan view:cache
@endtask

@task('modo_en_linea')
echo "🚀 Levantando el sistema. ¡Actualización exitosa!"
cd {{ $app_dir }}
php artisan up
@endtask