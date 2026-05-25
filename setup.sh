#!/bin/bash
# Script de Setup Automatizado para RIS - Production Ready
# Uso: chmod +x setup.sh && ./setup.sh

set -e # Salir si hay error

echo "🚀 =========================================="
echo "  RIS - Sistema de Información Radiológica"
echo "  Setup Automatizado"
echo "=========================================="

# COLORES
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
NC='\033[0m' # No Color

# 1. VERIFICACIONES PREVIAS
echo -e "${YELLOW}1️⃣  Verificando pre-requisitos...${NC}"

if ! command -v docker &> /dev/null; then
    echo -e "${RED}❌ Docker no está instalado${NC}"
    exit 1
fi

if ! command -v docker-compose &> /dev/null; then
    echo -e "${RED}❌ Docker Compose no está instalado${NC}"
    exit 1
fi

if ! command -v php &> /dev/null; then
    echo -e "${RED}❌ PHP no está instalado${NC}"
    exit 1
fi

if ! command -v composer &> /dev/null; then
    echo -e "${RED}❌ Composer no está instalado${NC}"
    exit 1
fi

if ! command -v npm &> /dev/null; then
    echo -e "${RED}❌ Node.js/npm no está instalado${NC}"
    exit 1
fi

echo -e "${GREEN}✅ Todos los pre-requisitos OK${NC}\n"

# 2. SETUP BACKEND
echo -e "${YELLOW}2️⃣  Configurando Backend...${NC}"
cd backend

# Copiar .env si no existe
if [ ! -f .env ]; then
    echo "📋 Creando .env desde .env.example..."
    cp .env.example .env
    php artisan key:generate
    echo -e "${GREEN}✅ .env creado${NC}"
else
    echo -e "${YELLOW}⚠️  .env ya existe, saltando...${NC}"
fi

# Instalar dependencias
echo "📦 Instalando dependencias Composer..."
composer install --no-interaction

echo -e "${GREEN}✅ Backend configurado${NC}\n"

# 3. LEVANTAR SERVICIOS DOCKER
echo -e "${YELLOW}3️⃣  Levantando servicios Docker...${NC}"
docker-compose up -d
sleep 5 # Esperar a que PostgreSQL inicie

echo -e "${GREEN}✅ Servicios Docker iniciados${NC}\n"

# 4. MIGRACIONES (incluye índices)
echo -e "${YELLOW}4️⃣  Ejecutando migraciones...${NC}"
php artisan migrate --force

echo -e "${GREEN}✅ Migraciones completadas${NC}\n"

# 5. SEEDERS (opcional)
read -p "¿Ejecutar seeders para datos de prueba? (s/n): " -n 1 -r
echo
if [[ $REPLY =~ ^[Ss]$ ]]; then
    php artisan db:seed
    echo -e "${GREEN}✅ Datos de prueba cargados${NC}"
else
    echo -e "${YELLOW}⏭️  Seeders saltados${NC}"
fi

echo ""

# 6. SETUP FRONTEND
echo -e "${YELLOW}5️⃣  Configurando Frontend...${NC}"
cd ../frontend

npm install

echo -e "${GREEN}✅ Frontend configurado${NC}\n"

# 7. TESTS
echo -e "${YELLOW}6️⃣  Ejecutando tests...${NC}"
cd ../backend

if php artisan test --parallel 2>/dev/null; then
    echo -e "${GREEN}✅ Todos los tests pasaron${NC}"
else
    echo -e "${YELLOW}⚠️  Algunos tests fallaron (revisar logs)${NC}"
fi

echo ""

# 8. RESUMEN FINAL
echo -e "${GREEN}=========================================="
echo "  ✅ SETUP COMPLETADO CON ÉXITO"
echo "==========================================${NC}"

echo -e "\n${YELLOW}📋 SIGUIENTE PASOS:${NC}"
echo ""
echo "Backend (desarrollo):"
echo -e "  ${GREEN}cd backend && php artisan serve${NC}"
echo ""
echo "Frontend:"
echo -e "  ${GREEN}cd frontend && npm run dev${NC}"
echo ""
echo "Verificar salud de servicios:"
echo -e "  ${GREEN}php artisan health:check${NC}"
echo ""
echo "Acceder a la aplicación:"
echo "  - Frontend: http://localhost:5173"
echo "  - Backend: http://localhost:8000/api"
echo "  - DICOM (Orthanc): http://localhost:8042"
echo "  - Redis: localhost:6379"
echo ""
echo -e "${YELLOW}📊 VERIFICACIONES RECOMENDADAS:${NC}"
echo "  1. Revisar config en backend/.env"
echo "  2. Probar login en frontend"
echo "  3. Ver logs: tail -f backend/storage/logs/laravel.log"
echo "  4. Leer: DEPLOYMENT_GUIDE_ES.md"
echo ""
echo -e "${GREEN}¡Listo para entregar! 🎉${NC}"
