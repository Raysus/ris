# RIS en laboratorio — Windows Server

> **Recomendado en producción:** [GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md) (Ubuntu Server + Docker).
>
> Esta guía cubre **Windows Server o Windows 10/11 Pro** con **Docker Desktop**.
> Detalle técnico adicional: [INSTALACION.md](INSTALACION.md).

---

## 1. Requisitos

| Requisito | Detalle |
|-----------|---------|
| Sistema | Windows Server 2019+ o Windows 10/11 **64 bits** |
| RAM | Mínimo **8 GB** (recomendado 16 GB) |
| Docker | **Docker Desktop** instalado y en ejecución (WSL2 backend) |
| Red | IP fija en la LAN (ej. `192.168.1.50`) |
| Git | [Git for Windows](https://git-scm.com/download/win) |

**Tailscale:** solo en el PC del equipo de sistemas (despliegues remotos), no en cada PC de usuarios.

---

## 2. Instalación paso a paso

### 2.1 Clonar el repositorio

```powershell
git clone https://github.com/Raysus/ris.git C:\RIS
cd C:\RIS
git checkout laboratorios
```

Token GitHub: ver [GIT_ACCESO_GITHUB.md](GIT_ACCESO_GITHUB.md).

### 2.2 Configurar `.env`

```powershell
cd C:\RIS\backend
Copy-Item .env.lan.example .env
notepad .env
```

| Variable | Qué poner |
|----------|-----------|
| `192.168.1.50` (todas) | IP **real** del servidor Windows (`ipconfig`) |
| `APP_URL` / `FRONTEND_URL` | `http://<SU-IP>` (igual que la barra del navegador) |
| `DB_PASSWORD` | Contraseña fuerte inventada |
| `CLOUD_SYNC_SECRET` | Ver [SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md) |
| `ORTHANC_URL` | `https://pacs.healthticloud.cl` |
| `SESSION_SECURE_COOKIE` | `false` (HTTP sin TLS en LAN) |

**ECOTEMUCO (sin demo Siresa):**

```env
DB_AUTO_SEED=true
DB_SEED_CLASS=EcotemucoLabSeeder
```

Tras el primer arranque: `DB_AUTO_SEED=false`. Guía completa: [DESPLIEGUE_ECOTEMUCO_LOCAL.md](DESPLIEGUE_ECOTEMUCO_LOCAL.md).

**Aplicar secretos automáticamente (Git Bash o WSL):**

```bash
bash /c/RIS/scripts/apply-lan-secrets.sh 192.168.1.50
```

### 2.3 Generar `APP_KEY`

```powershell
docker compose -f docker-compose.lan.yml run --rm api php artisan key:generate --show
```

Copie la línea `base64:...` en `APP_KEY=` del `.env`.

### 2.4 Levantar el stack

```powershell
docker compose -f docker-compose.lan.yml up -d --build
docker compose -f docker-compose.lan.yml ps
```

Todos los servicios deben estar **running** (`api`, `web`, `queue`, `pgsql`).

### 2.5 Comprobar

```powershell
curl http://127.0.0.1/api/health
```

Desde otra PC: `http://<IP>/api/health` → JSON `"status":"ok"`.

Navegador: `http://<IP>` → pantalla de login.

### 2.6 Tras el primer arranque

```powershell
notepad .env
# DB_AUTO_SEED=false
docker compose -f docker-compose.lan.yml up -d
```

---

## 3. Firewall Windows

```powershell
New-NetFirewallRule -DisplayName "RIS HTTP" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
# Opcional si acceden al puerto 8000 directo:
New-NetFirewallRule -DisplayName "RIS API" -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow
```

---

## 4. Sync con la nube

1. Login (`admin`/`admin` si usó `EcotemucoLabSeeder`, o `rgutierrez`/`rgutierrez` si demo Siresa).
2. Selector superior: sede concreta (ej. **ECOTEMUCO**).
3. **Administración → Sync Nube → Catálogo desde nube**.

Requiere `CLOUD_SYNC_SECRET` correcto y contenedor `queue` en ejecución.

---

## 5. Bridge escáner (PC de recepción, opcional)

En la PC con escáner (no en el servidor):

```powershell
cd C:\RIS\tools\ris-local-bridge
Copy-Item config.example.json config.json
npm install
npm start
```

Inicio automático: `.\install-windows-startup.ps1` (ver [INSTALACION.md](INSTALACION.md) §5).

---

## 6. Actualizaciones

Las ejecuta el equipo de sistemas:

```powershell
cd C:\RIS
git pull origin laboratorios
cd backend
docker compose -f docker-compose.lan.yml up -d --build
docker compose -f docker-compose.lan.yml up -d --force-recreate web
```

---

## 7. Problemas frecuentes

| Síntoma | Solución |
|---------|----------|
| Docker no inicia | Abrir Docker Desktop; habilitar WSL2 |
| `failed to fetch` / CORS | `FRONTEND_URL` = URL exacta del navegador |
| `/api/health` devuelve HTML | `docker compose ... up -d --force-recreate web` |
| Sync nube falla | `CLOUD_SYNC_SECRET` en [SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md) |
| Aparece Siresa en ECOTEMUCO | Usar `DB_SEED_CLASS=EcotemucoLabSeeder`, no `DatabaseSeeder` |

Más síntomas: [GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md) §16.

---

## 8. Checklist

- [ ] Docker Desktop running
- [ ] `.env` con IP real, `APP_KEY`, `DB_PASSWORD`, `CLOUD_SYNC_SECRET`
- [ ] `DB_AUTO_SEED=false` tras primer arranque
- [ ] `curl http://localhost/api/health` → ok
- [ ] Otra PC alcanza `http://<IP>`
- [ ] Sync Nube probado (si aplica)
- [ ] Contraseñas demo cambiadas

---

**Secretos:** [SECRETOS_DESPLIEGUE.md](SECRETOS_DESPLIEGUE.md) · **ECOTEMUCO local:** [DESPLIEGUE_ECOTEMUCO_LOCAL.md](DESPLIEGUE_ECOTEMUCO_LOCAL.md)
