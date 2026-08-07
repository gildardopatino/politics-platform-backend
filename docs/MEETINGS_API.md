# Reuniones, check-in por QR y asistentes

Contrato **observado** (Spec 0010), con los arreglos de la **Spec 0021**
incorporados. Describe lo que la API hace hoy, verificado con pruebas, no lo que
debería hacer. Lo que sigue siendo discutible se marca con ⚠️ y está en
`.specify/context/known-issues.md`.

Corregido por la 0021: el recurso público ya no devuelve nulls ni PII (F3, F6),
las búsquedas de asistentes son portables (F4), las respuestas de creación traen
los campos con DEFAULT poblados (F5) y el SVG del QR viaja como string (F8).
La **Spec 0022** añadió la identidad de persona: el check-in liga al Votante por
cédula (`meeting_attendees.voter_id`), deduplica, autocompleta desde el servidor
y mide nuevos vs recurrentes.

Siguen abiertas, por alcance de otras specs: la validación de campos dinámicos
(0023) y la máquina de estados de la reunión.

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

`data.status` llega poblado (`scheduled`): el controller recarga el modelo antes
de serializar, porque ese valor lo pone el DEFAULT de la columna (corregido por la
Spec 0021, F5).

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
{ "qr_code": "...", "qr_url": "...", "check_in_url": "...", "svg": "<svg …>", "svg_base64": "..." }
```

Devuelve `404 {"message":"QR code not generated yet"}` si la reunión no tiene código.

`svg` es un string con el SVG y `svg_base64` el mismo contenido en base64
(corregido por la Spec 0021, F8: antes `svg` salía como objeto porque
`QrCode::generate()` devuelve un `HtmlString`).

---

## Check-in público por QR (SIN autenticación)

Estas tres rutas viven fuera del grupo `jwt.auth`. Al no pasar por `tenant`, no
hay `current_tenant_id` enlazado y `TenantScope` no filtra: la reunión se busca
por su código en toda la base. Es lo que hace falta para que un QR impreso
funcione, pero implica que **quien tenga el código accede sin sesión**.

### `GET /meetings/public/{qr_code}` — info para la pantalla de check-in

Las **dos** rutas GET sirven el mismo `PublicMeetingResource` (Spec 0021, F3+F6):

```json
{ "success": true,
  "data": { "id": 1,
            "titulo": "Asamblea barrial",
            "descripcion": "Encuentro con la comunidad",
            "starts_at": "2026-08-20T18:00:00-05:00", "ends_at": null,
            "status": "scheduled",
            "lugar_nombre": "Salón comunal", "lugar_direccion": "Calle 50 #45-30",
            "planner": { "name": "..." },
            "location": { "department": "...", "municipality": "...", "commune": "...", "barrio": "..." },
            "template": { "id": 1, "nombre": "...", "descripcion": "...", "fields": [...] },
            "attendees_count": 3, "checked_in_count": 2 } }
```

Las claves van **en español** porque son las que pinta `MeetingCheckIn.tsx`;
detrás salen de `title`, `description` y `direccion`.

**Qué NO incluye, por ser rutas sin autenticación**: `tenant_id`, `metadata`,
`qr_code`, ids de usuario, ni el correo o el teléfono de quien organiza. Del
planner solo el nombre, que es lo que identifica el encuentro ante quien va a
asistir. Los campos `objetivo`, `lugar_tipo` y `lugar_url` se retiraron: no
existen en el modelo y solo producían nulls.

`template.fields` es la lista de preguntas configurables con la que el frontend
pinta el formulario.

404 si el código no existe.

### `GET /meetings/check-in/{qr_code}` — misma vista pública

Idéntico payload que `getPublicInfo`, sin el envoltorio `success`. Antes devolvía
el `MeetingResource` entero, con `tenant_id` y el email del planner.

### `POST /meetings/check-in/{qr_code}` — registrar asistencia

| Campo | Regla |
| --- | --- |
| `cedula` | requerido, string, máx 20 |
| `nombres` | requerido, string, máx 255 |
| `apellidos` | requerido, string, máx 255 |
| `barrio_id` | opcional, debe existir |
| `telefono` | opcional, máx 20 |
| `email` | opcional, formato email |
| `extra_fields` | opcional, array **validado contra la plantilla** (Spec 0023) |

Registra a una **persona**, no un formulario (Spec 0022). El QR resuelve la
reunión y con ella el tenant; el resto lo hace `App\Services\AttendanceService`:

1. **Normaliza la cédula** — sin puntos, espacios ni guiones. `71.000.001` y
   `71000001` son la misma persona, y así se guarda.
2. **Busca o crea el Votante** dentro del tenant de la reunión. Si es alguien
   nuevo, consulta el recurso en línea (`DocumentVerificationService`) para
   completar lo que el formulario no pide; si se cae, crea con lo que haya. El
   asistente queda ligado por `voter_id`.
3. **Completa el asistente** con los datos del votante que el formulario dejó en
   blanco. Lo que la persona escribió siempre manda.
4. **Deduplica**: si esa cédula ya hizo check-in en **esta** reunión, actualiza
   la fila existente en vez de crear otra. Entre reuniones distintas son dos
   asistencias de un mismo votante.

El votante también se completa en sentido inverso, pero solo en sus huecos: un
formulario público no reescribe un dato ya curado. Cuando difieren, el votante
queda marcado con `has_multiple_records` para revisión.

Crea el asistente con `checked_in = true`, `checked_in_at = now()`,
`created_by = null` (nadie autenticado) y el `tenant_id` heredado de la reunión.
Responde `201`.

⚠️ Devuelve el **modelo crudo**, no `MeetingAttendeeResource`: la forma difiere
del resto de endpoints de asistentes (aquí viaja `tenant_id`, y no viene
`full_name`).

⚠️ **No comprueba el estado de la reunión**: se puede hacer check-in en una
cancelada o ya completada.

### `GET /meetings/public/{qr_code}/verify-document?cedula=` — autocompletado

Público y con `throttle:20,1`. El QR fija el tenant de la búsqueda. Consulta
`voters` (la base de la propia campaña) y, si no está, PISAMI y `leads`.
Devuelve solo `nombres`, `apellidos`, `telefono` y `email` — sin dirección ni
puesto de votación. Contrato completo en `VERIFY_DOCUMENT_API.md`.

---

## Asistentes (autenticado)

No tienen permisos propios: usan los de meetings, porque son datos de la reunión.

| Endpoint | Permiso | Notas |
| --- | --- | --- |
| `GET /meetings/{m}/attendees` | `view_meetings` | Pagina de 50. Filtro `?checked_in=true\|false`. `meta` trae `checked_in_count` y `total_count` |
| `GET /meetings/{m}/attendees/search?search=` | `view_meetings` | Por cédula o nombre, ignorando mayúsculas. Máx 50 |
| `GET /attendees/search?search=` | `view_meetings` | Igual, en todas las reuniones del tenant, agrupado por cédula |
| `POST /meetings/{m}/attendees` | `create_meetings` | `extra_fields` se valida contra la plantilla de la reunión, igual que el check-in (Spec 0023) |
| `GET /attendees/{id}` | `view_meetings` | Incluye la reunión |
| `PUT /attendees/{id}` | `edit_meetings` | Al pasar `checked_in` de false a true sella `checked_in_at` |
| `DELETE /attendees/{id}` | `delete_meetings` | Borrado **real**: la tabla no tiene softDeletes |

Sin el parámetro `search`, ambas búsquedas responden
`400 {"data": [], "message": "Search parameter is required"}`.

`search` y `searchAll` son portables: usan
`DatabaseExpressions::caseInsensitiveLike()` (Spec 0021, F4; antes filtraban con
`ilike` y respondían 500 fuera de PostgreSQL).

La respuesta de `POST` trae `checked_in: false` (Spec 0021, F5: antes salía
`null`, porque el valor lo pone el DEFAULT de la columna y el controller no
recargaba el modelo).

⚠️ `POST` admite la misma cédula dos veces en la misma reunión. Sigue abierto:
es parte del rediseño de identidad de persona.

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
en `tests/Feature/Meetings/MeetingAttendanceDomainTest.php` (18 pruebas).

| # | Pregunta | Respuesta |
| --- | --- | --- |
| 1 | ¿El check-in busca por documento y autocompleta? | **SÍ** (0022) |
| 2 | ¿Se deduplica por cédula? | **SÍ** (0022) |
| 3 | ¿Campos dinámicos configurables? | **PARCIAL** |
| 4 | ¿Métrica de nuevos vs recurrentes? | **SÍ** (0022) |
| 5 | ¿Reunión → compromisos? | **SÍ** |

### 1. Búsqueda por documento — SÍ (Spec 0022)

**Autocompleta en el cliente y, además, el servidor completa lo que falte.**

Dónde está cableado: `platform-politics-frontend/src/pages/MeetingCheckIn.tsx`
(`handleVerifyDocument`, se dispara con ≥6 dígitos de cédula) llama a
`meetingCheckInService.verifyDocument(qrCode, cedula)` →
`GET /api/v1/meetings/public/{qr_code}/verify-document?cedula=` (ruta pública,
`throttle:20,1`) y rellena `nombres`, `apellidos`, `telefono` y `email` del
formulario. Contrato completo en `VERIFY_DOCUMENT_API.md`.

`MeetingController@verifyDocument` resuelve la reunión por su QR, enlaza
`current_tenant_id` con el tenant dueño y delega en
`DocumentVerificationService`, que consulta en este orden:

1. **Tabla `voters`** — la base de la propia campaña. Va primero (Spec 0022)
   porque es la que alguien mantiene: su dato gana sobre cualquier registro
   externo, que puede estar desactualizado.
2. **PISAMI** — API externa, URL hard-codeada en `PisamiService`
   (`pisami.ibague.gov.co`, alcaldía de Ibagué).
3. **Tabla `leads`** — por cédula.

Y el **servidor no se queda mirando**: `AttendanceService` repite la búsqueda al
registrar el check-in, así que quien llame a la API directamente recibe el mismo
enriquecimiento que el formulario. La asistencia también **alimenta `voters`**:
quien asiste queda dado de alta como votante del tenant y ligado por `voter_id`.

Lo que **no** hace:

- **No mira `meeting_attendees`.** Una asistencia histórica que se quedó sin
  `voter_id` no autocompleta. Desde la 0022 ya no se generan casos así: todo
  check-in crea o liga su votante.

`GET /voters/search/by-cedula` busca en `voters` pero exige `view_voters` y
responde 401 sin sesión, así que el formulario público nunca pudo usarlo. La
0022 no lo abre: lo que hace es que el lookup del QR —ya acotado al tenant por
la 0026— consulte `voters` con su propia política de privacidad.

La ruta que usaba —`GET /verify-document` a secas— era pública y caía fuera del
grupo `tenant`, así que buscaba en `leads` **sin filtro**: cualquiera, sabiendo
solo una cédula, sacaba los datos de un lead de otro tenant. Corregido en la
Spec 0026 colgando la búsqueda pública del QR, que es lo que fija el tenant, y
recortando la respuesta a nombre y contacto. `/verify-document` sigue existiendo
para las pantallas internas, ya autenticada y con `view_voters`.

### 2. Deduplicación por cédula — SÍ (Spec 0022)

Un segundo check-in del mismo documento en la **misma** reunión actualiza la
fila que ya existe: gana el dato más reciente y `attendees_count` no sube.

La identidad entre reuniones la da `meeting_attendees.voter_id`, la columna que
introdujo la 0022. La tabla ya no modela solo el evento de check-in: cada fila
apunta a la persona. Es nullable porque la asistencia anterior a esa spec puede
no tener votante —el backfill ligó por cédula donde existía y dejó el resto en
`null`, sin inventar votantes.

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

**Se validan en el backend desde la Spec 0023** (`App\Rules\CamposDeLaPlantilla`),
en las dos vías de alta. Antes `extra_fields` era `nullable|array` y nada más: la
obligatoriedad la aplicaba solo el formulario del frontend y se saltaba llamando
a la API. Reglas y mensajes en `CHECKIN_CAMPOS_DINAMICOS.md`.

### 4. Nuevos vs recurrentes — SÍ (Spec 0022)

#### `GET /meetings/{meeting}/attendance-stats` — `view_meetings`

```json
{
  "data": {
    "meeting_id": 3,
    "total_check_ins": 2,
    "unique_attendees": 2,
    "new_attendees": 1,
    "recurring_attendees": 1,
    "linked_to_voter": 2
  }
}
```

**Qué es «nuevo».** Esta reunión es la **primera asistencia de esa persona en la
campaña**, ordenando por `checked_in_at` y, a igualdad, por id. No es «no estaba
en `voters`»: alguien puede llevar años en la base electoral —cargado por los
webhooks de Registraduría, por ejemplo— y pisar su primera reunión hoy, y eso es
justo el crecimiento que se quiere medir.

El orden importa: la misma persona cuenta como **nueva** en la reunión donde
estrenó y como **recurrente** en las siguientes. No basta con «asistió a otra
reunión».

La persona se identifica por **cédula normalizada**, no por `voter_id`: la
asistencia anterior a esta spec puede no tener votante ligado y aun así cuenta
como visita previa. `linked_to_voter` dice cuánta de la asistencia de esta
reunión sí quedó ligada.

Una reunión de otro tenant da 404, y la asistencia de otra campaña con la misma
cédula no vuelve recurrente a nadie.

Los demás endpoints **siguen contando filas**, no personas — conviene no leer sus
totales como si fueran gente distinta:

| Endpoint | Qué da |
| --- | --- |
| `GET /meetings/public/{qr}` | `attendees_count`, `checked_in_count` — filas |
| `GET /meetings/{m}/attendees` | `total_count`, `checked_in_count` — sin marca por asistente |
| `GET /reports/meetings` | `total_attendees` — sin `distinct` por cédula |
| `GET /dashboard` | `attendees`, `attendees_by_month` — filas |
| `GET /geographic-stats` | `attendees_count` por reunión — filas |
| `GET /attendee-hierarchies/stats` | `unique_attendees` de **relaciones de jerarquía**, no de asistencia: con 3 check-ins y sin jerarquías da 0 |

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
