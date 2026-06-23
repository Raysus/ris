# Desplegar ECOTEMUCO en local (LAN) desde la nube

Guía para levantar un **servidor ECOTEMUCO** en la red del centro, sincronizado con
`ris.healthticloud.cl`, **sin** datos demo de Siresa.

---

## ¿Se puede sin problemas?

**Sí**, si sigue este flujo. **No** use el `DatabaseSeeder` por defecto: crea Siresa y
sucursales demo y el UUID de ECOTEMUCO no coincidiría con la nube.

| Enfoque | Recomendado |
|---------|-------------|
| `DB_SEED_CLASS=EcotemucoLabSeeder` | Sí — matriz ECOTEMUCO con UUID de nube + usuario `admin` |
| `DatabaseSeeder` | No — solo para demo Siresa |
| Sync catálogo desde nube | Sí — trae exámenes, salas, plantillas, usuarios |

---

## Requisitos

- Ubuntu 22.04/24.04 o Windows con Docker (ver guías Linux/Windows).
- Secretos en [SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md) (`CLOUD_SYNC_SECRET`, URLs PACS/visor).
- Red del centro alcanza `https://api.healthticloud.cl` y `https://pacs.healthticloud.cl`.

---

## Pasos (Ubuntu Docker — resumen)

### 1. Clonar y configurar `.env`

```bash
cd /opt/RIS/backend
cp .env.lan.example .env
nano .env
```

| Variable | Valor ECOTEMUCO |
|----------|-----------------|
| `APP_URL` / `FRONTEND_URL` | `http://<IP-LAN-del-servidor>` |
| `CLOUD_SYNC_SECRET` | Ver [SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md) |
| `DB_AUTO_SEED` | `true` *(solo primer arranque)* |
| `DB_SEED_CLASS` | `EcotemucoLabSeeder` |
| `RIS_CLOUD_ROLE` | `local` |

Genere `APP_KEY` y `DB_PASSWORD` (guía laboratorio §7).

### 2. Primer arranque

```bash
docker compose -f docker-compose.lan.yml up -d --build
curl -s http://127.0.0.1/api/health
```

### 3. Desactivar re-seed

```bash
nano .env
# DB_AUTO_SEED=false
docker compose -f docker-compose.lan.yml up -d
```

### 4. Login y sync catálogo

1. Navegador: `http://<IP-LAN>`
2. Usuario: `admin` / contraseña: `admin` (cambiar después).
3. Selector superior: **ECOTEMUCO**.
4. **Administración → Sync Nube → Catálogo desde nube** (+ usuarios si necesita el equipo operativo).
5. Opcional: exámenes ecográficos — en nube ya están; el sync los trae. Si faltan:
   `php scripts/seed-ecotemuco-exams.php` (solo si el catálogo no se importó).

### 5. Registrar lab en nube (MWL relay, opcional)

Si ECOTEMUCO tendrá equipos DICOM en LAN, sistemas añade en la nube:

```env
LAB_MWL_RELAY_URLS={"019ef4e0-a7f9-73d0-be80-db47957e1a6b":"http://<IP-TAILSCALE-LAB>/api/integrations/local-mwl/relay"}
```

---

## Qué NO hace el seeder ECOTEMUCO

- No crea sucursales (añádalas en Admin → Ajustes).
- No importa exámenes ni salas (eso es **Sync Nube**).
- No copia citas ni pacientes (llegan al enviar desde el lab o al importar con «+ Pacientes»).

---

## Problemas frecuentes

| Síntoma | Causa / solución |
|---------|------------------|
| Aparece Siresa en el selector | Usó `DatabaseSeeder`. Vacíe BD o reinstale con `EcotemucoLabSeeder`. |
| Sync falla 403 | `CLOUD_SYNC_SECRET` distinto al de la nube. |
| Dos ECOTEMUCO en selector | Matriz creada a mano con otro UUID. Borre la duplicada o reinstale con seeder. |
| Exámenes vacíos | Ejecute Sync Nube con ECOTEMUCO seleccionado. |

---

## Referencias

- [GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md)
- [SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md)
- [INSTALACION.md](INSTALACION.md) §6.1 Sync matriz
