# Compromisos, prioridades y recordatorios por WhatsApp

Contrato **observado** del módulo de compromisos (Spec 0012, caracterización).
Un compromiso es una tarea que sale de una reunión: se asigna a un usuario, tiene
fecha límite y prioridad, y dispara recordatorios escalonados por WhatsApp.

> **Esto es caracterización.** Documenta lo que el código hace hoy, no lo que
> debería hacer. Lo que está mal se marca como ⚠️ y se registra en
> `.specify/context/known-issues.md`; corregirlo es otra spec.

Esta primera versión se escribió **leyendo el código** (Fase 0). Las fases 1–3 la
fijan con pruebas y la fase 4 la corrige con lo que las pruebas demuestren.

---

## Rutas

Todas dentro de `['jwt.auth', 'tenant', 'tenant.active']`. Sin token → 401.

| Endpoint | Permiso | Acción |
| --- | --- | --- |
| `GET /commitments` | `view_commitments` | `index` |
| `GET /commitments/overdue` | `view_commitments` | `overdue` |
| `GET /meetings/{meeting}/commitments` | `view_commitments` | `byMeeting` |
| `POST /commitments/{commitment}/complete` | `edit_commitments` | `complete` |
| `POST /commitments` | `create_commitments` | `store` |
| `GET /commitments/{commitment}` | `view_commitments` | `show` |
| `PUT\|PATCH /commitments/{commitment}` | `edit_commitments` | `update` |
| `DELETE /commitments/{commitment}` | `delete_commitments` | `destroy` |
| `GET /priorities`, `GET /priorities/{priority}` | **ninguno** | catálogo abierto a cualquier usuario del tenant |
| `POST\|PUT\|DELETE /priorities...` | `role:admin` | administración del catálogo |

Las rutas literales van **antes** del `apiResource` (Spec 0001): si no,
`{commitment}` captura `overdue`.

## Modelo

`commitments` — `HasTenant`, `SoftDeletes`, auditable (`owen-it`) y con
activity log (`description`, `status`, `due_date`).

| Columna | Tipo | Nota |
| --- | --- | --- |
| `meeting_id` | FK reuniones, **NOT NULL**, `onDelete('cascade')` | |
| `tenant_id` | FK tenants, NOT NULL | lo rellena `HasTenant` |
| `description` | text NOT NULL | |
| `assigned_user_id` | FK users **nullable**, `onDelete('set null')` | |
| `priority_id` | FK priorities **nullable**, `onDelete('set null')` | |
| `due_date` | date **nullable**, cast `date` | sin hora |
| `status` | enum(`pending`,`in_progress`,`completed`,`cancelled`) default `pending` | |
| `notes` | text nullable | |
| `created_by` | FK users NOT NULL | |

`priorities` es un **catálogo global**: no tiene `tenant_id` ni usa `HasTenant`.
Lo siembra `PrioritySeeder` (Baja, Media, Alta, Urgente).

## Recordatorios — `scheduleCommitmentReminders`

Vive **privado dentro de `CommitmentController`** y solo corre en `store`.
Con `$totalDays = now()->diffInDays($commitment->due_date)`:

1. `assignment` — inmediato, sin `delay`.
2. Si `$totalDays > 2`:
   - `50_percent` → `delay(now + (int)($totalDays * 0.5) días)`
   - `25_percent` → `delay(now + (int)($totalDays * 0.75) días)`
3. `due_date` → `delay(due_date a las 08:00)`, solo si esa fecha es futura.

Si el usuario asignado no existe o no tiene `phone`, **no encola nada** y `store`
responde `whatsapp_notification_sent: false`.

`SendCommitmentReminderJob::handle()` vuelve a comprobar en tiempo de ejecución:
salta si el compromiso está `completed`, salta si no hay teléfono, y si envía
descuenta un crédito de WhatsApp del tenant.

---

*Las secciones de detalle (payloads, filtros, casos borde y hallazgos) las
completa la fase 4 con la evidencia de las pruebas.*
