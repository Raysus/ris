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

**Despliegue en un laboratorio (paso a paso, no técnico):** **[docs/GUIA_INSTALACION_LABORATORIO.md](docs/GUIA_INSTALACION_LABORATORIO.md)** (Ubuntu). Windows: **[docs/GUIA_INSTALACION_LABORATORIO_WINDOWS.md](docs/GUIA_INSTALACION_LABORATORIO_WINDOWS.md)**.

RIS local en Docker; PACS y visor en la nube. Las PCs del centro solo usan navegador. **Tailscale** es para despliegues remotos desde el PC de sistemas, no para instalar en el laboratorio.

**Manual de usuario:** `docs/INSTRUCTIVO_HealthTiCloud_RIS.pdf` o `.docx`

Regenerar tras cambios: `cd docs` → `python generate_instructivo.py` y `python generate_instructivo_docx.py`

### Laboratorios de prueba (tras `db:seed`)

| Laboratorio | Tipo | Módulo tecnólogo |
|-------------|------|------------------|
| Centro de Diagnóstico RIS PRO | Clínico | Worklist (MWL) |
| Dental Demo — CBCT Temuco / Sucursal Centro | Dental | Atención en salas |
| Veterinaria Demo Sur / Urgencias 24h | Veterinario | Atención en salas |

Seleccione la sede en la barra superior. Usuario tecnólogo de prueba: `friquelme` (ver contraseña en seeder).

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
