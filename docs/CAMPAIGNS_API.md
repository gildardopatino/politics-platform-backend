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

Esta primera versión se escribió **leyendo el código** (Fase 0). Las fases 1–2 la
fijan con pruebas y la fase 3 la corrige con lo que demuestren.

---

## Rutas

Todas dentro de `['jwt.auth', 'tenant', 'tenant.active']`.

| Endpoint | Permiso | Acción |
| --- | --- | --- |
| `GET /campaigns` | `view_campaigns` | `index` |
| `POST /campaigns` | `create_campaigns` | `store` — **crea y encola el envío** |
| `GET /campaigns/{campaign}` | `view_campaigns` | `show` |
| `PUT\|PATCH /campaigns/{campaign}` | `edit_campaigns` | `update` (solo si `pending`) |
| `DELETE /campaigns/{campaign}` | `delete_campaigns` | `destroy` (soft delete) |
| `POST /campaigns/{campaign}/send` | `edit_campaigns` | `send` (solo si `pending`) |
| `POST /campaigns/{campaign}/cancel` | `edit_campaigns` | `cancel` |
| `GET /campaigns/{campaign}/recipients` | `view_campaigns` | `recipients` |

## Modelo

`campaigns` — `HasTenant`, `SoftDeletes`, auditable y con activity log
(`title`, `channel`, `status`, `sent_count`).

| Columna | Tipo |
| --- | --- |
| `tenant_id`, `created_by` | FK NOT NULL |
| `creator_token` | text — JWT del creador con **un año** de vigencia |
| `title`, `message` | string / text |
| `channel` | enum(`whatsapp`,`email`,`both`), default `email` |
| `filter_json` | json nullable, cast `array` |
| `scheduled_at`, `sent_at` | timestamp nullable |
| `status` | enum(`draft`,`pending`,`scheduled`,`sending`,`sent`,`failed`) |
| `total_recipients`, `sent_count`, `failed_count` | integer default 0 |

`campaign_recipients` — sin `tenant_id` y **sin `HasTenant`**: cuelga de la
campaña. `recipient_type` (`email`/`whatsapp`), `recipient_value`,
`recipient_name`, `status` enum(`pending`,`sent`,`failed`,`bounced`),
`error_message`, `sent_at`, `metadata`.

## Flujo de envío (leído)

1. `CampaignService::createCampaign` — en transacción: genera un JWT de 1 año,
   crea la campaña con `status = scheduled` si viene `scheduled_at` o `pending`
   si no, resuelve los destinatarios, guarda `total_recipients` y **despacha
   `SendCampaignJob`** (con `delay` si estaba programada).
2. `generateRecipients` según `filter_json.target`: `all_users` (por defecto),
   `meeting_attendees`, `custom_list` o `by_location`. Deduplica por
   `tipo:valor` e inserta en bloque.
3. `SendCampaignJob::handle` — solo procesa `pending`/`scheduled`; marca
   `sending`, recorre los destinatarios `pending` en trozos de
   `config('campaign.batch_size')` (100), envía uno a uno con
   `sleep(1)` entre trozos, y al final marca `sent`.
4. `sendToRecipient` — correo por el webhook de n8n usando `creator_token`,
   WhatsApp por Evolution API. Marca el destinatario `sent` o `failed` con su
   `error_message`.

---

*Los payloads, los filtros, los créditos, los casos borde y los hallazgos los
completa la fase 3 con la evidencia de las pruebas.*
