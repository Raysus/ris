@echo off
title RIS Local Bridge
cd /d "%~dp0"

if not exist "node_modules\" (
    echo Instalando dependencias...
    call npm install
)

if not exist "config.json" (
    echo Copiando config.example.json a config.json — edite rutas de visor y escaner.
    copy /Y config.example.json config.json
)

echo Iniciando bridge en http://127.0.0.1:8181
node server.js
rem Propaga el codigo de salida de node para que la Tarea Programada lo reinicie si cae.
exit /b %errorlevel%
