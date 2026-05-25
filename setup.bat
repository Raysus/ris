@echo off
REM Script de Setup Automatizado para RIS - Windows
REM Uso: setup.bat

echo.
echo ==========================================
echo   RIS - Sistema de Informacion Radiologica
echo   Setup Automatizado (Windows)
echo ==========================================
echo.

REM 1. VERIFICACIONES PREVIAS
echo [1/6] Verificando pre-requisitos...

where docker >nul 2>nul
if %errorlevel% neq 0 (
    echo ERROR: Docker no esta instalado
    exit /b 1
)

where php >nul 2>nul
if %errorlevel% neq 0 (
    echo ERROR: PHP no esta instalado
    exit /b 1
)

where composer >nul 2>nul
if %errorlevel% neq 0 (
    echo ERROR: Composer no esta instalado
    exit /b 1
)

where npm >nul 2>nul
if %errorlevel% neq 0 (
    echo ERROR: Node.js/npm no esta instalado
    exit /b 1
)

echo OK - Todos los pre-requisitos encontrados
echo.

REM 2. SETUP BACKEND
echo [2/6] Configurando Backend...
cd backend

if not exist .env (
    echo Creando .env desde .env.example...
    copy .env.example .env
    php artisan key:generate
    echo .env creado
) else (
    echo .env ya existe, saltando...
)

echo Instalando dependencias Composer...
call composer install --no-interaction

echo Backend configurado
echo.

REM 3. LEVANTAR SERVICIOS DOCKER
echo [3/6] Levantando servicios Docker...
docker-compose up -d
timeout /t 5 /nobreak

echo Servicios Docker iniciados
echo.

REM 4. MIGRACIONES
echo [4/6] Ejecutando migraciones...
php artisan migrate --force

echo Migraciones completadas
echo.

REM 5. SETUP FRONTEND
echo [5/6] Configurando Frontend...
cd ..\frontend

call npm install

echo Frontend configurado
echo.

REM 6. TESTS
echo [6/6] Ejecutando tests...
cd ..\backend

php artisan test --parallel

echo.
echo ==========================================
echo   SETUP COMPLETADO CON EXITO
echo ==========================================
echo.
echo SIGUIENTE PASOS:
echo.
echo Backend (desarrollo):
echo   cd backend ^&^& php artisan serve
echo.
echo Frontend:
echo   cd frontend ^&^& npm run dev
echo.
echo Acceder a:
echo   - Frontend: http://localhost:5173
echo   - Backend: http://localhost:8000/api
echo   - DICOM (Orthanc): http://localhost:8042
echo.
echo LISTO PARA ENTREGAR!
echo.
pause
