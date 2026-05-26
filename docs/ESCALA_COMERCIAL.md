# Punto 3 — Escala comercial

Integración hospitalaria, facturación Chile (FONASA + DTE), FHIR, sync cloud y consolidación multi-sede.

> **No incluido:** refactor del frontend a Vite (se mantiene HTML/JS actual).

---

## Tipos de laboratorio (clínico / dental / veterinario)

El RIS adapta pantallas y APIs según el **tipo de laboratorio** (`laboratory_types.code`):

| Código | Tipo | FONASA / bonos | Previsión clínica (FONASA/ISAPRE) |
|--------|------|----------------|-----------------------------------|
| `clinical` | Clínico humano | Sí | Sí |
| `dental` | Centro dental | No | Oculto en agenda |
| `veterinary` | Veterinario | No | Solo Particular / Convenios |

- `GET /api/lab-profile` — perfil del laboratorio activo (`X-Lab-Id`)
- El login devuelve `contexto_laboratorio.perfil_laboratorio`
- Bonos FONASA → **403** en dental/veterinario
- Catálogo agenda filtra previsiones FONASA

Asigne el tipo correcto en **Admin → Ajustes** al crear o editar la matriz/sucursal.

---

## 1. Sincronización cloud

Cada envío queda en `cloud_sync_logs` (pending / success / failed / skipped).

- **Admin → Sync Nube** — listado, estadísticas, reintentar fallidos
- `GET /api/integrations/cloud-sync`
- `POST /api/integrations/cloud-sync/{id}/retry`

```env
CLOUD_SERVER_URL=
CLOUD_SYNC_SECRET=
QUEUE_CONNECTION=database
```

---

## 2. HL7 v2 / HIS

- Entrada **ORM^O01** → cita automática
- Salida **ORU^R01** opcional al firmar informe
- **Admin → HL7 / HIS**

```env
HL7_ENABLED=true
HL7_INBOUND_SECRET=
HL7_OUTBOUND_URL=
HL7_SEND_ORU_ON_SIGN=false
```

`POST /api/hl7/inbound` + headers `X-HL7-Secret`, `X-Lab-Id`

---

## 3. FONASA y bonos

Registro y validación de bonos (manual o electrónico) vinculados a la cita.

### Agenda (paso Facturación)

Si **Tipo Bono** = Manual o Electrónico, aparece panel FONASA:

- Registrar folio
- Validar (API externa o simulación local)
- Montos bonificación / copago según plan y arancel

### API

| Método | Ruta |
|--------|------|
| GET | `/api/fonasa/appointments/{id}/preview` |
| GET | `/api/fonasa/appointments/{id}/bono` |
| POST | `/api/fonasa/appointments/{id}/bono` |
| POST | `/api/fonasa/appointments/{id}/bono/{bonoId}/validate` |

```env
FONASA_ENABLED=true
FONASA_API_URL=          # validador externo (opcional)
FONASA_API_TOKEN=
FONASA_SIMULATE=true     # si no hay API, valida folio ≥6 chars y RUT formato
FONASA_DEFAULT_BONIFICACION_PCT=80
```

---

## 4. DTE (boleta / factura electrónica)

Genera payload tributario (estructura SII simplificada) y envía a proveedor o simula en local.

### Agenda

Botones **Emitir boleta** / **Emitir factura** (cita guardada).

### Admin → DTE / Boletas

Listado de documentos emitidos.

### API

| Método | Ruta |
|--------|------|
| GET | `/api/billing/appointments/{id}/dte/preview` |
| POST | `/api/billing/appointments/{id}/dte` |
| GET | `/api/billing/appointments/{id}/dte` |
| GET | `/api/billing/dte` (admin) |

```env
DTE_ENABLED=true
DTE_PROVIDER_URL=        # API facturador (opcional)
DTE_PROVIDER_TOKEN=
DTE_EMISOR_RUT=
DTE_EMISOR_RAZON_SOCIAL=HealthTiCloud RIS
DTE_SIMULATE=true        # guarda JSON en storage/app/public/dte/
```

---

## 5. FHIR R4

Interoperabilidad moderna (además de HL7 v2).

| Recurso | Lectura |
|---------|---------|
| Patient | `GET /api/fhir/Patient/{id}` |
| ServiceRequest | `GET /api/fhir/ServiceRequest?appointment={uuid}` |
| DiagnosticReport | `GET /api/fhir/DiagnosticReport?appointment={uuid}` |
| Bundle cita | `GET /api/fhir/Appointment/{id}/bundle` |

**Crear orden (sin token Sanctum, con secreto):**

```http
POST /api/fhir/ServiceRequest
X-FHIR-Secret: ...
X-Lab-Id: {lab-uuid}
Content-Type: application/json

{ "resourceType": "ServiceRequest", "subject": { "reference": "Patient/{id}" }, ... }
```

Convierte internamente a ORM HL7 y crea la cita.

```env
FHIR_ENABLED=true
FHIR_INBOUND_SECRET=
```

`GET /api/fhir/metadata` — CapabilityStatement (público)

---

## 6. Cierre de caja

`GET /api/payments/reports/cash-close?fecha=YYYY-MM-DD`  
**Admin → Cierre de Caja** + export Excel.

---

## 7. Consolidación matriz + sucursales

Producción y cobros por sede en un mes (red completa según laboratorios permitidos del usuario).

`GET /api/reports/consolidated-matrix?month=2026-05`

**Admin → Matriz / Sucursales** + export Excel.

---

## Despliegue

```bash
cd backend
php artisan migrate --force
php artisan config:clear
```

Worker de colas para sync cloud, ORU HL7 y tareas pesadas.

---

## Simulación vs producción

| Módulo | Sin URL proveedor | Con URL proveedor |
|--------|-------------------|-------------------|
| FONASA | `FONASA_SIMULATE=true` | `FONASA_API_URL` + token |
| DTE | `DTE_SIMULATE=true` → folio `SIM-…` | `DTE_PROVIDER_URL` |
| Cloud | `skipped` si no hay URL | `CLOUD_SERVER_URL` |

Cuando conecte APIs reales (IMED, facturador SII, etc.), solo actualice `.env` sin cambiar el flujo en Agenda/Admin.
