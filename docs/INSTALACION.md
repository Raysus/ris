# Instalación — HealthTiCloud RIS

Guía para técnicos. Un solo documento: servidor + PCs de usuario.

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
| PHP | 8.3+ (extensiones: pdo_pgsql, mbstring, openssl, json, fileinfo, bcmath) |
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

Tras `db:seed`, usar usuario del seeder (ej. `rgutierrez` / contraseña del seeder).  
Cambiar contraseñas antes de producción.

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
PACS_BRIDGE_URL=http://localhost:8181/open-dicom
PACS_DEFAULT_IP=...
PACS_DEFAULT_PORT=4242
PACS_DEFAULT_AET=HealthTICloud
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

Opcional desde PC con SSH: `php vendor/bin/envoy run deploy-nube`

Si Envoy falla con `Permission denied` en `storage/` o `bootstrap/cache`, en el servidor (una vez o antes de volver a desplegar):

```bash
cd /var/www/ris.healthticloud.cl/backend
sudo chown -R userit:userit storage bootstrap/cache
cd /var/www/ris.healthticloud.cl
git fetch origin && git reset --hard origin/nube
```

Luego vuelva a ejecutar `envoy run deploy-nube` (el script ya incluye `prepare_git_nube`).

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
| Sync a nube | `CLOUD_SERVER_URL`, `CLOUD_SYNC_SECRET` |
| FONASA (solo clínico) | `FONASA_*` |
| Factura electrónica | `DTE_*` |
| HL7 hospital | `HL7_*` |
| Centros dental/vet | Tipo de laboratorio en **Admin** (sin FONASA) |

Tras cambiar `.env`: `php artisan config:clear`

---

## 7. Problemas frecuentes

| Problema | Solución |
|----------|----------|
| Login 500 | `php artisan migrate --force` · revisar `storage/logs/laravel.log` |
| CORS / no conecta API | `FRONTEND_URL` en `.env` = URL exacta del navegador |
| Menú vacío | Cerrar sesión y volver a entrar |
| Agenda sin salas | Elegir laboratorio arriba · Ctrl+F5 |
| Escáner no funciona | Bridge en `127.0.0.1:8181` · NAPS2 en `config.json` |
| Visor no abre | Bridge + RadiAnt; si falla, botón **OHIF** |
| Correos no salen | Cola activa (`ris-queue`) · SMTP en `.env` |

Logs: `backend/storage/logs/laravel.log`

---

## 8. Manual de usuario

- PDF/DOCX: `docs/INSTRUCTIVO_HealthTiCloud_RIS.pdf` (o `.docx`)
- Regenerar: `cd docs` → `python generate_instructivo_docx.py`

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
