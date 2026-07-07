# Instalación — HealthTiCloud RIS

Guía para técnicos. Un solo documento: servidor + PCs de usuario.

> **¿Despliegue en un laboratorio (paso a paso, sin tecnicismos)?**
> Use **[GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md)** (Ubuntu Server + Docker).
> Variante Windows: **[GUIA_INSTALACION_LABORATORIO_WINDOWS.md](GUIA_INSTALACION_LABORATORIO_WINDOWS.md)**.
> **Secretos:** **[SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md)** · **ECOTEMUCO local:** **[DESPLIEGUE_ECOTEMUCO_LOCAL.md](DESPLIEGUE_ECOTEMUCO_LOCAL.md)**.
> Esta guía cubre desarrollo local, producción en nube y detalle técnico.
>
> **Tailscale** solo lo usa quien **despliega o da soporte** desde su computador (SSH/Envoy a nube y laboratorios).
> El servidor del laboratorio **no** requiere Tailscale para el uso diario del RIS; solo la URL de PACS que indique sistemas.

| Qué | Dónde corre |
|-----|-------------|
| API (Laravel) | Servidor |
| Frontend (HTML/JS) | Servidor |
| PostgreSQL | Servidor (Docker o nativo) |
| Bridge escáner/visor | **Cada PC** de recepción y radiología |

**URLs producción:** frontend `https://ris.healthticloud.cl` · API `https://api.healthticloud.cl`  
**Externos:** portal pacientes `https://portal.healthticloud.cl` · visor OHIF `https://viewer.healthticloud.cl`

---

## 1. Requisitos

| Software | Versión |
|----------|---------|
| PHP | 8.5+ (extensiones: pdo_pgsql, mbstring, openssl, json, fileinfo, bcmath) |
| Composer | 2.x |
| Docker | Para PostgreSQL local (opcional en servidor Linux productivo) |
| Node.js | Solo en PCs con bridge (escáner/visor local) |

Comprobar: `php -v` · `composer -V` · `docker compose version`

---

## 2. Instalación local (desarrollo o servidor de prueba)

### 2.1 Clonar y base de datos

```bash
git clone <url-repositorio> RIS
cd RIS/backend
docker compose up -d
docker compose ps    # pgsql debe estar "running"
```

### 2.2 Backend

**Windows (PowerShell):**

```powershell
cd backend
Copy-Item .env.example .env
# Editar .env: DB_*, FRONTEND_URL, MAIL_*
composer install
php artisan key:generate
php artisan migrate --force
# Demo Siresa (desarrollo): php artisan db:seed
# ECOTEMUCO LAN: DB_SEED_CLASS=EcotemucoLabSeeder en .env o:
# php artisan db:seed --class=EcotemucoLabSeeder
php artisan db:seed
php artisan storage:link
php artisan serve --host=127.0.0.1 --port=8000
```

**Linux / macOS:** igual con `cp .env.example .env`.

API: **http://127.0.0.1:8000**

### 2.3 Frontend

```bash
cd frontend/js
cp config.example.js config.js   # Windows: Copy-Item config.example.js config.js
```

Editar `config.js`:

```javascript
const API_URL = 'http://127.0.0.1:8000/api';
```

Servir carpeta `frontend/` (dejar terminal abierta):

```bash
cd frontend
python -m http.server 5500
```

O usar **Live Server** en `index.html` (puerto 5500).

Abrir: **http://127.0.0.1:5500/index.html**

### 2.4 Primer login

Tras `db:seed`, use un usuario del seeder (ej. `rgutierrez`). Cambie contraseñas antes de producción.

Seleccione una **sede concreta** en la barra superior (no «Todas mis sucursales»). El menú y los módulos dependen del tipo de laboratorio (clínico, dental, veterinario).

### 2.5 Comprobar que funciona

```bash
curl -s http://127.0.0.1:8000/api/health
```

Login en el navegador → menú lateral visible → módulo Agenda carga.

---

## 3. Red local (varias PCs en la clínica)

En `config.js` de **cada PC cliente** use la **IP del servidor**, no `127.0.0.1`:

```javascript
const API_URL = 'http://192.168.1.50:8000/api';
```

En el servidor:

```bash
php artisan serve --host=0.0.0.0 --port=8000
python -m http.server 5500 --bind 0.0.0.0   # en frontend/
```

En `.env` del backend:

```env
FRONTEND_URL=http://192.168.1.50:5500
APP_URL=http://192.168.1.50:8000
```

Firewall: permitir puertos **8000** y **5500** (o 80/443 si usa Nginx).

---

## 3.1 Laboratorio con Docker (`docker-compose.lan.yml`)

Stack recomendado en el servidor del centro (Ubuntu o Windows con Docker Desktop).
La API corre en contenedor **PHP 8.5.4** (`backend/Dockerfile`, imagen `php:8.5.4-cli`), alineado con `composer.json` (`^8.5`).
En laboratorio LAN use siempre la imagen Docker; el PHP del host no afecta al contenedor.

```bash
cd backend
cp .env.lan.example .env
# Editar IP LAN, ORTHANC_URL, DB_PASSWORD, etc.
docker compose -f docker-compose.lan.yml run --rm api php artisan key:generate --show
# Pegar APP_KEY= en .env
docker compose -f docker-compose.lan.yml up -d --build
```

| Servicio | Puerto | Función |
|----------|--------|---------|
| `web` (nginx) | 80 | Frontend estático (`../frontend`) |
| `api` | 8000 | Laravel (`php artisan serve`) |
| `pgsql` | interno | PostgreSQL 15 |
| `redis` | interno | Caché opcional (cola por defecto: `database`) |

El frontend detecta la API en `http://<IP-servidor>/api` (puerto 80, nginx) o en `:8000` si no hay proxy (`frontend/js/config.js`).
No hace falta editar `config.js` en cada PC si todas entran por `http://<IP>` o por un alias DNS local documentado (ej. `http://siresamatriz.healthticloud.cl` — ver guía laboratorio §10.1).

Variables clave (ver `.env.lan.example`):

| Variable | LAN típico |
|----------|------------|
| `APP_URL` | `http://192.168.x.x` o `http://siresamatriz.healthticloud.cl` (misma URL que el navegador) |
| `FRONTEND_URL` | **Igual** que `APP_URL` — debe coincidir con la barra del navegador (CORS y sesión) |
| `SESSION_SECURE_COOKIE` | `false` (HTTP sin TLS) |
| `RIS_CLOUD_ROLE` | `local` |
| `CLOUD_SYNC_SECRET` | Mismo valor que en la nube (envío de citas/pacientes) |
| `ORTHANC_URL` | URL PACS en nube |
| `VIEWER_URL` | `https://viewer.healthticloud.cl` |

Comprobación desde otra PC de la LAN:

```bash
curl -s http://<IP-servidor>/api/health   # JSON "status":"ok", no HTML
```

Tras el primer arranque: `DB_AUTO_SEED=false` y `docker compose -f docker-compose.lan.yml up -d`.

Tras `git pull` que toque `docker/frontend.nginx.conf`: `docker compose -f docker-compose.lan.yml up -d --force-recreate web`.

Guía paso a paso: **[GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md)**.

---

## 4. Producción en nube (sin Docker)

Código típico: `/var/www/ris.healthticloud.cl`

### 4.1 Variables `.env` (backend)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.healthticloud.cl
FRONTEND_URL=https://ris.healthticloud.cl

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_DATABASE=ris_db
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_ENCRYPTION=tls
MAIL_SEND_CONFIRMATION=true
PATIENT_PORTAL_URL=https://portal.healthticloud.cl

VIEWER_URL=https://viewer.healthticloud.cl
VIEWER_PATH=/viewer
VIEWER_QUERY_PARAM=StudyInstanceUIDs
PACS_BRIDGE_URL=http://localhost:8181/open-dicom
PACS_DEFAULT_IP=...
PACS_DEFAULT_PORT=4242
PACS_DEFAULT_AET=HEALTHTICLOUD
ORTHANC_URL=http://...

BACKUP_PATH=/var/backups/ris
```

Frontend (`frontend/js/config.js` en el servidor):

```javascript
const API_URL = 'https://api.healthticloud.cl/api';
```

### 4.2 Nginx

Plantilla: `deploy/nginx/healthticloud.conf.example`

- `ris.healthticloud.cl` → carpeta `frontend/`
- `api.healthticloud.cl` → `backend/public`

### 4.3 Colas y tareas (obligatorio en producción)

```bash
sudo cp deploy/systemd/ris-queue.service /etc/systemd/system/
sudo cp deploy/systemd/ris-scheduler.service /etc/systemd/system/
sudo systemctl enable --now ris-queue ris-scheduler
```

Sin esto no se envían correos ni jobs en segundo plano.

### 4.4 Cada deploy

```bash
cd backend
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan config:clear
sudo -u www-data php artisan optimize
sudo -u www-data php artisan queue:restart
```

Desde su PC (con **Tailscale** activo para alcanzar el servidor): `cd backend && php vendor/bin/envoy run deploy-nube`

Laboratorios LAN: `php vendor/bin/envoy run deploy-lab --lab=lab_lautaro` (misma red Tailscale; el lab no instala Tailscale).

**Envoy y sudo:** las tareas usan `sudo -n` (sin contraseña). Si ve `Authentication failed`, en el servidor **como root** (una sola vez):

```bash
cd /var/www/ris.healthticloud.cl
sudo bash deploy/scripts/server-setup-deploy-user.sh
```

Eso instala ACL + `/etc/sudoers.d/ris-deploy` y deja `userit` + `www-data` compartiendo `storage/`.

Manual alternativo:

```bash
sudo cp /var/www/ris.healthticloud.cl/deploy/sudoers-ris-deploy.example /etc/sudoers.d/ris-deploy
sudo chmod 440 /etc/sudoers.d/ris-deploy
sudo visudo -c -f /etc/sudoers.d/ris-deploy
```

Si `visudo` falla con `unknown setting: requiretty`, el archivo viejo tenía `Defaults:userit !requiretty` (no compatible con **sudo-rs** en Ubuntu 24+). Actualice el repo (`git pull`) y vuelva a copiar `deploy/sudoers-ris-deploy.example`, o pegue manualmente:

```
Cmnd_Alias RIS_DEPLOY_CHOWN = /usr/bin/chown, /usr/bin/chmod, /bin/chown, /bin/chmod
Cmnd_Alias RIS_DEPLOY_ARTISAN = /usr/bin/php /var/www/ris.healthticloud.cl/backend/artisan *
userit ALL=(root) NOPASSWD: RIS_DEPLOY_CHOWN
userit ALL=(www-data) NOPASSWD: RIS_DEPLOY_ARTISAN
```
```

Probar: `sudo -n -u www-data php /var/www/ris.healthticloud.cl/backend/artisan --version`

### 4.5 Verificar

```bash
curl -s https://api.healthticloud.cl/api/health
```

Debe responder `"status":"ok"`.

### 4.6 Permisos storage

Si falla el log o migrate:

```bash
sudo chown -R www-data:www-data storage bootstrap/cache
sudo -u www-data php artisan optimize
```

### 4.7 Respaldos

```bash
sudo -u www-data php artisan ris:backup --keep=14
```

---

## 5. Bridge en PCs de recepción y radiología

El bridge **no va en el servidor**. Va en cada PC que escanea o abre RadiAnt/OsiriX.

```bash
cd RIS/tools/ris-local-bridge
copy config.example.json config.json    # Mac/Linux: cp
npm install
npm start
```

Probar: `curl http://127.0.0.1:8181/health`

Editar `config.json`: rutas de **NAPS2** (escáner) y **RadiAnt** (u otro visor).

### Inicio automático

**Windows (PowerShell en la carpeta del bridge):**

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned -Force
.\install-windows-startup.ps1
```

**macOS:**

```bash
cd RIS/tools/ris-local-bridge
chmod +x setup-scanner-macos.sh install-macos-startup.sh start-bridge.sh
./setup-scanner-macos.sh
```

Configura **Horos** como visor (`viewer: horos`). Requiere Horos en `/Applications/Horos.app`.

Alternativa manual (solo arranque automático):

```bash
chmod +x install-macos-startup.sh
./install-macos-startup.sh
```

### Visor desde el RIS

| Botón | Acción |
|-------|--------|
| VISOR PACS | Bridge local → URL custom (`.env` `VIEWER_CUSTOM_URL`) → OHIF |
| OHIF | Solo visor web en la nube |

En Agenda: **Escanear** (bridge) o **Subir** (PDF/imagen sin bridge).

---

## 6. Configuración opcional (`.env`)

| Función | Variables |
|---------|-----------|
| Keycloak / portal | `KEYCLOAK_*` |
| Sync a nube | Ver sección **Sync matriz / sucursales** abajo |
| FONASA (solo clínico) | `FONASA_*` |
| Factura electrónica | `DTE_*` |
| HL7 hospital | `HL7_*` |
| Centros dental/vet | Tipo **Centro Dental** o **Veterinario** en Admin; módulo **Atención en salas** |
| Subida manual PACS | `POST /api/appointments/{id}/upload-dicom` (sin MWL) |
| Forzar modo manual en clínico | `laboratories.settings`: `{"uses_dicom_worklist": false}` |

Tras cambiar `.env`: `php artisan config:clear`

### 6.1 Sync matriz / sucursales (nube ↔ laboratorios)

**Nube** (`api.healthticloud.cl`) — receptor central:

```env
RIS_CLOUD_ROLE=cloud
CLOUD_INBOUND_ENABLED=true
CLOUD_SYNC_SECRET=un-secreto-largo-compartido
QUEUE_CONNECTION=database
```

**Laboratorio local** (Docker LAN) — envía operación y puede importar catálogo:

```env
RIS_CLOUD_ROLE=local
CLOUD_API_BASE=https://api.healthticloud.cl/api
CLOUD_SYNC_SECRET=el-mismo-secreto-que-en-nube
QUEUE_CONNECTION=database
```

En Admin → **Sync Nube**:

- **Catálogo desde nube** — exámenes, máquinas, médicos solicitantes, previsiones (**sede concreta** en el selector superior; no «Todas mis sucursales»).
- **+ Pacientes** — además pacientes de esa sede.
- **Enviar pendientes** — reintenta cola de envío local → nube (requiere `CLOUD_SYNC_SECRET` y contenedor `queue` activo).

La matriz en la **misma BD** ve sucursales con el selector de sede; los labs remotos replican citas/pacientes vía cola automática al guardar.

Colas: en nube `ris-queue` (systemd); en lab contenedor `queue`. Redis es opcional (`QUEUE_CONNECTION=redis` + `REDIS_HOST=redis` en Docker).

---

## 7. Problemas frecuentes

| Problema | Solución |
|----------|----------|
| Login 500 | `php artisan migrate --force` · revisar `storage/logs/laravel.log` |
| CORS / no conecta API | `FRONTEND_URL` en `.env` = URL exacta del navegador (`http://IP` sin `:8000` si entran por puerto 80). Sustituir IP plantilla `192.168.1.50` por la IP real. |
| `/api/health` devuelve HTML | Recrear nginx: `docker compose -f docker-compose.lan.yml up -d --force-recreate web` |
| Otra PC no alcanza el RIS | UFW puerto 80, misma VLAN, `curl http://IP/api/health` desde esa PC |
| SSH por Tailscale refused | Instalar `openssh-server`; usuario en Envoy = `whoami` del servidor |
| Sync catálogo pide sede | Selector superior: matriz o sucursal concreta, no «Todas» |
| Menú vacío | Cerrar sesión y volver a entrar |
| Agenda sin salas | Elegir laboratorio arriba · Ctrl+F5 |
| Escáner no funciona | Bridge en `127.0.0.1:8181` · NAPS2 en `config.json` |
| Visor no abre | Bridge + RadiAnt; si falla, botón **OHIF** |
| Correos no salen | Cola activa (`ris-queue`) · SMTP en `.env` |

Logs: `backend/storage/logs/laravel.log`

---

## 8. Manual de usuario (instructivo)

Archivos entregables: `docs/INSTRUCTIVO_HealthTiCloud_RIS.pdf` y `.docx` (17 capítulos: flujo clínico, módulos, dental/vet, dictado, secretaria, sync nube, FAQ).

Regenerar tras cambios de interfaz:

```bash
pip install -r docs/requirements-docs.txt
# API en :8000, frontend en :8765 (variables RIS_API_URL / RIS_FRONTEND_URL opcionales)
node docs/capture_screenshots.mjs
python docs/generate_instructivo.py
python docs/generate_instructivo_docx.py
```

El capítulo 5 del PDF describe Worklist (clínico) y **Atención en salas** (dental/vet). El capítulo 12 detalla diferencias por tipo de centro.

---

## 9. Checklist entrega

**Servidor**

- [ ] PostgreSQL y API responden
- [ ] `APP_DEBUG=false` en producción
- [ ] `config.js` apunta a la API correcta
- [ ] Colas `ris-queue` y `ris-scheduler` activas
- [ ] `/api/health` → ok
- [ ] Contraseñas demo cambiadas

**Cada PC operativa**

- [ ] Bridge instalado y auto-inicio (si usan escáner/visor local)
- [ ] Navegador con acceso al frontend
