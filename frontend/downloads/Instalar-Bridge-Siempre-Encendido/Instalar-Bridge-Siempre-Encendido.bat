@echo off
chcp 65001 >nul
title HealthTiCloud - Bridge siempre encendido
cd /d "%~dp0"

echo ============================================================
echo  RIS Local Bridge — inicio automatico (siempre encendido)
echo ============================================================
echo.
echo  Este script registra una Tarea Programada de Windows para que
echo  el bridge arranque oculto al iniciar sesion y se reinicie solo.
echo.
echo  Debe ejecutarse EN LA PC DE RECEPCION, desde la carpeta del bridge
echo  (donde estan server.js y start-bridge.bat).
echo.

if not exist "%~dp0start-bridge-hidden.vbs" (
  echo ERROR: No se encuentra start-bridge-hidden.vbs en esta carpeta.
  echo Copie toda la carpeta Instalar-Bridge-Siempre-Encendido dentro de
  echo la carpeta del bridge en la PC de recepcion y vuelva a ejecutar.
  pause
  exit /b 1
)

if not exist "%~dp0start-bridge.bat" (
  echo ERROR: No se encuentra start-bridge.bat en esta carpeta.
  pause
  exit /b 1
)

if not exist "%~dp0server.js" (
  echo AVISO: No hay server.js aqui. Si el bridge ya esta instalado en otra
  echo carpeta, copie estos archivos ahi y ejecute este .bat desde alli.
  echo.
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0install-windows-startup.ps1"
set ERR=%ERRORLEVEL%
echo.
if %ERR% NEQ 0 (
  echo Fallo la instalacion. Codigo: %ERR%
  pause
  exit /b %ERR%
)

echo.
echo Probando health en http://127.0.0.1:8181/health ...
powershell -NoProfile -Command "try { $r = Invoke-WebRequest -UseBasicParsing http://127.0.0.1:8181/health -TimeoutSec 5; Write-Host $r.Content } catch { Write-Host 'Aun no responde. Espere unos segundos o inicie sesion de nuevo.' }"
echo.
echo Listo. Puede cerrar esta ventana.
pause
