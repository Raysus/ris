# Punto 2 — Menos fricción clínica

Funcionalidades para reducir trabajo manual en recepción, tecnología y radiología.

> **Portal de pacientes:** no está integrado en este RIS. Usa el portal externo **https://portal.healthticloud.cl**. El visor OHIF para el personal está en **https://viewer.healthticloud.cl** (módulos Radiólogo y Validación).

---

## 1. Correos al paciente

| Correo | Cuándo | Cómo |
|--------|--------|------|
| Confirmación de cita | Al crear cita en Agenda | Automático si `MAIL_SEND_CONFIRMATION=true` y hay email |
| Instrucciones de preparación | Al crear cita (no ambulatorio) | Ya existía |
| Recordatorio 24 h | Día anterior, 18:00 | Scheduler `ris:send-appointment-reminders` |
| Resultados disponibles | Al enviar desde Entrega | Botón correo + `ReportReadyMail` |

Variables en `.env`:

```env
MAIL_MAILER=smtp
MAIL_SEND_CONFIRMATION=true
PATIENT_PORTAL_URL=https://portal.healthticloud.cl
```

---

## 2. PACS — estado automático

Tras enviar DICOM (`dicom_enviado`), el comando `ris:sync-orthanc-status` consulta Orthanc cada 5 minutos.

Si hay instancias para el `accession_number`:

- Estado → `en_atencion`
- Campo `images_received_at` registrado

```env
ORTHANC_URL=http://127.0.0.1:8042
```

---

## 3. Visor OHIF (módulos internos del RIS)

Usado en **Radiólogo** y **Validación** (`js/core/viewer.js`):

- Intenta abrir visor de escritorio vía bridge (`PACS_BRIDGE_URL`)
- Si falla, abre **OHIF** en `https://viewer.healthticloud.cl?AccessionNumber={accession}`

```env
VIEWER_TYPE=ohif
VIEWER_URL=https://viewer.healthticloud.cl
VIEWER_ACCESSION_PARAM=AccessionNumber
VIEWER_TOKEN=
PACS_BRIDGE_URL=http://localhost:8181/open-dicom
PACS_DEFAULT_IP=172.16.66.11
PACS_DEFAULT_PORT=4242
PACS_DEFAULT_AET=HealthTICloud
```

API: `GET /api/viewer-config` (requiere login del personal).

---

## 4. Dashboard — alertas operativas

`GET /api/dashboard/metrics` incluye array `alerts` (transcripción, radiología, DICOM atascado, TAT, citas sin confirmar).

```env
DASHBOARD_TAT_ALERT_MINUTES=180
```

---

## Despliegue

```bash
cd backend
sudo -u www-data php artisan migrate --force
sudo -u www-data php artisan optimize
sudo systemctl restart ris-scheduler ris-queue
```
