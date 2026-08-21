# Inventario de la landing page (Spec 0085 · Fase 0)

Qué compone la **landing page del candidato**, de punta a punta. Se levantó para
poder apagarla con criterio (Fase 1) y para saber exactamente qué se borraría en
la Fase 2, que es la irreversible.

La landing es **vitrina pública** —banners, biografía, propuestas, eventos,
galería, testimonios, feed de redes, voluntarios y contacto— y no toca ninguna
decisión de campaña: no entra en el CRM, ni en el escrutinio, ni en el análisis.
Esa es la razón de retirarla.

> **Estado (Fase 1):** apagada. `config('landing.habilitada')`, `false` por
> defecto (`LANDING_HABILITADA` en el `.env`). Con el flag apagado **las rutas ni
> se registran**: los endpoints públicos responden 404 y el admin no existe. El
> código y los datos siguen intactos.

---

## 1. Backend

### 1.1 Rutas

| Bloque | Dónde | Qué es |
| --- | --- | --- |
| Público | `routes/api.php` · `Route::prefix('landingpage')` | 9 endpoints **sin autenticación**: `GET banners`, `biografia`, `propuestas`, `eventos`, `galeria`, `testimonios`, `social-feed`; `POST voluntarios`, `contacto`. El tenant se resuelve por cabecera `X-Tenant-Slug` o `?tenant=` |
| Admin | `routes/api.php` · `Route::prefix('landingpage/admin')` | 6 `apiResource` (banners, propuestas, eventos, galeria, testimonios, social-feed) + 3 rutas de biografía. Middleware `permission:manage_landingpage` |
| Redes | `routes/api.php` · `Route::prefix('settings/social-media')` | 8 endpoints: credenciales por red, auto-sync y disparo manual del sync |

Los tres bloques quedaron dentro de `if (config('landing.habilitada'))`.

### 1.2 Controllers

| Archivo | Qué hace |
| --- | --- |
| `app/Http/Controllers/Api/V1/LandingPageController.php` | Todo el lado **público** (los 9 endpoints) |
| `app/Http/Controllers/Api/V1/Landing/LandingBannerAdminController.php` | CRUD de banners |
| `.../Landing/LandingPropuestaAdminController.php` | CRUD de propuestas |
| `.../Landing/LandingEventoAdminController.php` | CRUD de eventos |
| `.../Landing/LandingGaleriaAdminController.php` | CRUD de galería |
| `.../Landing/LandingTestimonioAdminController.php` | CRUD de testimonios |
| `.../Landing/LandingSocialFeedAdminController.php` | CRUD del feed de redes |
| `.../Landing/BiografiaAdminController.php` | Biografía: vive en `tenants.biografia_data` (JSON), no en tabla propia |
| `.../SocialMediaSettingsController.php` | Credenciales de redes y disparo del sync |

### 1.3 Modelos

`LandingBanner`, `LandingPropuesta`, `LandingEvento`, `LandingGaleria`,
`LandingTestimonio`, `LandingSocialFeed`, `LandingVoluntario`, `LandingContacto`
(todos en `app/Models/`).

### 1.4 Tablas y columnas

| Tabla | Migración |
| --- | --- |
| `landing_banners` | `2025_11_08_131315_create_landing_banners_table.php` |
| `landing_eventos` | `2025_11_08_131316_create_landing_eventos_table.php` |
| `landing_galeria` | `2025_11_08_131316_create_landing_galeria_table.php` |
| `landing_propuestas` | `2025_11_08_131316_create_landing_propuestas_table.php` |
| `landing_social_feed` | `2025_11_08_131317_create_landing_social_feed_table.php` (+ `2025_11_08_165051_add_sync_fields...`) |
| `landing_testimonios` | `2025_11_08_131317_create_landing_testimonios_table.php` |
| `landing_contactos` | `2025_11_08_131318_create_landing_contactos_table.php` |
| `landing_voluntarios` | `2025_11_08_131318_create_landing_voluntarios_table.php` |

Columnas de la landing **dentro de `tenants`** (ojo en la Fase 2: la tabla es del
producto, las columnas no):

- `biografia_data` (JSON) — `2025_11_08_131124_add_biografia_data_to_tenants_table.php`
- Credenciales sociales — `2025_11_08_165025_add_social_media_credentials_to_tenants_table.php`:
  `twitter_enabled`, `twitter_bearer_token`, `twitter_user_id`, `twitter_username`,
  `facebook_enabled`, `facebook_access_token`, `facebook_page_id`,
  `instagram_enabled`, `instagram_access_token`, `instagram_user_id`, `instagram_username`,
  `youtube_enabled`, `youtube_api_key`, `youtube_channel_id`,
  `social_auto_sync_enabled`, `social_sync_interval_minutes`, `social_last_synced_at`

> **Dato sensible.** Ahí viven tokens de acceso de terceros (`*_access_token`,
> `*_bearer_token`, `youtube_api_key`). Con la landing apagada dejan de usarse; la
> Fase 2 debería **borrarlos**, no solo dejar de leerlos.

### 1.5 Integraciones (sync de redes)

| Pieza | Archivo |
| --- | --- |
| Servicio | `app/Services/SocialMediaSyncService.php` |
| Job | `app/Jobs/SyncSocialMediaJob.php` |
| Comando | `app/Console/Commands/SyncSocialMediaCommand.php` (`social:sync`) |
| Agenda | `routes/console.php` · `Schedule::call(...)->name('sync-social-media')->everyFifteenMinutes()` |

Alimenta `landing_social_feed` y **no tiene otro consumidor**, así que se apaga
con la landing: el comando avisa y sale en 0, y la tarea agendada no hace nada.

### 1.6 Permisos

`manage_landingpage` (`App\Support\Permissions::MANAGE_LANDINGPAGE`), en el grupo
`landing` del catálogo. **Se queda sembrado** en la Fase 1: apagar no es borrar, y
prender el flag tiene que devolver la landing entera, roles incluidos.

---

## 2. Frontend

| Pieza | Archivos |
| --- | --- |
| Páginas | `src/pages/LandingBanners.tsx`, `LandingBiografia.tsx`, `LandingEventos.tsx`, `LandingGaleria.tsx`, `LandingPropuestas.tsx`, `LandingSocialFeed.tsx`, `LandingTestimonios.tsx` |
| Componentes | `src/components/landingpage/` — `BannerForm`, `BiografiaForm`, `EventoForm`, `GaleriaForm`, `PropuestaForm`, `SocialFeedForm`, `TestimonioForm` |
| Hooks | `src/hooks/` — `useBanners`, `useBiografia`, `useEventos`, `useGaleria`, `usePropuestas`, `useSocialFeed`, `useTestimonios` |
| Servicio | `src/services/landingpage.ts` |
| Rutas | `src/App.tsx` — 7 rutas `landingpage/*`, cada una tras `ProtectedRoute` con `MANAGE_LANDINGPAGE` |
| Menú | `src/components/layout/Sidebar.tsx` — el grupo «Landing Page» con sus 7 entradas |
| Permiso | `src/constants/permissions.ts` — `MANAGE_LANDINGPAGE` |

> El menú ya estaba **comentado a mano** («Oculto temporalmente») antes de esta
> spec. La 0085 lo devolvió al código y lo puso tras el flag: dos mecanismos para
> lo mismo —un comentario y un interruptor— es cómo se pierde la reversibilidad
> que la spec promete.

---

## 3. Qué NO es de la landing (y por eso no se toca)

Vale la pena dejarlo escrito, porque son las piezas que un borrado apresurado se
llevaría por delante:

- **Check-in público de reuniones** (`meetings/check-in/{qr}`) — también es
  público y también es una página de cara al votante, pero es herramienta de
  campaña: mide asistencia real. Se queda.
- **Generación de imagen del puesto de votación** (`voting-place/generate-image`)
  — público, del CRM.
- **`tenants.logo` y los colores del tema** — son del panel, no de la vitrina.
- **`landing_contactos` / `landing_voluntarios`** — son de la landing, pero traen
  **datos de personas** que alguien pudo escribir. Antes de borrarlos (Fase 2),
  exportarlos o confirmar que no hay nada que rescatar.

---

## 4. Fase 2 (follow-up, no hecha)

Cuando se confirme que nadie la usa: borrar las 8 tablas y las columnas sociales
de `tenants` (con los tokens dentro), los 9 controllers, los 8 modelos, el
servicio/job/comando/agenda del sync, las 7 páginas + 7 componentes + 7 hooks +
el servicio del frontend, el permiso `manage_landingpage` del catálogo y del
seeder, y este flag. Esa parte **no es reversible**, y por eso va aparte.
