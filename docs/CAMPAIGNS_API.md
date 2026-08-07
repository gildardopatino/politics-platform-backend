# Campañas de mensajería y envío asíncrono

Contrato **observado** del módulo de campañas (Spec 0013, caracterización). Una
campaña es un envío masivo por WhatsApp y/o correo a destinatarios que se
resuelven en el momento de crearla, y que se procesa en segundo plano.

> **Esto es caracterización.** Documenta lo que el código hace hoy, no lo que
> debería hacer. Lo que está mal se marca ⚠️ y queda en
> `.specify/context/known-issues.md`; corregirlo es otra spec.

Guías de uso preexistentes: `CAMPAIGNS_QUICK_GUIDE.md`,
`CAMPAIGNS_API_EXAMPLES.md`, `CAMPAIGNS_LOCATION_FILTER.md`. Este documento es el
**contrato**, y manda sobre ellas cuando discrepen.

Pruebas que lo sostienen:

| Archivo | Qué fija |
| --- | --- |
| `tests/Feature/Campaigns/CampaignCharacterizationTest.php` (24) | CRUD, acciones, permisos, aislamiento |
| `tests/Feature/Campaigns/CampaignSendCharacterizationTest.php` (24) | encolado, destinatarios, job en ejecución, créditos |
| `tests/Feature/Campaigns/CampaignSendCompletenessTest.php` (6) | que el envío alcanza a **todos** los destinatarios (Spec 0037) |
| `tests/Feature/Campaigns/CampaignCreatorTokenTest.php` (7) | que `creator_token` no sale del servidor (Spec 0039) |

---

## Rutas

Todas dentro de `['jwt.auth', 'tenant', 'tenant.active']`. Sin token → 401, sin
permiso → 403, campaña de otro tenant → 404.

| Endpoint | Permiso | Acción |
| --- | --- | --- |
| `GET /campaigns` | `view_campaigns` | `index` |
| `POST /campaigns` | `create_campaigns` | `store` — **crea y encola el envío** |
| `GET /campaigns/{campaign}` | `view_campaigns` | `show` |
| `PUT\|PATCH /campaigns/{campaign}` | `edit_campaigns` | `update` (solo `pending`) |
| `DELETE /campaigns/{campaign}` | `delete_campaigns` | `destroy` (soft delete) |
| `POST /campaigns/{campaign}/send` | `edit_campaigns` | `send` (solo `pending`) |
| `POST /campaigns/{campaign}/cancel` | `edit_campaigns` | `cancel` — ⚠️ **roto** |
| `GET /campaigns/{campaign}/recipients` | `view_campaigns` | `recipients` |

## Modelo y vocabulario de estados

`campaigns` — `HasTenant`, `SoftDeletes`, auditable y con activity log
(`title`, `channel`, `status`, `sent_count`).

| Columna | Tipo |
| --- | --- |
| `tenant_id`, `created_by` | FK NOT NULL |
| `creator_token` | text — JWT del creador. `$hidden` + `$auditExclude` |
| `title`, `message` | string / text |
| `channel` | enum(`whatsapp`,`email`,`both`), default `email` |
| `filter_json` | json nullable, cast `array` |
| `scheduled_at`, `sent_at` | timestamp nullable |
| `status` | `draft`, `pending`, `scheduled`, `sending`, `sent`, `failed` |
| `total_recipients`, `sent_count`, `failed_count` | integer default 0 |

**El vocabulario de estados no cuadra y es la raíz de varios bugs.** Quién
escribe qué:

| Estado | Lo escribe | Lo comprueba |
| --- | --- | --- |
| `pending` | `createCampaign` sin fecha | `update`, `send`, el job |
| `scheduled` | `createCampaign` con fecha | el job |
| `sending` | el job al empezar | *nadie* |
| `sent` | el job al terminar | — |
| `failed` | `SendCampaignJob::failed()` | — |
| `draft` | *nadie* (es el DEFAULT de la columna) | — |
| `in_progress` | *nadie* | `destroy` ⚠️ |
| `completed` | *nadie* | `cancel` ⚠️ |
| `cancelled` | `cancel` ⚠️ (**no está en el enum**) | — |

`campaign_recipients` — sin `tenant_id` y **sin `HasTenant`**: cuelga de la
campaña. `recipient_type` (`email`/`whatsapp`), `recipient_value`,
`recipient_name`, `status` (`pending`,`sent`,`failed`,`bounced`),
`error_message`, `sent_at`, `metadata`.

---

## `GET /campaigns`

`spatie/laravel-query-builder`, paginado de 15 (`?per_page=`).

- **Filtros**: `filter[status]`, `filter[channel]` y ⚠️ `filter[titulo]`.
- **Orden**: `sort=created_at|scheduled_at` (y `-` descendente), más ⚠️ `titulo`.
- **Includes**: `?include=createdBy` (sale como `creator`) y `?include=recipients`.

⚠️ `titulo` **no es una columna**: se llama `title`. `filter[titulo]` responde
**500**. `sort=titulo` no ordena; en SQLite pasa silenciosamente (el motor toma
el nombre entrecomillado como constante de texto) y en PostgreSQL sería el mismo
error de columna inexistente que el filtro.

## `POST /campaigns`

| Campo | Regla |
| --- | --- |
| `title` | requerido, máx 255 |
| `message` | requerido |
| `channel` | requerido, `in:whatsapp,email,both` |
| `filter_json` | opcional, array |
| `filter_json.target` | `all_users`, `meeting_attendees`, `custom_list`, `by_location` |
| `filter_json.meeting_ids.*` | `exists:meetings,id` |
| `filter_json.custom_recipients.*.type` | `in:email,phone` |
| `filter_json.custom_recipients.*.value` | requerido con la lista |
| `filter_json.department_id\|municipality_id\|commune_id\|barrio_id` | `exists:` |
| `scheduled_at` | opcional, fecha **futura** (mensaje en español) |

Responde `201` con `message: "Campaign created and queued for sending"`, el
recurso con `total_recipients` ya calculado y `status` `pending` (o `scheduled`
si venía fecha). **El envío queda encolado en ese mismo momento.**

El alta genera un JWT del creador y lo guarda en `campaigns.creator_token` para
que el webhook de correo lo reutilice como `Bearer`. **No sale del servidor**
(Spec 0039): `Campaign` lo lleva en `$hidden` —así que no aparece en ninguna
serialización del modelo, pase o no por `CampaignResource`— y en `$auditExclude`,
así que tampoco entra en el registro de auditoría. El código lo sigue leyendo por
atributo, que es lo único que necesita `CampaignService::sendToRecipient`.

⚠️ Ese token **no dura un año**, aunque el código lo pretenda: `CampaignService`
sube `config('jwt.ttl')` a 525.600 minutos, genera el token y restaura el valor,
pero la factoría de JWT ya está resuelta con el TTL de la configuración, así que
se guarda un token normal (120 min por defecto). Rebaja el riesgo de la
credencial, pero rompe su propósito: **una campaña programada a más de dos horas
llegará al webhook con un token caducado** y sus correos fallarán la
autenticación. Sustituir el mecanismo es follow-up de la 0039.

⚠️ `filter_json.custom_recipients.*.name` **no tiene regla**, así que
`validated()` lo descarta y el nombre nunca llega al servicio.

## `GET /campaigns/{campaign}`

Carga `createdBy` y `recipients`. Campaña de otro tenant → 404.

## `PUT|PATCH /campaigns/{campaign}`

Solo si `status === 'pending'`; si no, **422** «Cannot update campaign that is
not pending».

⚠️ Una campaña `scheduled` —lo único que aún no se ha enviado— **no se puede
editar**: hay que borrarla y rehacerla.

⚠️ Editar **no regenera los destinatarios**. Cambiar `channel` o `filter_json`
deja la lista vieja y `total_recipients` desactualizado, sin aviso.

## `DELETE /campaigns/{campaign}`

Borrado en blando. ⚠️ La guarda compara con `in_progress`, que nadie escribe: una
campaña realmente en curso (`sending`) **sí se puede borrar**.

## `POST /campaigns/{campaign}/send`

Exige `pending`; si no, 422 «Campaign is not in pending status». Encola
`SendCampaignJob` y responde 200 «Campaign queued for sending».

⚠️ El alta ya dejó la campaña en `pending` **y ya encoló su envío**, así que
pulsar «enviar» encola un **segundo** job: si el primero no ha corrido todavía,
ambos ven destinatarios `pending` y **la gente recibe el mensaje dos veces**.

## `POST /campaigns/{campaign}/cancel`

⚠️ **Está roto.** Escribe `status = 'cancelled'`, valor que el CHECK de la
columna no admite ni en PostgreSQL ni fuera: responde **500** y la campaña no se
cancela. Y la guarda previa compara con `completed`, que tampoco existe, así que
ni siquiera una campaña ya enviada se detiene antes del error. **No hay forma de
cancelar una campaña.**

## `GET /campaigns/{campaign}/recipients`

Paginado de 50 (`?per_page=`). El `meta` de este endpoint **no** trae `per_page`.

---

## Envío

### 1. Alta — `CampaignService::createCampaign`

En transacción: genera el token de un año, crea la campaña (`scheduled` si hay
fecha, `pending` si no), resuelve los destinatarios, guarda `total_recipients` y
despacha `SendCampaignJob` —inmediato, o con `delay` en la hora de Bogotá si
estaba programada—.

### 2. Destinatarios — `generateRecipients`

Se resuelven **una sola vez, al crear**. Según `filter_json.target`
(por defecto `all_users`):

| Objetivo | De dónde salen |
| --- | --- |
| `all_users` | `users` del tenant de la campaña |
| `meeting_attendees` | `meeting_attendees` de `filter_json.meeting_ids` |
| `custom_list` | `filter_json.custom_recipients[]` (`type` `email`/`phone`) |
| `by_location` | asistentes filtrados por geografía |

El **canal decide el dato**: `email` toma el correo, `whatsapp` el teléfono, y
`both` genera **dos** destinatarios por persona. Quien no tenga el dato del canal
no entra. Al final se deduplica por `tipo:valor` y se inserta en bloque.

`by_location` toma **el nivel más específico** que venga, en este orden:
`barrio_id` → `commune_id` → `municipality_id` → `department_id`. El de municipio
cubre tanto los barrios colgados directamente del municipio como los que cuelgan
de sus comunas.

**Aislamiento:** `meeting_ids` se valida con `exists:meetings,id` sin filtro de
tenant, así que un id ajeno pasa la validación; quien protege es el `TenantScope`
de `MeetingAttendee`, que no ve esas filas. Pedir la reunión de otra campaña da
`total_recipients: 0`.

⚠️ `recipient_name` casi nunca se guarda: para asistentes el servicio lee
`$attendee->nombre` y el modelo tiene `nombres`/`apellidos`; para la lista
personalizada el `name` se pierde en la validación. Solo `all_users` guarda
nombre.

### 3. Job — `SendCampaignJob`

`tries = 3`, `timeout = 300`.

1. Si el estado no es `pending` ni `scheduled`, no hace nada.
2. Marca `sending` y sella `sent_at`.
3. Recorre los destinatarios `pending` con **`chunkById`** en lotes de
   `config('campaign.batch_size')` (100 por defecto), envía uno a uno,
   actualiza `sent_count`/`failed_count` **tras cada destinatario**, y hace
   `sleep(1)` entre lotes (rate limiting).
4. Marca `sent` **solo si no queda ningún `pending`**. Si quedara alguno, la
   campaña se queda en `sending` para que se note que el recorrido no terminó.

**Por qué `chunkById` y no `chunk`** (Spec 0037, hallazgo 🔴 de la 0013): el
bucle saca cada fila del conjunto que está recorriendo —enviar la deja fuera de
`pending`—, y `chunk` pagina con **OFFSET**, así que a partir del segundo lote el
desplazamiento se comía justo las filas que faltaban. Con lotes de 1 y tres
destinatarios se enviaban dos, el tercero quedaba `pending` para siempre y la
campaña se cerraba como `sent` igual. Con el tamaño por defecto solo aparecía a
partir de **101 destinatarios** — el tamaño en el que el envío masivo tiene
sentido. `chunkById` avanza por `id > último visto`, que no depende de cuántas
filas siguen cumpliendo el filtro.

Consecuencia útil: **reejecutar el job no reenvía**. Quien ya está `sent` o
`failed` no vuelve a entrar en la consulta.

> Al escribir bucles que **modifican lo que recorren**, `chunkById` (o releer
> desde el principio) en vez de `chunk`. Es la misma trampa que en cualquier
> paginación por OFFSET sobre un conjunto que se consume.

### 4. Entrega — `sendToRecipient`

- **WhatsApp**: `WhatsAppNotificationService` → instancia Evolution activa del
  tenant, con el número normalizado.
- **Correo**: `EmailNotificationService` → webhook de n8n, autenticado con
  `Bearer {campaign.creator_token}` (leído por atributo; el token está oculto en
  toda serialización, no en el modelo). Sin token guardado, el destinatario falla
  con `No authentication token available` — y con el token caducado, ver el aviso
  del TTL más arriba.

Éxito → `status = sent` + `sent_at`. Fallo → `status = failed` +
`error_message` (`WhatsApp service returned false`, `Email service returned
false`…).

### 5. Créditos

⚠️ **Las campañas no consumen créditos de mensajería.** El flujo no toca
`TenantMessagingCredit` en ningún punto: ni comprueba saldo antes ni descuenta
después, ni deja transacción. No es el problema de los recordatorios de
compromisos —que sí cobran, pero solo si la fila ya existía—: aquí el envío
masivo, que es el que de verdad consume, **sale gratis y sin registro**. Una
campaña se envía igual con el saldo en cero.
