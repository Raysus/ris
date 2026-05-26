#!/usr/bin/env bash
# RIS Local Bridge — macOS / Linux
cd "$(dirname "$0")"

if [ ! -d node_modules ]; then
  echo "Instalando dependencias..."
  npm install
fi

if [ ! -f config.json ]; then
  echo "Copiando config.example.json → config.json (edite rutas del visor)."
  cp config.example.json config.json
fi

echo "Iniciando bridge en http://127.0.0.1:8181"
exec node server.js
