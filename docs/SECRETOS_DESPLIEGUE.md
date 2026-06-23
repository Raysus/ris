# Secretos y credenciales — HealthTiCloud RIS

> **Uso interno (repositorio privado).** No publicar ni compartir fuera del equipo de sistemas.
> Valores vigentes a junio 2026. Si cambian en nube, actualice este archivo y el `.env` de cada laboratorio.

---

## 1. Resumen rápido por entorno

| Entorno | Archivo | Rol cloud |
|---------|---------|-----------|
| Nube (`ris.healthticloud.cl`) | `/var/www/ris.healthticloud.cl/backend/.env` | `RIS_CLOUD_ROLE=cloud` |
| Laboratorio LAN (Docker) | `backend/.env` (desde `.env.lan.example`) | `RIS_CLOUD_ROLE=local` |
| Desarrollo local | `backend/.env` (desde `env.example`) | `local` o sin sync |

---

## 2. Secretos compartidos (nube ↔ laboratorios)

Estos valores deben ser **idénticos** entre la nube y cada laboratorio que sincronice.

| Variable | Valor | Para qué |
|----------|-------|----------|
| `CLOUD_SYNC_SECRET` | `HtBMifjaMJASDmfFJASO1DSJa3!` | Envío/recepción de citas, pacientes y catálogo entre lab y nube. Header `X-Cloud-Sync-Secret`. |

**Sin `CLOUD_SYNC_SECRET`:** el lab funciona en LAN pero **no** envía citas a la matriz ni importa catálogo desde Admin → Sync Nube.

---

## 3. Infraestructura en nube (URLs fijas)

| Variable | Valor típico |
|----------|----------------|
| `CLOUD_API_BASE` | `https://api.healthticloud.cl/api` |
| `ORTHANC_URL` | `https://pacs.healthticloud.cl` |
| `PACS_DICOM_HOST` | `170.246.172.84` |
| `PACS_DEFAULT_PORT` | `4242` |
| `PACS_DEFAULT_AET` / `ORTHANC_AET` | `HEALTHTICLOUD` |
| `VIEWER_URL` | `https://viewer.healthticloud.cl` |
| `VIEWER_PATH` | `/viewer` |
| `PATIENT_PORTAL_URL` | `https://portal.healthticloud.cl` |

En laboratorio LAN copie estos valores al `.env` (ya vienen en `.env.lan.example`).

---

## 4. Keycloak / portal pacientes

| Variable | Valor |
|----------|-------|
| `KEYCLOAK_BASE_URL` | `https://sso.healthticloud.cl` |
| `KEYCLOAK_REALM` | `patient-portal` |
| `KEYCLOAK_CLIENT_ID` | `patient-portal` |
| `KEYCLOAK_ADMIN_USER` | `htcloud` |
| `KEYCLOAK_ADMIN_PASSWORD` | `mNgD0\og8N5EkG9UQ` |

Solo necesario si el lab envía correos con alta en portal o verificación SSO. En LAN de prueba puede dejarse y usar `MAIL_MAILER=log`.

| Variable | Valor |
|----------|-------|
| `PORTAL_INTEGRATION_SECRET` | *(consultar en nube si está activo `PORTAL_INTEGRATION_ENABLED`)* |

---

## 5. Secretos que genera cada instalación (no compartir entre servidores)

| Variable | Cómo obtenerla |
|----------|----------------|
| `APP_KEY` | `php artisan key:generate --show` (una por servidor; fijar en `.env`) |
| `DB_PASSWORD` | Inventar al instalar; debe coincidir con `docker-compose.lan.yml` / PostgreSQL |

---

## 6. GitHub (clonar / actualizar código)

| Uso | Dónde |
|-----|--------|
| Token fine-grained | Equipo sistemas (scope **Contents: Read** o Read+Write) |
| Guía paso a paso | [GIT_ACCESO_GITHUB.md](GIT_ACCESO_GITHUB.md) |

No guardar el token en el repositorio. Usar `git config credential.helper store` en el servidor.

---

## 7. Tailscale (solo soporte / deploy remoto)

| Recurso | Uso |
|---------|-----|
| Auth key | La entrega sistemas al unir servidor lab (`tailscale up --auth-key=...`) |
| IP `100.x` | En `Envoy.blade.php` para `deploy-lab` y SSH |

El personal del centro **no** necesita Tailscale para usar el RIS en el navegador.

---

## 8. Usuarios iniciales tras seed

| Seeder | Usuario | Contraseña | Notas |
|--------|---------|------------|-------|
| `DatabaseSeeder` (Siresa demo) | `rgutierrez` | `rgutierrez` | También crea `admin` (contraseña no documentada; use rgutierrez o resetee) |
| `EcotemucoLabSeeder` | `admin` | `admin` | Solo ECOTEMUCO; luego Sync Nube |

Cambie contraseñas antes de producción.

---

## 9. IDs fijos ECOTEMUCO (nube)

| Entidad | UUID |
|---------|------|
| Casa matriz ECOTEMUCO | `019ef4e0-a7f9-73d0-be80-db47957e1a6b` |
| Siresa matriz (referencia) | `ef633609-3144-17e1-7086-3eb7c8baed74` |

El seeder `EcotemucoLabSeeder` usa el UUID de ECOTEMUCO para que el sync con la nube no duplique la matriz.

---

## 10. Script para volcar secretos al `.env` local

```bash
cd backend
cp .env.lan.example .env
# Sustituya IP LAN
sed -i "s/192\.168\.1\.50/SU_IP/g" .env
# Secreto cloud (mismo que nube)
sed -i 's/^CLOUD_SYNC_SECRET=.*/CLOUD_SYNC_SECRET=HtBMifjaMJASDmfFJASO1DSJa3!/' .env
```

O use: `bash scripts/apply-lan-secrets.sh` (ver script en repo).

---

## 11. HL7 / FONASA / DTE (opcional)

Por defecto desactivados en `.env.lan.example` (`HL7_ENABLED=false`, `FONASA_ENABLED=false`, `DTE_ENABLED=false`).
Si un centro los activa, sistemas entrega tokens aparte.
