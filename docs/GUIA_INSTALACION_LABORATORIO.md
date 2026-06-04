# Guía de instalación del RIS en un laboratorio (Ubuntu Server)

Esta guía explica, paso a paso y en lenguaje sencillo, cómo instalar el sistema
HealthTiCloud RIS en el **servidor principal** de un laboratorio con **Ubuntu Server
22.04 o 24.04 LTS**. **No necesita saber programar.** Copie y pegue los comandos en orden.

> Si el servidor es **Windows**, use **[GUIA_INSTALACION_LABORATORIO_WINDOWS.md](GUIA_INSTALACION_LABORATORIO_WINDOWS.md)**.
>
> Si algo falla, vaya a **"Problemas frecuentes"** al final.

---

## 1. ¿Qué vamos a instalar? (resumen)

En el laboratorio hay **un servidor Ubuntu** donde corre el RIS (en Docker). Recepción,
médicos y tecnólogos entran con el **navegador** (Chrome o Firefox).

```
   INTERNET (nube — PACS / visor)              EL LABORATORIO
 ┌─────────────────────────┐         ┌────────────────────────────────────┐
 │  Imágenes (Orthanc)      │  red    │  SERVIDOR Ubuntu (este equipo)       │
 │  + Visor web             │◄───────►│  Docker: RIS + base de datos        │
 └─────────────────────────┘         │  PCs del centro → http://IP-servidor │
                                     └────────────────────────────────────┘
```

Tres ideas importantes:

- **Docker** empaqueta la base de datos, la API y el sitio web del RIS.
- **Las imágenes médicas no se guardan en el laboratorio**; el RIS se conecta al PACS en la nube.
- **Tailscale en el servidor del lab es opcional** para el día a día del centro, pero **recomendado**
  si sistemas debe entrar por SSH sin estar en la misma LAN (véase sección 11 y la nota siguiente).

### Nota sobre Tailscale

| Quién | ¿Necesita Tailscale? |
|-------|----------------------|
| PCs de usuarios del RIS (recepción, médicos) | **No** |
| Servidor del laboratorio | **Opcional** — **sí** si soporte remoto / `deploy-lab` por VPN |
| **Quien despliega o actualiza** (SSH, Envoy) | **Sí**, en **su** computador (misma red Tailscale que el servidor, si aplica) |

El personal del centro usa el RIS por `http://<IP-LAN>`; no instale Tailscale en cada PC.
El equipo de sistemas entra a la **nube** y a los **labs** con Envoy (`deploy-nube`, `deploy-lab`)
usando la IP Tailscale del servidor (ej. `100.104.4.20`), cuando el lab tiene Tailscale activo.
La **URL del PACS** en `.env` sigue siendo la que indique sistemas (no depende de Tailscale en el lab).

---

## 2. Antes de empezar

### En el servidor Ubuntu

| Requisito | Detalle |
|-----------|---------|
| Sistema | Ubuntu Server **22.04 o 24.04 LTS** (64 bits) |
| RAM | Mínimo **8 GB** (recomendado 16 GB) |
| Disco | Mínimo **50 GB** libres |
| Red | IP fija en la LAN (ej. `192.168.1.50`) |
| Acceso | Usuario con `sudo` y conexión a internet para la primera instalación |
| PHP en Docker | **8.3** (imagen `php:8.3-cli` en `backend/Dockerfile`; igual que `composer.json`) |

### En PCs con escáner (opcional)

| Programa | Uso |
|----------|-----|
| **Node.js** (LTS) | Puente escáner (`tools/ris-local-bridge`) |
| **NAPS2** | Control del escáner |

Las demás PCs del laboratorio **solo necesitan navegador**.

---

## 3. Instalar Docker (motor, no “Desktop”)

Conéctese al servidor por consola o SSH y ejecute **como usuario normal** (pedirá su clave con `sudo`):

```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl git gnupg
```

Instale Docker desde el repositorio oficial:

```bash
sudo install -m 0755 -d /etc/apt/keyrings
curl -fsSL https://download.docker.com/linux/ubuntu/gpg | sudo gpg --dearmor -o /etc/apt/keyrings/docker.gpg
sudo chmod a+r /etc/apt/keyrings/docker.gpg

echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.gpg] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo "${VERSION_CODENAME}") stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null

sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
```

Permita usar Docker sin `sudo` y active el servicio al arrancar:

```bash
sudo usermod -aG docker "$USER"
sudo systemctl enable docker
sudo systemctl start docker
```

> **Importante:** cierre sesión y vuelva a entrar (o reinicie) para que el grupo `docker` aplique.

Compruebe:

```bash
docker compose version
docker run --rm hello-world
```

---

## 4. Dirección del PACS en la nube (sin instalar Tailscale aquí)

El responsable de sistemas le dará la **URL del Orthanc/PACS** y, si aplica, la **IP y
puerto DICOM** para los equipos de rayos.

Ejemplos típicos en el archivo `.env` (más adelante):

| Variable | Ejemplo |
|----------|---------|
| `ORTHANC_URL` | `https://pacs.healthticloud.cl` o `http://100.104.4.114:8042` |
| `PACS_DEFAULT_IP` | IP o hostname del PACS en nube |
| `PACS_DEFAULT_PORT` | `4242` |

Para comprobar que el servidor **alcanza** el PACS (sustituya la URL que le dieron):

```bash
curl -sS -o /dev/null -w "%{http_code}\n" "https://pacs.healthticloud.cl/system" 
# o la ruta /system de su ORTHANC_URL
```

Un código `200` u otro distinto de “no conecta” indica que hay ruta de red; si falla,
avise a sistemas (firewall del centro o reglas en la nube).

---

## 5. Obtener la llave para descargar el RIS (token GitHub)

El código está en GitHub privado. Necesita un **token** (`github_pat_...` o `ghp_...`)
con permiso de **solo lectura**, entregado por el equipo de sistemas.

---

## 6. Descargar el programa

```bash
sudo mkdir -p /opt
sudo chown "$USER:$USER" /opt
cd /opt

# Reemplace EL_TOKEN por el token del paso 5
git clone https://EL_TOKEN@github.com/Raysus/ris.git RIS
cd RIS

git config --global credential.helper store
```

---

## 7. Configurar el sistema

```bash
cd /opt/RIS/backend
cp .env.lan.example .env
nano .env
```

El archivo `.env.lan.example` replica las variables importantes de `.env.example`,
adaptadas al stack Docker LAN (PostgreSQL en contenedor `pgsql`, PACS/visor en nube).

En `nano`: edite, luego `Ctrl+O`, Enter, `Ctrl+X`.

| Buscar | Poner |
|--------|--------|
| `192.168.1.50` (todas las apariciones) | IP **real** del servidor en la LAN |
| `ORTHANC_URL` | URL del PACS que le dio sistemas |
| `PACS_DEFAULT_IP` / `PACS_DEFAULT_PORT` / `PACS_DEFAULT_AET` | Datos DICOM para equipos (si aplica) |
| `DB_PASSWORD` | Contraseña fuerte inventada por usted |
| `SANCTUM_STATEFUL_DOMAINS` | Misma IP del servidor + `localhost,127.0.0.1` |
| `VIEWER_URL` / `VIEWER_PATH` | Visor OHIF en nube (`https://viewer.healthticloud.cl`, `/viewer`) |
| `PATIENT_PORTAL_URL` | `https://portal.healthticloud.cl` (enlaces en correos) |

Obtenga la IP del servidor:

```bash
hostname -I | awk '{print $1}'
```

Genere la clave interna (`APP_KEY`):

```bash
docker compose -f docker-compose.lan.yml run --rm api php artisan key:generate --show
```

Copie la línea `base64:...` completa, ábrala de nuevo con `nano .env`, pegue en `APP_KEY=`.

---

## 8. Encender el sistema

```bash
cd /opt/RIS/backend
docker compose -f docker-compose.lan.yml up -d --build
```

La primera vez puede tardar **varios minutos**. Verifique:

```bash
docker compose -f docker-compose.lan.yml ps
```

Todos los servicios deben estar **running**.

**Solo la primera vez:** después del primer arranque exitoso:

```bash
nano .env
# Cambie DB_AUTO_SEED=true a DB_AUTO_SEED=false
docker compose -f docker-compose.lan.yml up -d
```

---

## 9. Firewall (UFW)

Permita el acceso desde otras PCs de la LAN:

```bash
sudo ufw allow OpenSSH
sudo ufw allow 80/tcp
sudo ufw allow 8000/tcp
sudo ufw enable
sudo ufw status
```

---

## 10. Probar que funciona

**En el servidor:**

```
http://localhost
```

**Desde otra PC del laboratorio** (misma red Wi‑Fi/cable):

```
http://192.168.1.50
```

(use su IP real). Debe verse la pantalla de inicio de sesión.

Inicie sesión con el usuario de prueba que indique sistemas y **cambie contraseñas de
prueba** antes de uso real.

El visor de imágenes se abre desde el botón correspondiente en el RIS (visor en la nube).

---

## 11. Configurar Tailscale en el servidor (opcional)

Use este paso **solo si el equipo de sistemas se lo indica** o si el laboratorio necesita
soporte remoto y despliegues con `envoy run deploy-lab` sin depender de estar en la misma red local.

**No es necesario** para que recepción y médicos usen el RIS en el navegador.

### 11.1 Instalar Tailscale en Ubuntu Server

Conéctese al servidor por consola o SSH y ejecute:

```bash
curl -fsSL https://tailscale.com/install.sh | sh
sudo systemctl enable --now tailscaled
```

### 11.2 Unir el servidor a la red Tailscale

El administrador de sistemas le entregará una de estas formas de registro:

| Método | Comando / acción |
|--------|------------------|
| Enlace de invitación | Abra la URL en un navegador **desde el servidor** (o use el login que indique sistemas) y luego: `sudo tailscale up` |
| Clave de auth (auth key) | `sudo tailscale up --auth-key=tskey-auth-XXXXXXXX` (la clave la entrega sistemas; **no la publique**) |
| Login interactivo | `sudo tailscale up` y siga el enlace que muestra la consola |

Compruebe que quedó conectado:

```bash
tailscale status
tailscale ip -4
```

Anote la IP que empieza por `100.` (ej. `100.104.4.20`). Esa es la que usará sistemas en
`backend/Envoy.blade.php` para `deploy-lab`.

### 11.3 SSH y firewall

- Tailscale suele permitir SSH entre nodos de la misma cuenta sin abrir el puerto 22 a Internet.
- Con **UFW** activo (paso 9), normalmente **no hace falta** abrir puertos extra para Tailscale;
  el tráfico entra por la interfaz `tailscale0`.
- Si sistemas usa **Tailscale SSH**, puede pedirle que apruebe el nodo en la consola de Tailscale
  la primera vez que conecte.

Prueba desde el PC de sistemas (con Tailscale activo en esa máquina):

```bash
ssh usuario@100.104.4.XX
```

(Sustituya usuario e IP por los que le indiquen.)

### 11.4 Mantener Tailscale al reiniciar

El servicio `tailscaled` ya queda habilitado con `systemctl enable`. Tras un reinicio del servidor:

```bash
tailscale status
```

Si aparece **offline**, ejecute de nuevo `sudo tailscale up` (o con la misma `--auth-key` si usó clave).

### 11.5 Qué comunicar a sistemas

Envíe por correo o ticket:

| Dato | Ejemplo |
|------|---------|
| IP Tailscale IPv4 | `100.104.4.20` |
| Nombre del nodo en Tailscale | `lab-lautaro-srv` |
| Usuario SSH en el servidor | `admin` |
| Ruta del proyecto RIS | `/opt/RIS` |

Con eso sistemas puede ejecutar desde su PC:

```bash
cd backend
php vendor/bin/envoy run deploy-lab --lab=lab_lautaro
```

(el alias `lab_lautaro` debe existir en `Envoy.blade.php` con la IP Tailscale correcta).

> **Privacidad:** Tailscale cifra el tráfico entre nodos; no sustituye las políticas de acceso
> del centro. Solo personal autorizado debe tener cuenta en la misma red Tailscale.

---

## 12. Otras computadoras y escáner

- **Recepción / médicos / tecnólogos:** solo navegador → `http://<IP-del-servidor>`.
- **PC con escáner:** instale Node.js y NAPS2; en la carpeta del puente:

```bash
cd /opt/RIS/tools/ris-local-bridge
cp config.example.json config.json
# Editar config.json según INSTRUCTIVO / soporte
npm install
```

Arranque manual de prueba:

```bash
./start-bridge.sh
```

Compruebe en esa PC: `http://127.0.0.1:8181/health` → debe responder éxito.

Para dejar el puente al iniciar sesión en Ubuntu desktop, puede crear un servicio
`systemd` de usuario (pida a sistemas el unit o use la tarea programada equivalente).

---

## 13. Equipos de radiografía (DICOM)

Configure en cada modalidad (lo hace el técnico del equipo):

| Dato | Valor típico |
|------|----------------|
| AE Title destino | `HEALTHTICLOUD` |
| IP / hostname | El que indique sistemas (PACS en nube) |
| Puerto | `4242` |

El laboratorio **no** necesita Tailscale para esto si el PACS es alcanzable por la red
que defina el centro (ruta VPN del proveedor, IP pública, etc.).

---

## 14. Uso diario

Comandos en `/opt/RIS/backend`:

| Acción | Comando |
|--------|---------|
| Ver estado | `docker compose -f docker-compose.lan.yml ps` |
| Apagar | `docker compose -f docker-compose.lan.yml down` |
| Encender | `docker compose -f docker-compose.lan.yml up -d` |
| Reiniciar | `docker compose -f docker-compose.lan.yml restart` |
| Ver errores API | `docker compose -f docker-compose.lan.yml logs -f api` |

Docker suele iniciar solo al encender el servidor (`systemctl enable docker`).

### Copia de seguridad

```bash
cd /opt/RIS/backend
docker compose -f docker-compose.lan.yml exec -T pgsql pg_dump -U risuserdb ris_db > "/opt/RIS/respaldo_$(date +%Y%m%d).sql"
```

Guarde el archivo `.sql` en disco externo o nube corporativa.

---

## 15. Actualizaciones del sistema

**El personal del laboratorio no debe actualizar el RIS.**

Las actualizaciones las realiza el **equipo de sistemas** desde **su computador**
(con Tailscale en su PC y, si aplica, Tailscale en el servidor del lab — sección 11)
usando Envoy o comandos equivalentes:

```bash
# Ejemplo (lo ejecuta sistemas en el servidor, no recepción)
cd /opt/RIS && git pull
cd backend && docker compose -f docker-compose.lan.yml up -d --build
```

Si le piden una actualización manual excepcional, use exactamente esos dos bloques.

---

## 16. Problemas frecuentes

| Síntoma | Qué hacer |
|---------|-----------|
| Otra PC no abre `http://IP` | Compruebe IP con `hostname -I`, reglas UFW (paso 9), que el servidor esté encendido. Pruebe `ping IP` desde la otra PC. |
| Login no guarda sesión / se cae | En `.env`: `SESSION_SECURE_COOKIE=false`. `docker compose -f docker-compose.lan.yml up -d`. |
| No hay imágenes / visor vacío | Pruebe `curl` a `ORTHANC_URL` desde el servidor. Si falla, es red o URL incorrecta — contacte sistemas (no es problema del navegador del usuario). |
| `permission denied` con Docker | Usuario en grupo `docker`, cerrar sesión y volver a entrar. |
| Error al `up --build` | `docker compose ... logs api` y `logs pgsql`. Verifique espacio en disco: `df -h`. |
| Error de versión PHP / Composer | Reconstruya imagen: `docker compose -f docker-compose.lan.yml build --no-cache api`. Debe usar PHP **8.3** (no mezclar con PHP 8.4+ del host). |
| Puerto 80 ocupado | `sudo ss -tlnp | grep :80` — otro servicio (Apache/nginx) puede chocar; avise a sistemas. |
| Tailscale «offline» tras reinicio | `sudo systemctl status tailscaled` · `sudo tailscale up` · verifique con `tailscale status`. |
| Sistemas no entra por SSH a la IP `100.x` | Confirme que el nodo está en la misma cuenta Tailscale, IP con `tailscale ip -4`, y usuario SSH correcto. |

---

## 17. Lista de verificación final

**Servidor Ubuntu**

- [ ] Docker instalado (`docker compose version` OK)
- [ ] Proyecto en `/opt/RIS`
- [ ] `.env` con IP LAN, `ORTHANC_URL`, `DB_PASSWORD`, `APP_KEY`
- [ ] `docker compose ... ps` → todo **running**
- [ ] `DB_AUTO_SEED=false` tras el primer arranque
- [ ] UFW permite 80 y 8000
- [ ] `http://localhost` muestra login
- [ ] Otra PC abre `http://<IP-servidor>`
- [ ] Contraseñas de prueba cambiadas
- [ ] Primera copia de seguridad `.sql`

**Tailscale (solo si sistemas lo pidió)**

- [ ] `tailscaled` activo (`systemctl status tailscaled`)
- [ ] `tailscale ip -4` muestra IP `100.x.x.x`
- [ ] IP y usuario SSH comunicados al equipo de sistemas

**Equipos de rayos** (si aplica)

- [ ] Destino DICOM según tabla del paso 13
- [ ] Imagen de prueba visible en el RIS

---

**Documentación técnica adicional:** [INSTALACION.md](INSTALACION.md) · **Despliegues remotos:** `backend/Envoy.blade.php` (IP Tailscale del lab en `@servers`).
