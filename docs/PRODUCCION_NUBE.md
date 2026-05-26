# Producción en nube — HealthTiCloud RIS

Guía para el despliegue actual **sin Docker** en servidor Linux:

| Servicio | URL |
|---|---|
| Frontend | https://ris.healthticloud.cl |
| API | https://api.healthticloud.cl |
| Código en servidor | `/var/www/ris.healthticloud.cl` |

---

## 1. Variables de entorno (`.env` en `backend/`)

```env
APP_ENV=production
APP_DEBUG=false
APP_URL=https://api.healthticloud.cl
FRONTEND_URL=https://ris.healthticloud.cl

LOG_LEVEL=warning

DB_CONNECTION=pgsql
DB_HOST=127.0.0.1
DB_PORT=5432
DB_DATABASE=ris_db
DB_USERNAME=...
DB_PASSWORD=...

QUEUE_CONNECTION=database
CACHE_STORE=database
SESSION_DRIVER=database
SESSION_SECURE_COOKIE=true

SANCTUM_TOKEN_EXPIRATION=480

MAIL_MAILER=smtp
MAIL_HOST=...
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_ENCRYPTION=tls
MAIL_FROM_ADDRESS=noreply@healthticloud.cl
MAIL_FROM_NAME="HealthTiCloud RIS"

BACKUP_PATH=/var/backups/ris
PG_DUMP_PATH=/usr/bin/pg_dump
```

**Frontend** (`frontend/js/config.js`, no versionado):

```js
const API_URL = 'https://api.healthticloud.cl/api';
```

Tras cambiar `.env`:

```bash
cd /var/www/ris.healthticloud.cl/backend
php artisan config:clear
php artisan optimize
```

---

## 2. Nginx + PHP-FPM

Plantilla en `deploy/nginx/healthticloud.conf.example`:

- `ris.healthticloud.cl` → sirve `frontend/` (HTML estático)
- `api.healthticloud.cl` → `backend/public` (Laravel)

PHP-FPM recomendado (8.3+). El proxy ya está contemplado en Laravel (`trustProxies`).

---

## 3. Colas y tareas programadas (systemd)

Sin colas, los correos y jobs (`SyncEntityToCloud`, etc.) no se procesan.

```bash
sudo cp deploy/systemd/ris-queue.service /etc/systemd/system/
sudo cp deploy/systemd/ris-scheduler.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ris-queue ris-scheduler
```

Verificar:

```bash
sudo systemctl status ris-queue
tail -f /var/www/ris.healthticloud.cl/backend/storage/logs/queue.log
```

Tras cada deploy:

```bash
php artisan queue:restart
```

---

## 4. Migraciones nuevas (producción segura)

Incluyen tablas `jobs`, `sessions`, `audit_logs` y cifrado de PII en `personas` (RUT, email, teléfono).

```bash
cd backend
php artisan migrate --force
```

> **Importante:** el cifrado usa `APP_KEY`. No rote la clave sin migrar datos cifrados.

---

## 5. Respaldos

Automático (scheduler, 02:30):

```bash
php artisan ris:backup --keep=14
```

Manual o cron:

```bash
bash deploy/scripts/backup-ris.sh
```

Genera en `BACKUP_PATH`:

- `database.sql.gz` — dump PostgreSQL
- `storage-public.tar.gz` — adjuntos, informes, audios

**Restaurar BD (ejemplo):**

```bash
gunzip -c /var/backups/ris/YYYY-MM-DD_HHMMSS/database.sql.gz | psql -h 127.0.0.1 -U risuserdb -d ris_db
```

Probar un restore en entorno de prueba al menos una vez al mes.

---

## 6. Despliegue con Envoy

Desde su máquina (con SSH al servidor):

```bash
cd backend
php vendor/bin/envoy run deploy-nube
```

Pasos: `git pull` → `composer` → `migrate` → `optimize` → permisos → `queue:restart`.

---

## 7. Verificación post-deploy

```bash
# Health check
curl -s https://api.healthticloud.cl/api/health | jq

# Smoke test completo (requiere usuario operativo)
php tests/smoke_live.php https://api.healthticloud.cl
```

Esperado en `/api/health`:

```json
{
  "status": "ok",
  "checks": {
    "database": { "status": "ok" },
    "cache": { "status": "ok" },
    "queue": { "status": "ok", "driver": "database" }
  }
}
```

---

## 8. Seguridad implementada (punto 1)

| Medida | Detalle |
|---|---|
| CORS | `FRONTEND_URL` + dominios `*.healthticloud.cl` |
| Tokens | Expiración configurable (`SANCTUM_TOKEN_EXPIRATION`) |
| PII pacientes | RUT, email y teléfono cifrados en BD; búsqueda por hash |
| Auditoría | Tabla `audit_logs` (login, logout, anulaciones, etc.) |
| TLS sync | `SyncEntityToCloud` verifica certificados en producción |
| Login | Throttle 5 intentos/minuto |
| Contraseñas | Mínimo 8 caracteres al restablecer |

Los logs por cita (`appointment_logs`) siguen registrando acciones clínicas detalladas.

---

## 9. Checklist operativo

- [ ] `APP_DEBUG=false`
- [ ] Contraseñas del seeder cambiadas
- [ ] SMTP real configurado
- [ ] `ris-queue` y `ris-scheduler` activos
- [ ] Respaldos diarios verificados
- [ ] `config.js` del frontend apunta a `api.healthticloud.cl`
- [ ] Certificados SSL vigentes
- [ ] `/api/health` responde `ok`
