@echo off
setlocal EnableExtensions EnableDelayedExpansion
title HealthTiCloud - Instalacion escaner (RIS Bridge)
cd /d "%~dp0"

set "BRIDGE_DIR=%~dp0"
set "TASK_NAME=HealthTiCloud-RIS-Local-Bridge"
set "LOG_FILE=%BRIDGE_DIR%setup-scanner.log"

echo. > "%LOG_FILE%"
call :log "=== Instalador RIS Local Bridge ==="
call :log "Carpeta: %BRIDGE_DIR%"

REM --- 1. Node.js ---
call :log "[1/6] Comprobando Node.js..."
where node >nul 2>&1
if errorlevel 1 (
    call :log "Node.js no encontrado. Intentando instalar con winget..."
    where winget >nul 2>&1
    if errorlevel 1 (
        call :fail "winget no esta disponible. Instale Node.js LTS manualmente desde https://nodejs.org y vuelva a ejecutar este script."
    )
    winget install --id OpenJS.NodeJS.LTS -e --accept-package-agreements --accept-source-agreements >> "%LOG_FILE%" 2>&1
    if errorlevel 1 (
        call :fail "No se pudo instalar Node.js con winget. Instalelo manualmente y vuelva a ejecutar este script."
    )
    call :log "Node.js instalado. Actualizando PATH de esta sesion..."
    if exist "%ProgramFiles%\nodejs\node.exe" set "PATH=%ProgramFiles%\nodejs;%PATH%"
    if exist "%LocalAppData%\Programs\nodejs\node.exe" set "PATH=%LocalAppData%\Programs\nodejs;%PATH%"
)

where node >nul 2>&1
if errorlevel 1 (
    call :fail "Node.js sigue sin detectarse. Cierre esta ventana, abra una nueva CMD y ejecute de nuevo setup-scanner-windows.bat"
)
for /f "delims=" %%v in ('node -v 2^>nul') do call :log "Node.js: %%v"

REM --- 2. NAPS2 ---
call :log "[2/6] Comprobando NAPS2..."
set "NAPS2_EXE="
if exist "%ProgramFiles%\NAPS2\NAPS2.Console.exe" set "NAPS2_EXE=%ProgramFiles%\NAPS2\NAPS2.Console.exe"
if not defined NAPS2_EXE if exist "%ProgramFiles(x86)%\NAPS2\NAPS2.Console.exe" set "NAPS2_EXE=%ProgramFiles(x86)%\NAPS2\NAPS2.Console.exe"

if not defined NAPS2_EXE (
    call :log "NAPS2 no encontrado. Intentando instalar con winget..."
    where winget >nul 2>&1
    if errorlevel 1 (
        call :fail "Instale NAPS2 manualmente desde https://www.naps2.com/download y vuelva a ejecutar este script."
    )
    winget install --id NAPS2.NAPS2 -e --accept-package-agreements --accept-source-agreements >> "%LOG_FILE%" 2>&1
    if exist "%ProgramFiles%\NAPS2\NAPS2.Console.exe" set "NAPS2_EXE=%ProgramFiles%\NAPS2\NAPS2.Console.exe"
    if not defined NAPS2_EXE if exist "%ProgramFiles(x86)%\NAPS2\NAPS2.Console.exe" set "NAPS2_EXE=%ProgramFiles(x86)%\NAPS2\NAPS2.Console.exe"
)

if not defined NAPS2_EXE (
    call :fail "NAPS2 no encontrado tras la instalacion. Instalelo desde https://www.naps2.com/download"
)
call :log "NAPS2: %NAPS2_EXE%"

REM --- 3. config.json ---
call :log "[3/6] Configurando config.json..."
powershell -NoProfile -ExecutionPolicy Bypass -File "%BRIDGE_DIR%configure-printer-windows.ps1" >> "%LOG_FILE%" 2>&1
if errorlevel 1 (
    call :log "AVISO: impresora no detectada; configure manualmente con configure-printer-windows.ps1"
    powershell -NoProfile -ExecutionPolicy Bypass -Command ^
      "$naps2 = '%NAPS2_EXE%';" ^
      "$cfg = @{ viewer = 'radiant'; paths = @{ radiant = 'C:\Program Files\RadiAntViewer64bit\RadiAntViewer.exe'; horos = 'C:\Program Files\Horos\Horos.exe'; osirix = ''; weasis = 'C:\Program Files\Weasis\Weasis.exe' }; scanner = @{ naps2_path = $naps2; profile = 'Default' }; printer = @{ enabled = $false; interface = ''; width_chars = 42; copies = 1; cut_feed_lines = 6 } };" ^
      "if (-not (Test-Path '%BRIDGE_DIR%config.json')) { $cfg | ConvertTo-Json -Depth 5 | Set-Content -Path '%BRIDGE_DIR%config.json' -Encoding UTF8 }"
) else (
    call :log "Impresora termica configurada en config.json"
)
if not exist "%BRIDGE_DIR%config.json" call :fail "No se pudo crear config.json"
call :log "config.json listo (perfil escaner: Default)"

REM --- 4. npm install ---
call :log "[4/6] Instalando dependencias npm..."
if not exist "%BRIDGE_DIR%node_modules\" (
    call npm install --no-fund --no-audit >> "%LOG_FILE%" 2>&1
    if errorlevel 1 call :fail "npm install fallo. Revise %LOG_FILE%"
) else (
    call :log "node_modules ya existe; omitiendo npm install."
)

REM --- 5. Inicio automatico al iniciar sesion ---
call :log "[5/6] Registrando inicio automatico (Tarea programada)..."
powershell -NoProfile -ExecutionPolicy Bypass -File "%BRIDGE_DIR%install-windows-startup.ps1" >> "%LOG_FILE%" 2>&1
if errorlevel 1 call :fail "No se pudo registrar la tarea programada. Ejecute install-windows-startup.ps1 manualmente."

REM --- 6. Arrancar y verificar ---
call :log "[6/6] Iniciando bridge y comprobando salud..."
powershell -NoProfile -Command "Start-ScheduledTask -TaskName '%TASK_NAME%' -ErrorAction SilentlyContinue" >> "%LOG_FILE%" 2>&1
timeout /t 3 /nobreak >nul

powershell -NoProfile -Command ^
  "try { $r = Invoke-WebRequest -UseBasicParsing -TimeoutSec 5 http://127.0.0.1:8181/health; if ($r.StatusCode -eq 200) { exit 0 } else { exit 1 } } catch { exit 1 }"
if errorlevel 1 (
    call :log "AVISO: el bridge aun no responde en http://127.0.0.1:8181/health"
    call :log "Inicie manualmente: start-bridge.bat"
) else (
    call :log "OK: bridge respondiendo en http://127.0.0.1:8181/health"
)

echo.
echo ============================================================
echo  INSTALACION COMPLETADA
echo ============================================================
echo.
echo  Bridge:     http://127.0.0.1:8181
echo  Log:        %LOG_FILE%
echo  Tarea:      %TASK_NAME% (inicio al iniciar sesion, oculto)
echo.
echo  IMPORTANTE - Configure el escaner en NAPS2 (solo una vez):
echo    1. Abra "NAPS2" desde el menu Inicio
echo    2. Perfil -^> Nuevo perfil llamado "Default"
echo    3. Elija su escaner (USB) y guarde
echo.
echo  Prueba en el RIS: Agenda -^> nueva cita -^> Escanear orden
echo.
echo  Para quitar el inicio automatico:
echo    powershell Unregister-ScheduledTask -TaskName '%TASK_NAME%' -Confirm:$false
echo.
pause
exit /b 0

:log
echo %~1
echo %~1>> "%LOG_FILE%"
exit /b 0

:fail
echo.
echo ERROR: %~1
echo ERROR: %~1>> "%LOG_FILE%"
echo Revise el log: %LOG_FILE%
pause
exit /b 1
