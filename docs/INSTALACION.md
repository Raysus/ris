# Instalación — HealthTiCloud RIS

Guía para técnicos. Un solo documento: servidor + PCs de usuario.

> **¿Despliegue en un laboratorio (paso a paso, sin tecnicismos)?**
> Use **[GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md)** (Ubuntu Server + Docker).
> Variante Windows: **[GUIA_INSTALACION_LABORATORIO_WINDOWS.md](GUIA_INSTALACION_LABORATORIO_WINDOWS.md)**.
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

**Probar perfiles dental / veterinario:** en el selector superior del RIS elija una sede de demostración:

| Laboratorio | Tipo | Qué validar |
|-------------|------|-------------|
| Dental Demo — CBCT Temuco | Centro Dental | Menú **Atención en salas** (no Worklist); Agenda sin FONASA |
| Dental Demo — Sucursal Centro | Centro Dental | Sucursal hija; mismo flujo |
| Veterinaria Demo Sur | Veterinario | Atención en salas; etiqueta «Mascota» en agenda |
| Veterinaria Demo — Urgencias 24h | Veterinario | Sucursal veterinaria |
| Centro de Diagnóstico RIS PRO | Clínico | Menú **Worklist** y envío MWL |

Flujo dental/vet: Agenda (confirmar cita) → **Atención en salas** → subir `.dcm`/`.zip` a PACS → finalizar → Radiólogo.  
Requiere `ORTHANC_URL` accesible desde el backend en desarrollo.

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
VIEWER_PATH=/viewer
VIEWER_QUERY_PARAM=StudyInstanceUIDs
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
| Centros dental/vet | Tipo **Centro Dental** o **Veterinario** en Admin; módulo **Atención en salas** |
| Subida manual PACS | `POST /api/appointments/{id}/upload-dicom` (sin MWL) |
| Forzar modo manual en clínico | `laboratories.settings`: `{"uses_dicom_worklist": false}` |

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
- Regenerar ambos formatos:

```bash
cd docs
python generate_instructivo.py
python generate_instructivo_docx.py
```

El capítulo 5 describe Worklist (clínico) y **Atención en salas** (dental/vet). El capítulo 12 detalla diferencias por tipo de centro y los laboratorios de demostración del seeder.

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
