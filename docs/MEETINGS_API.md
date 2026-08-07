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

## Flujo de asistencia

`.specify/context/domain-meetings-attendance.md` describe para qué existe el
módulo. Respuesta a las cinco preguntas del addendum de la Spec 0010, verificada
en `tests/Feature/Meetings/MeetingAttendanceDomainTest.php` (17 pruebas).

| # | Pregunta | Respuesta |
| --- | --- | --- |
| 1 | ¿El check-in busca por documento y autocompleta? | **PARCIAL** |
| 2 | ¿Se deduplica por cédula? | **NO** |
| 3 | ¿Campos dinámicos configurables? | **PARCIAL** |
| 4 | ¿Métrica de nuevos vs recurrentes? | **NO** |
| 5 | ¿Reunión → compromisos? | **SÍ** |

### 1. Búsqueda por documento — PARCIAL

**Sí existe, pero solo en el cliente y contra la fuente equivocada.**

Dónde está cableado: `platform-politics-frontend/src/pages/MeetingCheckIn.tsx`
(`handleVerifyDocument`, se dispara con ≥6 dígitos de cédula) llama a
`meetingCheckInService.verifyDocument()` →
`GET /api/v1/verify-document?cedula=` (ruta **pública**) y rellena `nombres`,
`apellidos`, `telefono` y `email` del formulario.

`VoterController@verifyDocument` consulta, en este orden:

1. **PISAMI** — API externa, URL hard-codeada en `PisamiService`
   (`pisami.ibague.gov.co`, alcaldía de Ibagué).
2. **Tabla `leads`** — por cédula.

Lo que **no** hace:

- **No mira `voters`** ni `meeting_attendees`. Alguien que ya es votante del
  tenant, o que ya asistió a otra reunión, **no se autocompleta**.
- **El backend no hace ningún lookup.** `MeetingController@checkIn` guarda
  literalmente lo que llega; quien llame a la API directamente no recibe
  enriquecimiento. Todo el autocompletado es del frontend.
- **El check-in no crea ni actualiza el votante.** Los webhooks de Registraduría
  alimentan `voters`, pero el check-in no los toca: las dos bases de personas
  quedan desconectadas.

`GET /voters/search/by-cedula` **sí** busca en `voters`, pero exige
`view_voters` y responde 401 sin sesión: el formulario público no puede usarlo.
Por eso el check-in acabó usando `verify-document`.

⚠️ `verify-document` es público y busca en `leads` **sin filtro de tenant**:
cualquiera, sabiendo solo una cédula, obtiene nombre, teléfono, correo, dirección
y puesto de votación de un lead de otro tenant.

### 2. Deduplicación por cédula — NO

Un segundo check-in del mismo documento **crea una fila nueva**, no actualiza.
Verificado: dos check-ins con distinto teléfono dejan dos filas con su valor
respectivo, y `attendees_count` sube a 2.

Tampoco hay identidad entre reuniones: `meeting_attendees` no tiene ninguna
columna que ligue las filas a la misma persona (`person_id`, `voter_id`,
`lead_id` no existen). La tabla modela **el evento de check-in**, no a quien
asiste.

### 3. Campos dinámicos — PARCIAL

**Dónde se configuran**: `meeting_templates.fields` (columna JSON, casteada a
array), a nivel de **tenant**. La reunión los usa vía `template_id`. No hay
configuración por reunión ni en los ajustes del tenant.

**Dónde se exponen**: `GET /meetings/public/{qr}` los devuelve en
`data.template.fields`; el frontend los pinta (`renderTemplateField` soporta
`radio`, `checkbox`, texto…). Si la reunión no tiene plantilla, `data.template`
es `null`.

**Dónde se guardan**: en `meeting_attendees.extra_fields` (JSON). No hay tabla de
respuestas.

⚠️ **No se validan en el backend.** `CheckInRequest` declara
`extra_fields => nullable|array` y nada más: se acepta un campo que la plantilla
no declara y se acepta omitir uno marcado `required`. La obligatoriedad solo la
aplica el formulario del frontend, así que se salta llamando a la API.

### 4. Nuevos vs recurrentes — NO

Buscado en los cuatro sitios y no existe:

| Endpoint | Qué da | Sirve |
| --- | --- | --- |
| `GET /meetings/public/{qr}` | `attendees_count`, `checked_in_count` | ❌ cuenta filas |
| `GET /meetings/{m}/attendees` | `total_count`, `checked_in_count` | ❌ ninguna marca por asistente |
| `GET /reports/meetings` | `total_attendees`, `checked_in_attendees` | ❌ sin `distinct` por cédula |
| `GET /dashboard` | `attendees`, `attendees_by_month`, `top_meetings_by_attendees` | ❌ cuenta filas |
| `GET /geographic-stats` | `attendees_count` por reunión | ❌ cuenta filas |
| `GET /attendee-hierarchies/stats` | `unique_attendees` | ❌ cuenta cédulas de **relaciones de jerarquía**, no de asistencia: con 3 check-ins y sin jerarquías da 0 |

El dato **está en la base** —basta agrupar `meeting_attendees` por cédula— pero
ningún endpoint lo expone. La prueba
`test_el_dato_para_calcular_nuevos_vs_recurrentes_esta_en_la_base_pero_nadie_lo_expone`
deja la consulta escrita como evidencia.

### 5. Reunión → compromisos — SÍ

`GET /api/v1/meetings/{meeting}/commitments`, permiso `view_commitments`
(no `view_meetings`). Sirve `CommitmentController@byMeeting`: filtra por
`meeting_id`, pagina (`per_page`, 15) y devuelve `{data, meta}` con la misma
forma que el resto de listados de compromisos.

- `allowedFilters`: `status`, `assigned_user_id`, `priority_id`
- `allowedIncludes`: `assignedUser`, `priority`, `createdBy`
- carga por defecto `assignedUser` y `priority`; el objeto `meeting` **no** viene
  (sí `meeting_id`)
- una reunión de otro tenant da 404

Los cuatro huecos están registrados como hallazgos en `known-issues.md`.
