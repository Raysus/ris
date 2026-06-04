# Guía de instalación del RIS en un laboratorio (Windows)

> **Recomendado para servidores nuevos:** use la guía principal
> **[GUIA_INSTALACION_LABORATORIO.md](GUIA_INSTALACION_LABORATORIO.md)** (Ubuntu Server).
> Este documento queda como referencia si el servidor del laboratorio es **Windows**.

---

## Tailscale: ¿dónde va?

| Dónde | ¿Instala Tailscale? |
|-------|---------------------|
| **Servidor del laboratorio** | **No** (salvo que sistemas indique otra VPN concreta) |
| **PCs de recepción / médicos** | **No** |
| **Computador del equipo de sistemas** (despliegues y soporte) | **Sí** — para SSH a la nube y laboratorios vía Envoy |

El laboratorio se conecta al PACS de la nube con la **URL o IP** que entregue el responsable (`ORTHANC_URL` en `.env`), no necesariamente por Tailscale.

---

## 1. ¿Qué vamos a instalar? (resumen)

En el laboratorio habrá **un computador principal** (el **servidor**). Ahí corre el RIS en **Docker Desktop**. Las demás PCs usan el **navegador**.

- Las **imágenes médicas** están en la **nube**; el RIS local se conecta por red a ese PACS.
- **Tailscale no se instala en el servidor** para el día a día del centro.

---

## 2. Antes de empezar: qué descargar (servidor Windows)

| Programa | Para qué | Enlace |
|----------|----------|--------|
| **Docker Desktop** | Ejecutar el RIS | https://www.docker.com/products/docker-desktop/ |
| **Git** | Descargar el RIS | https://git-scm.com/download/win |

En PCs con **escáner**: **Node.js LTS**, **NAPS2** (ver sección 11).

---

## 3. Docker Desktop

Instalar, reiniciar si pide, dejar Docker Desktop **abierto** (ícono de ballena en la bandeja).

---

## 4. Obtener token y clonar

```powershell
git clone https://EL_TOKEN@github.com/Raysus/ris.git C:\RIS
git config --global credential.helper store
```

---

## 5. Configurar

```powershell
cd C:\RIS\backend
Copy-Item .env.lan.example .env
notepad .env
```

Sustituya `192.168.1.50` por la IPv4 del servidor (`ipconfig`). Configure `ORTHANC_URL` con la URL del PACS en nube que le indique sistemas.

El contenedor API usa **PHP 8.3** (igual que `composer.json`). Si en Windows tiene otro PHP instalado, no importa: Docker usa la imagen del `Dockerfile`.

```powershell
docker compose -f docker-compose.lan.yml run --rm api php artisan key:generate --show
```

Pegue el valor en `APP_KEY=` en `.env`.

---

## 6. Encender

```powershell
docker compose -f docker-compose.lan.yml up -d --build
```

Tras el primer arranque: `DB_AUTO_SEED=false` en `.env` y `up -d` de nuevo.

---

## 7. Firewall Windows

PowerShell como administrador:

```powershell
New-NetFirewallRule -DisplayName "RIS Frontend" -Direction Inbound -Protocol TCP -LocalPort 80   -Action Allow
New-NetFirewallRule -DisplayName "RIS API"      -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow
```

---

## 8. Uso diario y actualizaciones

Comandos en `C:\RIS\backend` con `docker compose -f docker-compose.lan.yml`.

Las **actualizaciones** las hace el equipo de sistemas **desde su PC** (con Tailscale para llegar por SSH), no el personal del laboratorio.

---

Problemas frecuentes y checklist: mismos criterios que la guía Ubuntu (firewall, `SESSION_SECURE_COOKIE=false`, URL del PACS, Docker abierto).
