# HealthTiCloud RIS

Sistema de información radiológica: agenda, worklist, informes, administración y pagos.

| Componente | Tecnología |
|------------|------------|
| API | Laravel 13 · PHP 8.5+ |
| Frontend | HTML + JavaScript + Bootstrap 5 |
| Base de datos | PostgreSQL 15 |

---

## Documentación

| Documento | Para quién |
|-----------|------------|
| [docs/INSTALACION.md](docs/INSTALACION.md) | Técnicos: local, LAN Docker, nube, bridge, sync, fallos |
| [docs/GUIA_INSTALACION_LABORATORIO.md](docs/GUIA_INSTALACION_LABORATORIO.md) | Instalación paso a paso (Ubuntu Server) |
| [docs/GUIA_INSTALACION_LABORATORIO_WINDOWS.md](docs/GUIA_INSTALACION_LABORATORIO_WINDOWS.md) | Variante Windows (referencia) |
| [docs/GIT_ACCESO_GITHUB.md](docs/GIT_ACCESO_GITHUB.md) | Token o SSH para `git push` / `git pull` |

**Manual de usuario:** `docs/INSTRUCTIVO_HealthTiCloud_RIS.pdf` o `.docx`  
Regenerar: `cd docs` → `python generate_instructivo.py` y `python generate_instructivo_docx.py`

**Laboratorio LAN:** las PCs entran por `http://<IP-del-servidor>` (puerto 80). Comprobar desde otra PC: `curl http://<IP>/api/health`.

**Despliegue:** rama `laboratorios` en sedes locales · rama `nube` en producción. Ver `backend/Envoy.blade.php` (`deploy-lab`, `deploy-nube`).

---

## Inicio rápido (desarrollo local)

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
# API en config.js: http://127.0.0.1:8000/api
cd .. && python -m http.server 5500
```

Abrir **http://127.0.0.1:5500/index.html**

---

## Producción

| Servicio | URL |
|----------|-----|
| Frontend | https://ris.healthticloud.cl |
| API | https://api.healthticloud.cl |

---

## Bridge local (escáner y visor)

En cada PC de recepción/radiología (no en el servidor):

```bash
cd tools/ris-local-bridge
npm install && npm start
```

Ver [docs/INSTALACION.md](docs/INSTALACION.md) §5.

---

## Tests

```bash
cd backend && php artisan test
```
