# HealthTiCloud RIS

Sistema de información radiológica: agenda, worklist, informes, administración y pagos.

| Componente | Tecnología |
|------------|------------|
| API | Laravel 13 · PHP 8.3+ |
| Frontend | HTML + JavaScript + Bootstrap 5 |
| Base de datos | PostgreSQL 15 |

---

## Documentación

**Guía de instalación (técnicos):** **[docs/INSTALACION.md](docs/INSTALACION.md)**

Incluye: instalación local, red LAN, producción en nube, bridge escáner/visor en cada PC, variables `.env` y fallos frecuentes.

**Manual de usuario:** `docs/INSTRUCTIVO_HealthTiCloud_RIS.pdf` o `.docx`

---

## Inicio rápido (local)

```bash
cd backend
docker compose up -d
cp .env.example .env          # Windows: Copy-Item .env.example .env
composer install
php artisan key:generate
php artisan migrate --force
php artisan db:seed
php artisan storage:link
php artisan serve --port=8000
```

```bash
cd frontend/js && cp config.example.js config.js
# Editar config.js: const API_URL = 'http://127.0.0.1:8000/api';
cd .. && python -m http.server 5500
```

Abrir **http://127.0.0.1:5500/index.html**

---

## Producción

| Servicio | URL |
|----------|-----|
| Frontend | https://ris.healthticloud.cl |
| API | https://api.healthticloud.cl |

Detalle de Nginx, colas, respaldos y deploy: **[docs/INSTALACION.md](docs/INSTALACION.md)** §4.

---

## Bridge local (escáner y visor)

En cada PC de recepción/radiología (no en el servidor):

```bash
cd tools/ris-local-bridge
npm install && npm start
```

Ver **[docs/INSTALACION.md](docs/INSTALACION.md)** §5.

---

## Tests

```bash
cd backend && php artisan test
```

---

**Versión:** 1.0.0 · Mayo 2026
