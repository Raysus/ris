# RIS en laboratorio — Windows Server

> **Recomendado:** [GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md) (Ubuntu Server + Docker).

Esta guía resume lo esencial si el servidor del centro es **Windows** con **Docker Desktop**.

---

## Requisitos

- Docker Desktop instalado y en ejecución
- Git para clonar el repositorio
- IP fija en la LAN (ej. `192.168.1.50`)

**Tailscale:** solo en el PC del equipo de sistemas (despliegues remotos), no en cada PC de usuarios.

---

## Instalación

```powershell
git clone https://github.com/Raysus/ris.git C:\RIS
cd C:\RIS\backend
Copy-Item .env.lan.example .env
notepad .env
```

En `.env`: sustituya `192.168.1.50` por la IP del servidor. `APP_URL` y `FRONTEND_URL` deben coincidir con la URL del navegador (`http://<IP>`). Configure `ORTHANC_URL` y `CLOUD_SYNC_SECRET` según sistemas.

```powershell
docker compose -f docker-compose.lan.yml run --rm api php artisan key:generate --show
# Pegar APP_KEY= en .env
docker compose -f docker-compose.lan.yml up -d --build
```

Comprobar desde otra PC: `curl http://<IP>/api/health` → JSON `"status":"ok"`.

Tras el primer arranque: `DB_AUTO_SEED=false` en `.env` y `docker compose -f docker-compose.lan.yml up -d`.

---

## Firewall

```powershell
New-NetFirewallRule -DisplayName "RIS HTTP" -Direction Inbound -Protocol TCP -LocalPort 80 -Action Allow
```

---

## Actualizaciones

Las actualizaciones las ejecuta el equipo de sistemas (`envoy run deploy-lab` o `git pull` + `docker compose ... up -d --build` en el servidor).

Problemas frecuentes y checklist: [INSTALACION.md](INSTALACION.md) §7 y [GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md).
