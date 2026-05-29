# Guía de instalación del RIS en un laboratorio

Esta guía explica, paso a paso y en lenguaje sencillo, cómo instalar el sistema
HealthTiCloud RIS en un laboratorio. **No necesita saber programar.** Solo siga los
pasos en orden, copiando y pegando los comandos donde se indica.

> Si en algún paso algo no funciona, vaya al final: **"Problemas frecuentes"**.

---

## 1. ¿Qué vamos a instalar? (resumen)

En el laboratorio habrá **un computador principal** (lo llamaremos **el servidor**).
Ahí se instala el RIS. Las demás computadoras (recepción, médicos) solo usan el
**navegador de internet** (Chrome o Edge) para entrar al sistema.

Tres ideas simples:

- **El programa corre dentro de "Docker".** Docker es como una caja que ya trae todo
  lo que el sistema necesita (base de datos, etc.). Así no hay que instalar mil cosas.
- **Las imágenes médicas (radiografías) NO se guardan en el laboratorio**, sino en un
  servidor central en internet (la "nube"). El laboratorio se conecta a ese servidor.
- **La conexión segura a la nube se hace con "Tailscale"**, un programa que crea una
  especie de túnel privado entre el laboratorio y la nube.

```
   INTERNET (la nube)                         EL LABORATORIO
 ┌───────────────────────┐         ┌──────────────────────────────────────┐
 │  Imágenes médicas      │  túnel  │  SERVIDOR (este computador)            │
 │  (PACS) + Visor        │◄═══════►│  - corre el RIS dentro de Docker       │
 └───────────────────────┘ Tailscale│                                        │
                                     │  Recepción / Médicos → navegador web   │
                                     └──────────────────────────────────────┘
```

---

## 2. Antes de empezar: qué descargar

En **el servidor** (el computador principal del laboratorio) descargue e instale:

| Programa | Para qué sirve | Dónde se descarga |
|----------|----------------|-------------------|
| **Docker Desktop** | Hace funcionar el RIS | https://www.docker.com/products/docker-desktop/ |
| **Tailscale** | Conecta con la nube de forma segura | https://tailscale.com/download/windows |
| **Git** | Descarga el programa del RIS | https://git-scm.com/download/win |

Solo en computadoras que tengan **escáner** de documentos, instale además:

| Programa | Para qué sirve | Dónde se descarga |
|----------|----------------|-------------------|
| **Node.js** (versión LTS) | Conecta el escáner con el RIS | https://nodejs.org/en/download |
| **NAPS2** | Maneja el escáner | https://www.naps2.com/ |

> Las imágenes médicas se ven en un **visor web** (se abre solo en el navegador). **No
> hace falta instalar RadiAnt** ni ningún otro visor de imágenes.

**Consejo:** si el laboratorio tiene mala conexión, lleve estos instaladores en un
pendrive (USB) ya descargados.

---

## 3. Instalar Docker Desktop

1. Ejecute el instalador de **Docker Desktop** que descargó.
2. Acepte las opciones por defecto. Si le pide **reiniciar el computador**, hágalo.
3. Después de reiniciar, abra **Docker Desktop** una vez y espere a que el ícono de la
   ballena (abajo a la derecha, junto al reloj) deje de moverse. Eso significa que está listo.

> Docker Desktop debe quedar **abierto** para que el RIS funcione. Más adelante lo
> dejaremos iniciando automáticamente con Windows.

---

## 4. Conectar con la nube usando Tailscale

1. Ejecute el instalador de **Tailscale** y ábralo.
2. Inicie sesión con la **cuenta de la organización** (se la entrega el responsable de
   sistemas) y autorice este computador.
3. Para comprobar que la conexión funciona, abra **PowerShell** (busque "PowerShell" en
   el menú inicio) y escriba:

```powershell
tailscale status
```

   Debe aparecer una lista de equipos conectados. Anote o pida la **dirección del
   servidor de la nube** (un número parecido a `100.104.4.114`). La necesitará en el paso 7.

> **¿Qué es Tailscale?** Es como una red privada entre el laboratorio y la nube. Sin él,
> el laboratorio no puede ver las imágenes médicas guardadas en la nube.

---

## 5. Obtener la "llave" para descargar el RIS (token)

El programa del RIS está guardado en un sitio privado (GitHub). Para descargarlo, el
servidor necesita una **llave de acceso**, llamada **token**.

El responsable de sistemas le entregará un **token** (un texto largo que empieza con
`github_pat_...` o `ghp_...`). Guárdelo a mano; lo usará en el siguiente paso.

> Si no tiene el token, pídalo a quien administra el repositorio en GitHub. Debe ser un
> token con permiso de **solo lectura** sobre el proyecto del RIS.

---

## 6. Descargar el programa del RIS

1. Abra **PowerShell**.
2. Copie y pegue este comando, **reemplazando `EL_TOKEN`** por el token del paso 5:

```powershell
git clone https://EL_TOKEN@github.com/Raysus/ris.git C:\RIS
```

   Esto crea la carpeta `C:\RIS` con todo el programa.

3. Para que no le vuelva a pedir el token en el futuro, ejecute una sola vez:

```powershell
git config --global credential.helper store
```

---

## 7. Configurar el sistema

1. En PowerShell, entre a la carpeta del programa:

```powershell
cd C:\RIS\backend
```

2. Cree el archivo de configuración a partir del ejemplo:

```powershell
Copy-Item .env.lan.example .env
notepad .env
```

   Se abrirá el Bloc de notas con el archivo de configuración. Cambie estos valores:

| Buscar | Cambiar por | Ejemplo |
|--------|-------------|---------|
| `192.168.1.50` (aparece varias veces) | La dirección del **servidor en la red del laboratorio** | `192.168.1.50` |
| `ORTHANC_URL=...` | La dirección del **servidor de la nube** (paso 4) | `http://100.104.4.114:8042` |
| `DB_PASSWORD=...` | Una **contraseña** que usted invente | `MiClaveSegura2026` |
| `SANCTUM_STATEFUL_DOMAINS=...` | La misma dirección del servidor en la red | `192.168.1.50,localhost,127.0.0.1` |

   Para saber la dirección del servidor en la red del laboratorio, escriba en PowerShell:

```powershell
ipconfig
```

   Use el número que aparece en **"Dirección IPv4"** (ejemplo: `192.168.1.50`).

3. Guarde el archivo (Archivo → Guardar) y cierre el Bloc de notas.

4. Genere la "clave interna" del sistema y péguela en la configuración:

```powershell
docker compose -f docker-compose.lan.yml run --rm api php artisan key:generate --show
```

   Aparecerá un texto que empieza con `base64:`. **Cópielo completo.** Abra de nuevo el
   archivo (`notepad .env`), busque la línea `APP_KEY=` y pegue el texto justo después
   del `=`, de modo que quede así: `APP_KEY=base64:loquesalió`. Guarde y cierre.

---

## 8. Encender el sistema

En PowerShell, dentro de `C:\RIS\backend`, escriba:

```powershell
docker compose -f docker-compose.lan.yml up -d --build
```

La **primera vez tarda varios minutos** (descarga e instala todo). Cuando termine, el
sistema queda funcionando. Para comprobarlo:

```powershell
docker compose -f docker-compose.lan.yml ps
```

Deben aparecer todos los servicios con estado **running** (funcionando).

> **Importante (solo la primera vez):** después de este primer encendido, abra otra vez
> `notepad .env`, busque la línea `DB_AUTO_SEED=true` y cámbiela a `DB_AUTO_SEED=false`.
> Guarde y vuelva a ejecutar `docker compose -f docker-compose.lan.yml up -d`. Esto evita
> que se vuelvan a crear los datos de ejemplo.

---

## 9. Abrir el "candado" de Windows (firewall)

Para que las otras computadoras puedan entrar al sistema, hay que permitir el paso en el
firewall. Abra **PowerShell como Administrador** (clic derecho → "Ejecutar como
administrador") y pegue:

```powershell
New-NetFirewallRule -DisplayName "RIS Frontend" -Direction Inbound -Protocol TCP -LocalPort 80   -Action Allow
New-NetFirewallRule -DisplayName "RIS API"      -Direction Inbound -Protocol TCP -LocalPort 8000 -Action Allow
```

---

## 10. Probar que funciona

**En el servidor**, abra el navegador y entre a:

```
http://localhost
```

Debe aparecer la pantalla de inicio de sesión del RIS.

**Desde otra computadora del laboratorio**, abra el navegador y escriba la dirección del
servidor (la "Dirección IPv4" del paso 7). Por ejemplo:

```
http://192.168.1.50
```

Inicie sesión con el usuario de prueba que le indique el responsable de sistemas (por
ejemplo `rgutierrez`). **Cambie las contraseñas de prueba antes de usarlo de verdad.**

Las imágenes médicas se abren con el botón del visor dentro del RIS (se abre el visor de
la nube automáticamente).

---

## 11. Computadoras de recepción y médicos

No necesitan instalar nada: solo abren el navegador y entran a `http://<dirección del
servidor>` (la del paso 7).

**Solo** si una computadora tiene **escáner** de documentos, hay que instalar el
"puente" del escáner (carpeta `tools/ris-local-bridge`). Este "puente" es un pequeño
programa que conecta el escáner con el RIS. Requiere tener instalados **Node.js** y
**NAPS2** (paso 1).

### Dejar el escáner automático al encender la PC

Para que el puente arranque **solo**, oculto y se reinicie si se cae, ejecute **una sola
vez** en esa computadora (PowerShell, dentro de la carpeta `tools\ris-local-bridge`):

```powershell
Set-ExecutionPolicy -Scope CurrentUser RemoteSigned -Force
.\install-windows-startup.ps1
```

Esto crea una tarea de Windows que inicia el puente **al iniciar sesión** en esa PC, sin
ventana negra visible, y lo vuelve a levantar automáticamente si por algún motivo se cae.

> ¿Por qué "al iniciar sesión" y no "al prender"? El escáner necesita que haya un usuario
> con la sesión abierta para funcionar. Por eso el puente arranca apenas se inicia sesión.

**Para que quede 100% automático al prender el equipo** (sin que nadie escriba la
contraseña), active además el **inicio de sesión automático** de Windows en esa PC:

1. Presione `Windows + R`, escriba `netplwiz` y presione Enter.
2. Seleccione el usuario y **desmarque** "Los usuarios deben escribir su nombre y
   contraseña…".
3. Acepte e ingrese la contraseña de ese usuario cuando se la pida.

Así, al encender la PC: entra sola a la sesión → el puente arranca oculto → el escáner
queda listo. Para comprobar que funciona, abra en el navegador de esa PC
`http://127.0.0.1:8181/health` (debe responder `success`).

---

## 12. Equipos de radiografía (DICOM)

Los equipos que generan imágenes (rayos, ecógrafo, etc.) envían las imágenes al servidor
de la nube. Quien instala el equipo debe configurarlo con estos datos:

| Dato | Valor |
|------|-------|
| AE Title (nombre destino) | `HEALTHTICLOUD` |
| Dirección (IP) | La del **servidor de la nube** (paso 4), por ejemplo `100.104.4.114` |
| Puerto | `4242` |

> Para que el equipo "alcance" la nube, normalmente se activa una opción de Tailscale
> llamada **subnet router** en el servidor. Esto lo hace el responsable de sistemas.

---

## 13. Uso diario (encender, apagar, revisar)

Todos estos comandos se escriben en PowerShell dentro de `C:\RIS\backend`.

| Quiero... | Comando |
|-----------|---------|
| Ver si está funcionando | `docker compose -f docker-compose.lan.yml ps` |
| Apagar el sistema | `docker compose -f docker-compose.lan.yml down` |
| Encender el sistema | `docker compose -f docker-compose.lan.yml up -d` |
| Reiniciar todo | `docker compose -f docker-compose.lan.yml restart` |

Como Docker Desktop arranca con Windows, el sistema vuelve solo cuando se reinicia el
computador. No hace falta hacer nada cada mañana.

### Hacer una copia de seguridad de los datos

De vez en cuando, guarde una copia de la base de datos (citas, pacientes, etc.):

```powershell
docker compose -f docker-compose.lan.yml exec pgsql pg_dump -U risuserdb ris_db > "C:\RIS\respaldo_$(Get-Date -Format yyyyMMdd).sql"
```

Esto crea un archivo `respaldo_FECHA.sql` en `C:\RIS`. Cópielo a un disco externo o nube.

---

## 14. ¿Cómo se actualiza el sistema?

**Usted no tiene que hacer esto.** Las actualizaciones las realiza el equipo de sistemas
**de forma remota** a través de Tailscale, sin ir al laboratorio.

Si alguna vez le piden actualizar manualmente, son solo dos comandos en PowerShell dentro
de `C:\RIS\backend`:

```powershell
git pull
docker compose -f docker-compose.lan.yml up -d --build
```

---

## 15. Problemas frecuentes

| Lo que veo | Qué hacer |
|------------|-----------|
| Otra computadora no abre `http://...` | Revise que escribió bien la dirección (paso 7) y que hizo el paso 9 (firewall). Pruebe `ping <dirección>` desde la otra PC. |
| Al iniciar sesión, no entra / se cae | En `.env`, la línea `SESSION_SECURE_COOKIE` debe decir `false`. Guarde y ejecute `docker compose -f docker-compose.lan.yml up -d`. |
| No aparecen las imágenes médicas | Revise Tailscale: escriba `tailscale status` y `ping 100.104.4.114` (la dirección de la nube). Si no responde, la conexión con la nube está caída. |
| Sale error al encender (`up`) | Verifique que **Docker Desktop está abierto** (ícono de ballena quieto). Vuelva a intentar. |
| Quiero ver qué está fallando | `docker compose -f docker-compose.lan.yml logs api` |

Si nada de esto resuelve, contacte al equipo de sistemas y comparta lo que muestra el
último comando (`logs`).

---

## 16. Lista de verificación final

**El servidor**

- [ ] Docker Desktop instalado y abierto
- [ ] Tailscale conectado (`tailscale status` muestra la nube)
- [ ] Programa descargado en `C:\RIS`
- [ ] Archivo `.env` configurado (dirección del servidor, de la nube, contraseña, `APP_KEY`)
- [ ] `docker compose ... ps` muestra todo en **running**
- [ ] `DB_AUTO_SEED=false` después del primer encendido
- [ ] Firewall abierto (paso 9)
- [ ] Contraseñas de prueba cambiadas
- [ ] Primera copia de seguridad hecha

**Las demás computadoras**

- [ ] Abren `http://<dirección del servidor>` e inician sesión
- [ ] El visor de imágenes se abre desde el RIS

**Equipos de radiografía** (si aplica)

- [ ] Configurados con destino `HEALTHTICLOUD`, dirección de la nube, puerto `4242`
- [ ] Una imagen de prueba se ve en el RIS
