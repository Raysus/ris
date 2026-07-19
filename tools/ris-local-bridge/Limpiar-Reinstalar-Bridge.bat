@echo off
chcp 65001 >nul
title HealthTiCloud - Limpiar y reinstalar Bridge
cd /d "%~dp0"

echo ============================================================
echo  RIS Local Bridge — limpiar y reinstalar (Windows)
echo ============================================================
echo.
echo  Este script:
echo   - Libera el puerto 8181
echo   - Detiene el bridge / tarea vieja
echo   - Reinstala dependencias
echo   - Deja el bridge siempre encendido
echo   - Verifica http://127.0.0.1:8181/health
echo.
echo  Ejecute este .bat EN LA PC DE RECEPCION, desde esta carpeta.
echo.

if not exist "%~dp0server.js" (
  echo ERROR: No esta server.js en esta carpeta.
  pause
  exit /b 1
)

if not exist "%~dp0limpiar-reinstalar-bridge-windows.ps1" (
  echo ERROR: Falta limpiar-reinstalar-bridge-windows.ps1
  pause
  exit /b 1
)

powershell -NoProfile -ExecutionPolicy Bypass -File "%~dp0limpiar-reinstalar-bridge-windows.ps1"
set ERR=%ERRORLEVEL%
echo.
if %ERR% NEQ 0 (
  echo Termino con codigo %ERR%. Revise los mensajes de arriba.
) else (
  echo Todo OK.
)
pause
exit /b %ERR%
