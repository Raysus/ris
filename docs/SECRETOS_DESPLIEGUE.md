# Secretos y credenciales — HealthTiCloud RIS

> **No almacene valores reales en este repositorio.** Use el gestor de contraseñas del equipo
> o variables de entorno en cada servidor. Respaldo local (fuera de git): carpeta
> `RIS-secrets-backup` en el equipo de sistemas.

---

## 1. Resumen por entorno

| Entorno | Archivo | Rol cloud |
|---------|---------|-----------|
| Nube | `backend/.env` en servidor | `RIS_CLOUD_ROLE=cloud` |
| Laboratorio LAN | `backend/.env` | `RIS_CLOUD_ROLE=local` |

---

## 2. Secretos compartidos (nube ↔ laboratorios)

| Variable | Descripción |
|----------|-------------|
| `CLOUD_SYNC_SECRET` | Mismo valor en nube y cada lab que sincronice. Header `X-Cloud-Sync-Secret`. |

Obtenga el valor desde el gestor de contraseñas del equipo (no commitear).

---

## 3. Infraestructura nube (URLs públicas)

Ver `backend/.env.lan.example` para URLs de PACS, visor y portal.

---

## 4. Keycloak / portal pacientes

| Variable | Notas |
|----------|-------|
| `KEYCLOAK_BASE_URL` | URL SSO |
| `KEYCLOAK_ADMIN_USER` | Solo sistemas; **no** en git |
| `KEYCLOAK_ADMIN_PASSWORD` | Solo sistemas; **no** en git |
| `PORTAL_INTEGRATION_SECRET` | Si portal activo |

---

## 5. Secretos por instalación

| Variable | Cómo obtenerla |
|----------|----------------|
| `APP_KEY` | `php artisan key:generate --show` |
| `DB_PASSWORD` | Definir al instalar |

---

## 6. Aplicar secretos al `.env` LAN

```bash
export CLOUD_SYNC_SECRET='...'          # desde gestor de contraseñas
export KEYCLOAK_ADMIN_USER='...'
export KEYCLOAK_ADMIN_PASSWORD='...'
bash scripts/apply-lan-secrets.sh [IP_LAN]
```

El script **no** incluye secretos en el repositorio; falla si faltan variables sensibles.

---

## 7. Referencias

- [GIT_ACCESO_GITHUB.md](GIT_ACCESO_GITHUB.md)
- [INSTALACION.md](INSTALACION.md) §6.1 Sync matriz
