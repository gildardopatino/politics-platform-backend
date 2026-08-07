# Compromisos, prioridades y recordatorios por WhatsApp

Contrato **observado** del módulo de compromisos (Spec 0012, caracterización).
Un compromiso es una tarea que sale de una reunión: se asigna a un usuario, tiene
fecha límite y prioridad, y dispara recordatorios escalonados por WhatsApp.

> **Esto es caracterización.** Documenta lo que el código hace hoy, no lo que
> debería hacer. Lo que está mal se marca ⚠️ y queda registrado en
> `.specify/context/known-issues.md`; corregirlo es otra spec.

Pruebas que lo sostienen:

| Archivo | Qué fija |
| --- | --- |
| `tests/Feature/Commitments/CommitmentCharacterizationTest.php` (26) | CRUD, acciones, permisos, aislamiento |
| `tests/Feature/Commitments/CommitmentRemindersCharacterizationTest.php` (17) | encolado de recordatorios y el job en ejecución |
| `tests/Feature/Priorities/PriorityCharacterizationTest.php` (14) | catálogo de prioridades y su relación |
| `tests/Feature/Commitments/CommitmentOverdueTest.php` (5) | regresión de orden de rutas (Spec 0001) |

---

## Rutas

Todas dentro de `['jwt.auth', 'tenant', 'tenant.active']`. Sin token → 401.
Sin el permiso → 403. Un recurso de otro tenant → 404 (el binding va acotado).

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

`commitments` — `HasTenant`, `SoftDeletes`, auditable (`owen-it`) y con activity
log de `description`, `status` y `due_date`.

| Columna | Tipo | Nota |
| --- | --- | --- |
| `meeting_id` | FK reuniones, NOT NULL, `onDelete('cascade')` | |
| `tenant_id` | FK tenants, NOT NULL | lo rellena `HasTenant` |
| `description` | text NOT NULL | |
| `assigned_user_id` | FK users **nullable**, `onDelete('set null')` | |
| `priority_id` | FK priorities **nullable**, `onDelete('set null')` | |
| `due_date` | date **nullable**, cast `date` | **sin hora** |
| `status` | enum(`pending`,`in_progress`,`completed`,`cancelled`) default `pending` | |
| `notes` | text nullable | |
| `created_by` | FK users NOT NULL | |

`priorities` es un **catálogo global**: no tiene `tenant_id` ni usa `HasTenant`.
Lo siembra `PrioritySeeder` (Baja, Media, Alta, Urgente). Sin `softDeletes`.

---

## `GET /commitments`

`spatie/laravel-query-builder`. Paginado de 15 (`?per_page=`).

- **Filtros**: `filter[status]`, `filter[meeting_id]`, `filter[assigned_user_id]`,
  `filter[priority_id]`.
- **Orden**: `sort=due_date|created_at|status` (y su `-` descendente).
- **Includes**: `meeting`, `assignedUser`, `priority`, `createdBy`. Los tres
  primeros viajan **siempre** (el controller hace un `with()` fijo); `createdBy`
  hay que pedirlo con `?include=createdBy` y aparece como `creator`.

```json
{ "data": [ { "id": 1, "status": "pending", "due_date": "2026-08-27" } ],
  "meta": { "total": 1, "current_page": 1, "last_page": 1, "per_page": 15 } }
```

`CommitmentResource` publica `id`, `tenant_id`, `meeting_id`, `description`,
`due_date` (solo fecha), `status`, `notes`, `assigned_user_id`, `created_by`,
`priority_id`, timestamps y `deleted_at`; las relaciones solo si están cargadas.

## `POST /commitments`

| Campo | Regla |
| --- | --- |
| `meeting_id` | requerido, `exists:meetings,id` |
| `assigned_user_id` | requerido, `exists:users,id` |
| `priority_id` | requerido, `exists:priorities,id` |
| `description` | requerido, string |
| `due_date` | requerido, `date` |
| `status` | opcional, `in:scheduled,pending,in_progress,completed,cancelled` |
| `notes` | opcional |

Los mensajes de validación ya están en español. `tenant_id` y `created_by` salen
de la sesión, no del payload. Responde `201` con una clave extra:

```json
{ "data": { ... }, "message": "Commitment created successfully",
  "whatsapp_notification_sent": true }
```

`whatsapp_notification_sent` **no** dice que se haya enviado nada: solo que se
encoló el aviso de asignación, cosa que requiere que la persona asignada tenga
`phone`.

⚠️ `data.status` llega **`null`** aunque en la base valga `pending`: el
controller no recarga el modelo tras crearlo (misma clase de bug que la Spec 0021
cerró en reuniones y asistentes).

⚠️ Los `exists:` consultan la tabla en crudo, sin `TenantScope`: se puede colgar
el compromiso de la **reunión de otra campaña** y asignárselo a alguien ajeno. No
se filtran datos (las relaciones se cargan vacías) pero el vínculo queda escrito.

⚠️ `scheduled` pasa el validador y **no existe en el enum de la columna**: la
petición revienta con 500.

⚠️ `due_date` solo se valida como fecha: se puede crear un compromiso ya vencido.

## `GET /commitments/{commitment}`

Carga `meeting`, `assignedUser`, `priority` y `createdBy`. Otro tenant → 404.

## `PUT|PATCH /commitments/{commitment}`

Todos los campos `sometimes`; solo cambia lo enviado.

⚠️ `status` admite además `scheduled` y **`no_conmpleted`** —con la errata—,
ninguno en el enum de la columna: 500 al guardar.

⚠️ **No reprograma recordatorios.** Cambiar `due_date` o `assigned_user_id` no
encola ni cancela nada: los avisos viejos siguen en pie con las fechas viejas y
la persona nueva no recibe el suyo.

## `DELETE /commitments/{commitment}`

Borrado **en blando**. Responde `200`; a partir de ahí `show` da 404.

## `POST /commitments/{commitment}/complete`

Pone `status = completed`. Responde `200`.

⚠️ Intenta escribir `fecha_cumplimiento`, que **no existe como columna ni está en
`$fillable`**: el asignamiento masivo lo descarta en silencio, así que **no queda
constancia de cuándo se cumplió** el compromiso.

⚠️ Responde **sin cargar relaciones** (a diferencia de store/update/show): la
misma entidad tiene dos formas según el endpoint.

⚠️ No mira el estado previo: se puede «completar» un compromiso cancelado o ya
completado, tantas veces como se llame. No hay máquina de estados (igual que en
reuniones).

## `GET /commitments/overdue`

`scopeOverdue`: `due_date < now()` y `status in (pending, in_progress)`.
Paginado; el `meta` de este endpoint **no** trae `per_page`.

⚠️ `due_date` no tiene hora, así que se compara como las 00:00 del día: **a
cualquier hora del propio día de vencimiento el compromiso ya sale como
vencido**.

## `GET /meetings/{meeting}/commitments`

Mismos filtros/includes que `index` menos `meeting`. La reunión de otro tenant
da 404.

---

## Recordatorios escalonados

`CommitmentController::scheduleCommitmentReminders` es **privado y solo se
ejecuta en `store`**. Encola hasta cuatro `SendCommitmentReminderJob`. Con
`$totalDays = now()->diffInDays($commitment->due_date)` —un **float con signo**,
porque `due_date` es medianoche y `now()` es la hora real:

| Recordatorio | Cuándo se entrega | Condición |
| --- | --- | --- |
| `assignment` | inmediato, sin `delay` | siempre (si hay teléfono) |
| `50_percent` | `now + (int)($totalDays × 0.5)` días | `$totalDays > 2` |
| `25_percent` | `now + (int)($totalDays × 0.75)` días | `$totalDays > 2` |
| `due_date` | `due_date` a las **08:00** (America/Bogota) | solo si esa hora es futura |

Ejemplo fijado por las pruebas — creado el **2026-08-07 12:00** con vencimiento
el **2026-08-27** (plazo = 19,5 días):

| Job | Entrega |
| --- | --- |
| `assignment` | inmediata |
| `50_percent` | 2026-08-16 12:00 (`(int) 9,75` = 9 días) |
| `25_percent` | 2026-08-21 12:00 (`(int) 14,625` = 14 días) |
| `due_date` | 2026-08-27 08:00 |

### Casos borde

| Caso | Qué pasa |
| --- | --- |
| Asignado sin `phone` (o sin asignado) | **No se encola nada** y `whatsapp_notification_sent: false` |
| Plazo ≤ 2 días | Sin intermedios: solo `assignment` y `due_date` |
| `due_date` pasada | Solo `assignment` |
| `due_date` hoy, creado después de las 08:00 | Solo `assignment` (las 08:00 ya pasaron) |

⚠️ Los intermedios se miden **desde hoy** y se truncan a días enteros, así que
siempre se adelantan respecto del 50%/75% real del plazo.

⚠️ Con plazos cortos los dos intermedios **coinciden**: con 2,5 días de plazo,
`(int) 1,25` y `(int) 1,875` son ambos 1 día, y la persona recibe los dos WhatsApp
—uno de ellos «solo queda el 25% del tiempo»— en el mismo instante.

⚠️ `complete` **no cancela** los recordatorios encolados. Quien evita el mensaje
es el propio job, que vuelve a mirar el estado al ejecutarse.

### `SendCommitmentReminderJob` en ejecución

1. Recarga el compromiso; si está `completed`, no hace nada.
2. Si el asignado no existe o se quedó sin `phone`, no hace nada.
3. Construye el mensaje según el tipo (cuatro plantillas distintas, en español,
   con descripción, fecha límite y días restantes).
4. Envía por Evolution API vía `WhatsAppNotificationService`, que elige instancia
   activa del tenant con cupo (round-robin) y **normaliza el número**: 10 dígitos
   colombianos → prefijo `57`.
5. Si el envío fue bien, descuenta 1 crédito de WhatsApp del tenant.

Sin instancia de WhatsApp para el tenant no sale nada y el job no revienta.

⚠️ El crédito solo se descuenta **si el tenant ya tiene fila en
`tenant_messaging_credits`**. Sin ella el mensaje sale igual y no se cobra: el
saldo es un contador posterior, no una autorización previa.

---

## Prioridades

`GET /priorities` devuelve el catálogo entero ordenado por `order`, **sin
paginar y sin `meta`**, y no exige permiso alguno. La escritura pide `role:admin`.

| Campo | Regla (store) |
| --- | --- |
| `name` | requerido, máx 255, **único en toda la tabla** |
| `description` | opcional |
| `color` | requerido, exactamente 7 caracteres, `#RRGGBB` |
| `order` | requerido, entero ≥ 0 |

En `update` todo es `sometimes` y la unicidad ignora el propio registro.
`destroy` responde **422** («Cannot delete priority with associated
commitments») si la prioridad tiene compromisos; si no, borra de verdad.

`PriorityResource` (el que va dentro de un compromiso) publica `id`, `name`,
`color`, `order` y timestamps — **no** `description`.

⚠️ El catálogo es **global**: no tiene `tenant_id`. Lo que una campaña crea,
renombra o borra lo ven y lo sufren todas, y la unicidad de `name` impide que dos
campañas tengan cada una su «Alta». Misma familia que `voter-types` (0011).

⚠️ La guarda de `destroy` cuenta `$priority->commitments()`, y `Commitment` sí
lleva `TenantScope`: **los compromisos de las demás campañas no se cuentan**. Un
admin borra una prioridad que «no tiene compromisos» y, por el
`onDelete('set null')` de la FK, deja a otra campaña con sus compromisos sin
prioridad.

⚠️ Un compromiso en la papelera tampoco protege a su prioridad (la relación
excluye lo borrado en blando): al restaurarlo vuelve sin prioridad.
