# Reuniones, check-in por QR y asistentes

Contrato **observado** (Spec 0010). Este documento describe lo que la API hace
hoy, verificado con pruebas de caracterización, no lo que debería hacer. Donde el
comportamiento es discutible se marca con ⚠️ y se enlaza el hallazgo en
`.specify/context/known-issues.md`.

Pruebas que lo respaldan:

| Archivo | Qué fija |
| --- | --- |
| `tests/Feature/Meetings/MeetingLifecycleTest.php` | index, store, show, update, destroy, complete, cancel, getQRCode |
| `tests/Feature/Meetings/MeetingPublicCheckInTest.php` | las tres rutas públicas de QR |
| `tests/Feature/Meetings/MeetingAttendeeTest.php` | CRUD de asistentes, search, searchAll |
| `tests/Feature/Meetings/MeetingAttendanceDomainTest.php` | el flujo contra la intención de negocio |

---

## Reuniones (autenticado)

Todas bajo `/api/v1`, en el grupo `jwt.auth` + `tenant` + `tenant.active`.
Permisos según la Spec 0005.

### `GET /meetings` — `view_meetings`

Lista paginada del tenant. `spatie/laravel-query-builder`:

- **filtros**: `filter[title]`, `filter[status]`, `filter[department_id]`,
  `filter[municipality_id]`, `filter[commune_id]`, `filter[barrio_id]`
- **includes**: `planner`, `logisticsResponsible`, `template`, `attendees`,
  `commitments`, `department`, `municipality`, `commune`, `barrio`,
  `corregimiento`, `vereda`, `resourceAllocations`
- **sorts**: `starts_at`, `created_at`, `title`, `status`
- **paginación**: `per_page` (por defecto 15)

```json
{ "data": [ { "id": 1, "title": "...", "status": "scheduled", "attendees_count": 3 } ],
  "meta": { "total": 1, "current_page": 1, "last_page": 1, "per_page": 15 } }
```

### `POST /meetings` — `create_meetings`

Obligatorios: `title`, `starts_at`, `planner_user_id`. `ends_at` debe ser
posterior a `starts_at`. `tenant_id` del payload se ignora: manda el del usuario.

Efectos: genera el QR **de forma síncrona** (escribe un SVG en
`storage/app/public/qr-codes/{slug}/`), crea el recordatorio si viene `reminder`,
y envía notificaciones de WhatsApp al planner y al responsable de logística.

```json
{ "data": { ... }, "message": "Meeting created successfully",
  "whatsapp_notification_sent": false, "logistics_notification_sent": false }
```

⚠️ **`data.status` llega `null`** aunque en la base valga `scheduled`. El estado
lo pone el DEFAULT de la columna y el controller no recarga el modelo.

### `GET /meetings/{id}` — `view_meetings`
Carga planner, logística, plantilla, asistentes, compromisos, geografía,
recordatorio activo y asignaciones de recursos.

### `PUT /meetings/{id}` — `edit_meetings`
Actualización parcial. Si viene `reminder`, cancela el activo y crea uno nuevo.

### `DELETE /meetings/{id}` — `delete_meetings`
Borrado **en blando**. Cancela antes los recordatorios pendientes o en proceso.

### `POST /meetings/{id}/complete` — `edit_meetings`
Pone `status = completed`, `ends_at = now()` y procesa las jerarquías de
asistentes.

### `POST /meetings/{id}/cancel` — `edit_meetings`
Pone `status = cancelled`.

⚠️ **Ni `complete` ni `cancel` miran el estado previo.** Se puede completar una
reunión cancelada, cancelar una completada, y repetirlo sin límite. No hay
máquina de estados.

### `GET /meetings/{id}/qr-code` — `view_meetings`

```json
{ "qr_code": "...", "qr_url": "...", "check_in_url": "...", "svg": {...}, "svg_base64": "..." }
```

Devuelve `404 {"message":"QR code not generated yet"}` si la reunión no tiene código.

⚠️ **`svg` no es un string.** `QrCode::generate()` devuelve un `HtmlString` que al
serializar a JSON sale como objeto. El campo utilizable es **`svg_base64`**.

---

## Check-in público por QR (SIN autenticación)

Estas tres rutas viven fuera del grupo `jwt.auth`. Al no pasar por `tenant`, no
hay `current_tenant_id` enlazado y `TenantScope` no filtra: la reunión se busca
por su código en toda la base. Es lo que hace falta para que un QR impreso
funcione, pero implica que **quien tenga el código accede sin sesión**.

### `GET /meetings/public/{qr_code}` — info para la pantalla de check-in

```json
{ "success": true,
  "data": { "id": 1, "titulo": null, "descripcion": null, "objetivo": null,
            "starts_at": "...", "status": "scheduled",
            "lugar_tipo": null, "lugar_nombre": "Salón comunal",
            "lugar_direccion": null, "lugar_url": null,
            "planner": { "id": 1, "name": "...", "email": "...", "phone": "..." },
            "location": { "department": "...", "municipality": "...", "commune": "...", "barrio": "..." },
            "template": { "id": 1, "nombre": "...", "descripcion": "...", "fields": [...] },
            "attendees_count": 3, "checked_in_count": 2 } }
```

⚠️ **Seis campos llegan siempre `null`**: `titulo`, `descripcion`, `objetivo`,
`lugar_tipo`, `lugar_direccion` y `lugar_url`. El controller lee nombres que no
existen en el modelo `Meeting` (los reales son `title`, `description` y
`direccion`; `objetivo`, `lugar_tipo` y `lugar_url` no existen). `docs/CHECKIN_CAMPOS_DINAMICOS.md`
documenta este endpoint **con los campos poblados**, cosa que nunca ocurre.

`template.fields` **sí** funciona: es la lista de preguntas configurables con la
que el frontend pinta el formulario.

404 si el código no existe.

### `GET /meetings/check-in/{qr_code}` — reunión completa

Devuelve el `MeetingResource` entero.

⚠️ Incluye `tenant_id` y el objeto `planner` con su email en una ruta pública.

### `POST /meetings/check-in/{qr_code}` — registrar asistencia

| Campo | Regla |
| --- | --- |
| `cedula` | requerido, string, máx 20 |
| `nombres` | requerido, string, máx 255 |
| `apellidos` | requerido, string, máx 255 |
| `barrio_id` | opcional, debe existir |
| `telefono` | opcional, máx 20 |
| `email` | opcional, formato email |
| `extra_fields` | opcional, array libre |

Crea el asistente con `checked_in = true`, `checked_in_at = now()`,
`created_by = null` (nadie autenticado) y el `tenant_id` heredado de la reunión.
Responde `201`.

⚠️ Devuelve el **modelo crudo**, no `MeetingAttendeeResource`: la forma difiere
del resto de endpoints de asistentes (aquí viaja `tenant_id`, y no viene
`full_name`).

⚠️ **No comprueba duplicados ni estado de la reunión.** La misma cédula puede
registrarse tantas veces como escanee, y se puede hacer check-in en una reunión
cancelada o ya completada.

---

## Asistentes (autenticado)

No tienen permisos propios: usan los de meetings, porque son datos de la reunión.

| Endpoint | Permiso | Notas |
| --- | --- | --- |
| `GET /meetings/{m}/attendees` | `view_meetings` | Pagina de 50. Filtro `?checked_in=true\|false`. `meta` trae `checked_in_count` y `total_count` |
| `GET /meetings/{m}/attendees/search?search=` | `view_meetings` | ⚠️ ver abajo |
| `GET /attendees/search?search=` | `view_meetings` | Busca en todas las reuniones, agrupado por cédula. ⚠️ ver abajo |
| `POST /meetings/{m}/attendees` | `create_meetings` | |
| `GET /attendees/{id}` | `view_meetings` | Incluye la reunión |
| `PUT /attendees/{id}` | `edit_meetings` | Al pasar `checked_in` de false a true sella `checked_in_at` |
| `DELETE /attendees/{id}` | `delete_meetings` | Borrado **real**: la tabla no tiene softDeletes |

Sin el parámetro `search`, ambas búsquedas responden
`400 {"data": [], "message": "Search parameter is required"}`.

⚠️ **`search` y `searchAll` solo funcionan en PostgreSQL**: filtran con el
operador `ilike`. Fuera de él responden 500.

⚠️ `POST` admite la misma cédula dos veces en la misma reunión, y su respuesta
devuelve `checked_in: null` en vez de `false` (mismo motivo que `data.status` al
crear una reunión).

⚠️ Desmarcar `checked_in` deja la marca de hora anterior: solo se sella al pasar
de false a true.

### Aislamiento

Un asistente o una reunión de otro tenant dan **404** en `show`, `update`,
`destroy` y en el listado de asistentes. Es el binding implícito con
`TenantScope`, arreglado por la Spec 0004.

---

## Frente a la intención de negocio

`.specify/context/domain-meetings-attendance.md` describe para qué existe el
módulo. Estado real, verificado en `MeetingAttendanceDomainTest`:

| Lo previsto | Hoy |
| --- | --- |
| Registro por QR con formulario público | ✅ funciona |
| Formulario configurable (preguntas extra) | 🟡 a medias: la plantilla define `fields` y `getPublicInfo` los expone, pero `checkIn` **no valida** `extra_fields` contra ellos (ni exige los requeridos ni rechaza los no declarados) |
| Búsqueda por documento que autocomplete | ❌ **no está cableada**. `checkIn` guarda literalmente lo que llega; no consulta `voters` ni el recurso en línea. El lookup existe (`GET /voters/search/by-cedula`) pero es privado: exige `view_voters` y responde 401 sin sesión, así que el formulario público no puede usarlo |
| Asistencia deduplicada por documento | ❌ no hay deduplicación, ni dentro de una reunión ni entre reuniones. `meeting_attendees` no tiene identidad por persona, solo filas por check-in |
| Métrica de nuevos vs recurrentes | ❌ no existe en ningún endpoint. Los contadores cuentan filas, no personas |
| Enlace reunión ↔ compromisos | ✅ `GET /meetings/{m}/commitments` (permiso `view_commitments`) |

Los tres ❌ y el 🟡 están registrados como hallazgos en `known-issues.md`.
