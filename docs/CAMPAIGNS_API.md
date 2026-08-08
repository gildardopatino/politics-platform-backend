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
| `tests/Feature/Campaigns/CampaignStatusEnumTest.php` (4) | que los estados del código caben en la columna (Spec 0038) |
| `tests/Feature/Campaigns/CampaignCancelTest.php` (7) | cancelar (Spec 0038) |
| `tests/Feature/Campaigns/CampaignSingleDispatchTest.php` (8) | despacho único y `destroy` protegido (Spec 0038) |
| `tests/Feature/Campaigns/CampaignDraftAndBillingTest.php` (16) | borrador → envío y cobro todo-o-nada (Spec 0040) |
| `tests/Feature/Campaigns/CampaignScheduledSendTest.php` (5) | programadas y bordes del cobro (Spec 0040) |

---

## Rutas

Todas dentro de `['jwt.auth', 'tenant', 'tenant.active']`. Sin token → 401, sin
permiso → 403, campaña de otro tenant → 404.

| Endpoint | Permiso | Acción |
| --- | --- | --- |
| `GET /campaigns` | `view_campaigns` | `index` |
| `POST /campaigns` | `create_campaigns` | `store` — **guarda un borrador** |
| `GET /campaigns/{campaign}` | `view_campaigns` | `show` |
| `PUT\|PATCH /campaigns/{campaign}` | `edit_campaigns` | `update` (solo `draft`) |
| `DELETE /campaigns/{campaign}` | `delete_campaigns` | `destroy` (soft delete) |
| `POST /campaigns/{campaign}/send` | `edit_campaigns` | `send` — **resuelve, cobra y despacha** |
| `POST /campaigns/{campaign}/cancel` | `edit_campaigns` | `cancel` |
| `GET /campaigns/{campaign}/recipients` | `view_campaigns` | `recipients` |

**El ciclo es borrador → envío** (Spec 0040). Crear una campaña ya no la envía:
se guarda, se revisa, se edita, y sale cuando alguien pulsa enviar. Ese mismo
momento es el que **resuelve los destinatarios** y **cobra los créditos**.

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
| `scheduled_at`, `queued_at`, `sent_at` | timestamp nullable |
| `status` | `draft`, `pending`, `scheduled`, `sending`, `sent`, `failed`, `cancelled` |
| `total_recipients`, `sent_count`, `failed_count` | integer default 0 |

**Vocabulario de estados** (Spec 0038). Quién escribe qué:

| Estado | Lo escribe | Lo comprueba |
| --- | --- | --- |
| `draft` | `createCampaign` (siempre) | `update` y `send` (solo se edita y se envía un borrador) |
| `pending` | `send`, sin fecha o con la fecha ya pasada | el job |
| `scheduled` | `send`, con fecha futura | el job |
| `sending` | el job al empezar | `destroy` (no deja borrar) |
| `sent` | el job al terminar sin pendientes | `cancel` (no deja cancelar) |
| `failed` | `SendCampaignJob::failed()` | — |
| `cancelled` | `cancel` | el job (no arranca) |

Hasta la 0038 el vocabulario no cuadraba con la columna y era la raíz de varios
bugs: `cancel` escribía `cancelled`, que **no estaba en el enum** (500 siempre);
y las guardas de `destroy` y `cancel` preguntaban por `in_progress` y
`completed`, estados que **nadie escribe nunca**, así que no protegían nada.
`in_progress` y `completed` siguen sin existir a propósito: lo que se corrigió
fueron las guardas, no el enum.

`queued_at` marca que ya hay un job despachado para esa campaña.

`campaign_recipients` — sin `tenant_id` y **sin `HasTenant`**: cuelga de la
campaña. `recipient_type` (`email`/`whatsapp`), `recipient_value`,
`recipient_name`, `status` (`pending`,`sent`,`failed`,`bounced`,`cancelled`),
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

Responde `201` con `message: "Campaign saved as draft"`, `status: "draft"` y
`total_recipients: 0`. **No sale nada y no se cobra nada**: los destinatarios se
resuelven al enviar. `scheduled_at` se guarda para usarla entonces.

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

Solo un **borrador** se edita; si no, **422** «Cannot update campaign that is not
a draft». Lo que ya está en camino, enviado o cancelado no se toca.

Editar el borrador **sí cambia lo que se envía**: los destinatarios se resuelven
en el momento del envío, así que cambiar `channel` o `filter_json` se refleja.
Antes se resolvían al crear y editarlos no servía de nada; y una campaña
programada —lo único que aún no había salido— era justo lo que no se podía
corregir.

## `DELETE /campaigns/{campaign}`

Borrado en blando. Una campaña **en curso** (`sending`) no se puede borrar: 422
«Cannot delete campaign in progress». Las demás sí, incluidas las canceladas y
las ya enviadas.

## `POST /campaigns/{campaign}/send`

**El disparador del envío, y donde se cobra** (Spec 0040). Exige un borrador; si
no, 422 «Campaign is not a draft».

Qué hace, en este orden:

1. **Resuelve los destinatarios** desde `filter_json` (ver más abajo). Si no sale
   nadie → 422 «Campaign has no recipients to send», sin cobrar ni despachar.
2. **Cuenta los mensajes por canal.** Con `both`, cada persona cuenta uno de
   correo y uno de WhatsApp.
3. **Valida el saldo en todo o nada.** Si a cualquiera de los dos canales le
   falta un solo crédito → **422** con el detalle (abajo), sin enviar nada, sin
   descontar nada y sin dejar destinatarios escritos: la campaña sigue en
   borrador y basta recargar y reintentar.
4. **Descuenta por canal** y registra un `MessagingCreditTransaction` de tipo
   `consumption` por cada canal usado.
5. Marca `pending` —o `scheduled` si `scheduled_at` sigue en el futuro—, sella
   `queued_at` y **despacha el job una sola vez** (con `delay` si es programada).

Responde `200` con el recurso y `message: "Campaign queued for sending"`.

Del 1 al 4 van en **una sola transacción**, con la fila de créditos bloqueada
(`lockForUpdate`): dos envíos simultáneos del mismo tenant no pueden gastar el
mismo saldo dos veces. El job se despacha **fuera** de la transacción, porque
dentro podría arrancar antes del commit y encontrarse la campaña en `draft`.

### El 422 de saldo insuficiente

Contrato estable para el panel (spec 0043 en el frontend). **Los dos canales
viajan siempre**, aunque uno no se use:

```json
{
  "message": "Insufficient messaging credits to send this campaign",
  "credits": {
    "email":    { "needed": 0,   "available": 0,  "missing": 0 },
    "whatsapp": { "needed": 3,   "available": 1,  "missing": 2 }
  }
}
```

- `needed` — mensajes que saldrían por ese canal.
- `available` — saldo del tenant en ese canal **antes** de enviar.
- `missing` — `max(0, needed - available)`. Si algún canal lo tiene `> 0`, no
  sale nada.

Un tenant **sin fila** en `tenant_messaging_credits` tiene `available: 0` en los
dos: el saldo es una autorización previa, no un contador que se rellena después.

### Despacho único

`queued_at` marca que ya hay un job para esa campaña. Como enviar exige un
borrador y el envío deja la campaña en `pending`/`scheduled`, un segundo clic
responde 422 «Campaign is not a draft». La comprobación de `queued_at` se
mantiene como cinturón y tirantes: un borrador ya encolado responde 422 «Campaign
was already queued for sending».

## `POST /campaigns/{campaign}/cancel`

Pasa la campaña a `cancelled` y marca como **cancelados** los destinatarios que
seguían `pending`. Lo que ya salió no se toca: cancelar detiene lo que falta, no
reescribe lo que pasó. Responde 200 «Campaign cancelled».

Una campaña ya enviada no se cancela: 422 «Cannot cancel a campaign that was
already sent». Las demás sí —`draft`, `pending`, `scheduled`, `sending`,
`failed`—, y cancelar dos veces es inocuo.

⚠️ **Cancelar no devuelve los créditos.** El cobro ocurre al enviar; cancelar
detiene la entrega pero lo cobrado sigue cobrado. El reembolso —y el de los
mensajes que fallan en el job— es follow-up de la Spec 0040.

**Qué detiene de verdad:** el job comprueba el estado antes de arrancar, así que
una campaña programada y cancelada ya no sale cuando llega su hora. Una que ya
está *dentro* del bucle de envío no se interrumpe a mitad: el job no vuelve a
mirar el estado entre destinatario y destinatario.

Antes de la Spec 0038 este endpoint respondía **500 siempre** (`cancelled` no
estaba en el enum) y su guarda comparaba con `completed`, un estado que nadie
escribe.

## `GET /campaigns/{campaign}/recipients`

Paginado de 50 (`?per_page=`). El `meta` de este endpoint **no** trae `per_page`.

---

## Envío

### 1. Alta — `CampaignService::createCampaign`

Genera el token del creador y guarda la campaña en `draft` con su texto, su canal
y sus filtros. **Nada más**: ni destinatarios, ni cobro, ni job.

**`send` es el disparador; el alta no envía** (Spec 0040). Antes era al revés:
crear ponía la campaña en camino sin ningún paso de revisión.

### 2. Destinatarios — `generateRecipients`

Se resuelven **al enviar**, desde los filtros que tenga el borrador en ese
momento, así que editarlo cambia lo que sale. Según `filter_json.target`
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
de `MeetingAttendee`, que no ve esas filas. Pedir la reunión de otra campaña no
resuelve a nadie, y enviar responde «Campaign has no recipients to send».

⚠️ `recipient_name` casi nunca se guarda: para asistentes el servicio lee
`$attendee->nombre` y el modelo tiene `nombres`/`apellidos`; para la lista
personalizada el `name` se pierde en la validación. Solo `all_users` guarda
nombre.

### 3. Job — `SendCampaignJob`

`tries = 3`, `timeout = 300`.

1. Si el estado no es `pending` ni `scheduled`, no hace nada — de ahí que
   cancelar una campaña programada la detenga.
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

**Un crédito por mensaje y canal, cobrado al enviar** (Spec 0040). El saldo vive
en `tenant_messaging_credits` (`emails_available` / `whatsapp_available`), son
**dos bolsas separadas**, y cada consumo deja un `MessagingCreditTransaction` de
tipo `consumption` con el precio del catálogo del momento. Detalle del saldo y de
la compra en `MESSAGING_CREDITS_API.md`.

Reglas:

- Se cobra **al despachar**, no cuando el job entrega. Una campaña programada
  reserva su saldo el día que se envía, no el día que sale.
- **Todo o nada**: si a un canal le falta un crédito, no sale ningún mensaje por
  ninguno de los dos.
- Lo cobrado deja de estar disponible de verdad: con saldo para dos mensajes, la
  primera campaña se los lleva y la siguiente recibe 422.
- El job **no cobra**: cuando corre, el crédito ya está descontado.

⚠️ **No hay reembolso**: ni al cancelar, ni por los mensajes que fallan en el
job. Es follow-up de esta spec. Hasta entonces, `whatsapp_used` cuenta mensajes
*autorizados*, no *entregados*.

Antes de la 0040 el flujo no tocaba `TenantMessagingCredit` en ningún punto: el
envío masivo salía gratis, sin registro, y con el saldo en cero.
