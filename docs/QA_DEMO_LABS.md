# QA — Laboratorios demo (sin SIRESA)

## Usuarios de prueba

| Usuario | Contraseña | Roles |
|---------|------------|-------|
| `rgutierrez` | `rgutierrez` | admin, recepción, tecnólogo, radiólogo, transcriptor |
| `friquelme` | `friquelme` | tecnólogo |

## Laboratorios demo a probar

- Centro de Diagnóstico RIS PRO (clínico, worklist)
- Sucursal Sur (clínico)
- Dental Demo — CBCT Temuco / Sucursal Centro (atención en salas, sin FONASA)
- Veterinaria Demo Sur / Urgencias 24h (mascota, atención en salas)

**No usar** sedes Siresa (5–8) en esta checklist.

## Checklist manual (frontend) — todos los módulos

1. Login → sede **concreta** (no “Todas mis sucursales”).
2. **Dashboard** — KPIs del día con citas DEMO.
3. **Agenda** — calendario, buscar paciente demo, wizard 4 pasos, guardar cita.
4. **Worklist** (RIS PRO) — fila `DEMO-L1-WORKLIST`, envío DICOM simulado.
5. **Atención en salas** (Dental/Vet) — fila `DEMO-L9-ATENCION`, subida manual.
6. **Radiólogo** — `DEMO-L1-RADIOLOGO` / informe.
7. **Transcripción** — `DEMO-L1-TRANSCRIPCION`.
8. **Validación** — `DEMO-L1-VALIDACION`.
9. **Entrega** — `DEMO-L1-ENTREGA` / `ENTREGADO`.
10. **Admin** — salas, exámenes, usuarios, plantillas, insumos (sede concreta).
11. Logout/login → perfil de lab coherente.

Usuarios: `rgutierrez` (multi-módulo), `friquelme` (tecnólogo).

## Tests automatizados

```bash
cd backend
docker compose up -d
php artisan migrate --force
php artisan db:seed
php artisan test --filter=DemoLabsQaTest
```

Con API en marcha (`php artisan serve`):

```bash
php tests/demo_qa_flow.php http://127.0.0.1:8000
```

Cubre: lab-profile, agenda, worklist, radiólogo, transcripción, validación, entrega, dashboard, admin (templates, supplies, users, settings, payments).
