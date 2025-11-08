# Integración de Social Feed con Redes Sociales Reales

Documentación técnica para la sincronización automática de posts desde redes sociales.

**Fecha:** 8 de Noviembre, 2025  
**Versión:** 1.0

---

## Índice

1. [Arquitectura de la Solución](#arquitectura-de-la-solución)
2. [APIs de Redes Sociales](#apis-de-redes-sociales)
3. [Implementación Backend](#implementación-backend)
4. [Endpoints de Sincronización](#endpoints-de-sincronización)
5. [Configuración](#configuración)
6. [Automatización](#automatización)

---

## Arquitectura de la Solución

### Flujo Recomendado

```
┌─────────────────┐
│  Redes Sociales │
│  (Twitter/X,    │
│  Facebook,      │
│  Instagram)     │
└────────┬────────┘
         │ API Calls
         ▼
┌─────────────────────────┐
│  Laravel Backend        │
│  - Consume APIs         │
│  - Almacena en DB       │
│  - Cachea respuestas    │
│  - Job Queue (Sync)     │
└────────┬────────────────┘
         │ JSON Response
         ▼
┌─────────────────────────┐
│  Frontend               │
│  - Obtiene posts        │
│  - Muestra en landing   │
│  - Actualización rápida │
└─────────────────────────┘
```

### Ventajas de este Enfoque

✅ **Seguridad**: Los tokens de API permanecen en el servidor  
✅ **Performance**: Los posts se cachean localmente  
✅ **Confiabilidad**: Funciona aunque la red social esté caída  
✅ **Control**: Puedes filtrar, moderar o transformar contenido  
✅ **Límites de API**: Reduces llamadas directas a las redes  
✅ **Consistencia**: Misma estructura de datos independiente de la red  

---

## APIs de Redes Sociales

### 1. Twitter (X) API v2

**Documentación**: https://developer.twitter.com/en/docs/twitter-api

**Endpoints necesarios:**
```
GET /2/users/:id/tweets
GET /2/tweets?ids={ids}&tweet.fields=attachments,created_at,public_metrics
```

**Datos que se obtienen:**
- ID del tweet
- Texto del contenido
- Fecha de creación
- Métricas (likes, retweets, replies)
- Media (imágenes, videos)
- Autor

**Requisitos:**
- Cuenta de desarrollador en Twitter
- Bearer Token (API Key)
- Essential Access (gratis) o Elevated Access

**Límites gratuitos:**
- 500,000 tweets/mes (Essential)
- 15 requests/15 minutos

---

### 2. Facebook Graph API

**Documentación**: https://developers.facebook.com/docs/graph-api

**Endpoints necesarios:**
```
GET /me/posts?fields=id,message,created_time,full_picture,reactions.summary(true),shares,comments.summary(true)
GET /{page-id}/posts
```

**Datos que se obtienen:**
- ID del post
- Mensaje/texto
- Fecha de creación
- Imagen/video
- Reacciones (likes)
- Compartidos
- Comentarios (cantidad)

**Requisitos:**
- Facebook App
- Access Token de larga duración
- Permisos: `pages_read_engagement`, `pages_read_user_content`

**Límites:**
- 200 llamadas/hora por usuario
- Token expira en 60 días (renovable)

---

### 3. Instagram Graph API

**Documentación**: https://developers.facebook.com/docs/instagram-api

**Endpoints necesarios:**
```
GET /{user-id}/media?fields=id,caption,media_type,media_url,permalink,timestamp,like_count,comments_count
```

**Datos que se obtienen:**
- ID del post
- Caption (texto)
- Tipo de media
- URL de imagen/video
- Timestamp
- Likes
- Comentarios

**Requisitos:**
- Instagram Business o Creator Account
- Facebook App conectada
- Access Token
- Permisos: `instagram_basic`, `instagram_manage_insights`

**Límites:**
- 200 llamadas/hora

---

## Implementación Backend

### Paso 1: Configuración

**Archivo: `config/social.php`**

```php
<?php

return [
    'twitter' => [
        'enabled' => env('TWITTER_ENABLED', false),
        'bearer_token' => env('TWITTER_BEARER_TOKEN'),
        'user_id' => env('TWITTER_USER_ID'),
        'max_posts' => env('TWITTER_MAX_POSTS', 10),
    ],

    'facebook' => [
        'enabled' => env('FACEBOOK_ENABLED', false),
        'app_id' => env('FACEBOOK_APP_ID'),
        'app_secret' => env('FACEBOOK_APP_SECRET'),
        'access_token' => env('FACEBOOK_ACCESS_TOKEN'),
        'page_id' => env('FACEBOOK_PAGE_ID'),
        'max_posts' => env('FACEBOOK_MAX_POSTS', 10),
    ],

    'instagram' => [
        'enabled' => env('INSTAGRAM_ENABLED', false),
        'access_token' => env('INSTAGRAM_ACCESS_TOKEN'),
        'user_id' => env('INSTAGRAM_USER_ID'),
        'max_posts' => env('INSTAGRAM_MAX_POSTS', 10),
    ],

    'sync' => [
        'auto_sync' => env('SOCIAL_AUTO_SYNC', true),
        'sync_interval' => env('SOCIAL_SYNC_INTERVAL', 15), // minutos
        'cache_ttl' => env('SOCIAL_CACHE_TTL', 5), // minutos
    ],
];
```

**Archivo: `.env`**

```env
# Twitter Configuration
TWITTER_ENABLED=true
TWITTER_BEARER_TOKEN=your_bearer_token_here
TWITTER_USER_ID=1234567890
TWITTER_MAX_POSTS=10

# Facebook Configuration
FACEBOOK_ENABLED=true
FACEBOOK_APP_ID=your_app_id
FACEBOOK_APP_SECRET=your_app_secret
FACEBOOK_ACCESS_TOKEN=your_long_lived_token
FACEBOOK_PAGE_ID=your_page_id
FACEBOOK_MAX_POSTS=10

# Instagram Configuration
INSTAGRAM_ENABLED=true
INSTAGRAM_ACCESS_TOKEN=your_instagram_token
INSTAGRAM_USER_ID=your_instagram_user_id
INSTAGRAM_MAX_POSTS=10

# Sync Settings
SOCIAL_AUTO_SYNC=true
SOCIAL_SYNC_INTERVAL=15
SOCIAL_CACHE_TTL=5
```

---

### Paso 2: Servicio de Sincronización

**Archivo: `app/Services/SocialMediaSyncService.php`**

```php
<?php

namespace App\Services;

use App\Models\LandingSocialFeed;
use App\Models\Tenant;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class SocialMediaSyncService
{
    /**
     * Sincronizar posts de todas las redes sociales configuradas
     */
    public function syncAll(Tenant $tenant): array
    {
        $results = [
            'twitter' => ['synced' => 0, 'errors' => []],
            'facebook' => ['synced' => 0, 'errors' => []],
            'instagram' => ['synced' => 0, 'errors' => []],
        ];

        if (config('social.twitter.enabled')) {
            $results['twitter'] = $this->syncTwitter($tenant);
        }

        if (config('social.facebook.enabled')) {
            $results['facebook'] = $this->syncFacebook($tenant);
        }

        if (config('social.instagram.enabled')) {
            $results['instagram'] = $this->syncInstagram($tenant);
        }

        return $results;
    }

    /**
     * Sincronizar posts de Twitter
     */
    public function syncTwitter(Tenant $tenant): array
    {
        try {
            $userId = config('social.twitter.user_id');
            $bearerToken = config('social.twitter.bearer_token');
            $maxPosts = config('social.twitter.max_posts', 10);

            $response = Http::withToken($bearerToken)
                ->get("https://api.twitter.com/2/users/{$userId}/tweets", [
                    'max_results' => $maxPosts,
                    'tweet.fields' => 'created_at,public_metrics,attachments',
                    'expansions' => 'attachments.media_keys',
                    'media.fields' => 'url,preview_image_url',
                ]);

            if (!$response->successful()) {
                throw new \Exception("Twitter API error: " . $response->body());
            }

            $data = $response->json();
            $tweets = $data['data'] ?? [];
            $includes = $data['includes'] ?? [];
            $media = collect($includes['media'] ?? [])->keyBy('media_key');

            $synced = 0;

            foreach ($tweets as $tweet) {
                // Buscar si ya existe
                $existingPost = LandingSocialFeed::where('tenant_id', $tenant->id)
                    ->where('plataforma', 'twitter')
                    ->where('external_id', $tweet['id'])
                    ->first();

                // Obtener imagen si existe
                $imageUrl = null;
                if (isset($tweet['attachments']['media_keys'][0])) {
                    $mediaKey = $tweet['attachments']['media_keys'][0];
                    $mediaItem = $media->get($mediaKey);
                    $imageUrl = $mediaItem['url'] ?? $mediaItem['preview_image_url'] ?? null;
                }

                $postData = [
                    'tenant_id' => $tenant->id,
                    'plataforma' => 'twitter',
                    'external_id' => $tweet['id'],
                    'usuario' => '@' . ($data['includes']['users'][0]['username'] ?? 'candidato'),
                    'contenido' => $tweet['text'],
                    'fecha' => Carbon::parse($tweet['created_at'])->format('Y-m-d'),
                    'likes' => $tweet['public_metrics']['like_count'] ?? 0,
                    'compartidos' => $tweet['public_metrics']['retweet_count'] ?? 0,
                    'comentarios' => $tweet['public_metrics']['reply_count'] ?? 0,
                    'external_url' => "https://twitter.com/i/web/status/{$tweet['id']}",
                    'imagen' => $imageUrl,
                    'is_active' => true,
                ];

                if ($existingPost) {
                    // Actualizar métricas
                    $existingPost->update($postData);
                } else {
                    // Crear nuevo
                    LandingSocialFeed::create($postData);
                    $synced++;
                }
            }

            return ['synced' => $synced, 'errors' => []];

        } catch (\Exception $e) {
            Log::error('Twitter sync error: ' . $e->getMessage());
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Sincronizar posts de Facebook
     */
    public function syncFacebook(Tenant $tenant): array
    {
        try {
            $pageId = config('social.facebook.page_id');
            $accessToken = config('social.facebook.access_token');
            $maxPosts = config('social.facebook.max_posts', 10);

            $response = Http::get("https://graph.facebook.com/v18.0/{$pageId}/posts", [
                'access_token' => $accessToken,
                'fields' => 'id,message,created_time,full_picture,reactions.summary(true),shares,comments.summary(true),permalink_url',
                'limit' => $maxPosts,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Facebook API error: " . $response->body());
            }

            $posts = $response->json()['data'] ?? [];
            $synced = 0;

            foreach ($posts as $post) {
                if (!isset($post['message'])) {
                    continue; // Skip posts without text
                }

                $existingPost = LandingSocialFeed::where('tenant_id', $tenant->id)
                    ->where('plataforma', 'facebook')
                    ->where('external_id', $post['id'])
                    ->first();

                $postData = [
                    'tenant_id' => $tenant->id,
                    'plataforma' => 'facebook',
                    'external_id' => $post['id'],
                    'usuario' => $tenant->nombre,
                    'contenido' => $post['message'],
                    'fecha' => Carbon::parse($post['created_time'])->format('Y-m-d'),
                    'likes' => $post['reactions']['summary']['total_count'] ?? 0,
                    'compartidos' => $post['shares']['count'] ?? 0,
                    'comentarios' => $post['comments']['summary']['total_count'] ?? 0,
                    'external_url' => $post['permalink_url'] ?? null,
                    'imagen' => $post['full_picture'] ?? null,
                    'is_active' => true,
                ];

                if ($existingPost) {
                    $existingPost->update($postData);
                } else {
                    LandingSocialFeed::create($postData);
                    $synced++;
                }
            }

            return ['synced' => $synced, 'errors' => []];

        } catch (\Exception $e) {
            Log::error('Facebook sync error: ' . $e->getMessage());
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }

    /**
     * Sincronizar posts de Instagram
     */
    public function syncInstagram(Tenant $tenant): array
    {
        try {
            $userId = config('social.instagram.user_id');
            $accessToken = config('social.instagram.access_token');
            $maxPosts = config('social.instagram.max_posts', 10);

            $response = Http::get("https://graph.instagram.com/{$userId}/media", [
                'access_token' => $accessToken,
                'fields' => 'id,caption,media_type,media_url,permalink,timestamp,like_count,comments_count',
                'limit' => $maxPosts,
            ]);

            if (!$response->successful()) {
                throw new \Exception("Instagram API error: " . $response->body());
            }

            $posts = $response->json()['data'] ?? [];
            $synced = 0;

            foreach ($posts as $post) {
                if (!isset($post['caption'])) {
                    continue;
                }

                $existingPost = LandingSocialFeed::where('tenant_id', $tenant->id)
                    ->where('plataforma', 'instagram')
                    ->where('external_id', $post['id'])
                    ->first();

                $postData = [
                    'tenant_id' => $tenant->id,
                    'plataforma' => 'instagram',
                    'external_id' => $post['id'],
                    'usuario' => '@' . $tenant->slug,
                    'contenido' => $post['caption'],
                    'fecha' => Carbon::parse($post['timestamp'])->format('Y-m-d'),
                    'likes' => $post['like_count'] ?? 0,
                    'compartidos' => 0, // Instagram no proporciona shares
                    'comentarios' => $post['comments_count'] ?? 0,
                    'external_url' => $post['permalink'] ?? null,
                    'imagen' => $post['media_url'] ?? null,
                    'is_active' => true,
                ];

                if ($existingPost) {
                    $existingPost->update($postData);
                } else {
                    LandingSocialFeed::create($postData);
                    $synced++;
                }
            }

            return ['synced' => $synced, 'errors' => []];

        } catch (\Exception $e) {
            Log::error('Instagram sync error: ' . $e->getMessage());
            return ['synced' => 0, 'errors' => [$e->getMessage()]];
        }
    }
}
```

---

### Paso 3: Migración para External ID

**Archivo: `database/migrations/2025_11_08_add_external_fields_to_landing_social_feed.php`**

```php
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('landing_social_feed', function (Blueprint $table) {
            $table->string('external_id')->nullable()->after('id');
            $table->string('external_url')->nullable()->after('imagen');
            $table->timestamp('last_synced_at')->nullable()->after('is_active');
            
            // Índice para búsquedas rápidas
            $table->index(['tenant_id', 'plataforma', 'external_id']);
        });
    }

    public function down(): void
    {
        Schema::table('landing_social_feed', function (Blueprint $table) {
            $table->dropIndex(['tenant_id', 'plataforma', 'external_id']);
            $table->dropColumn(['external_id', 'external_url', 'last_synced_at']);
        });
    }
};
```

---

## Endpoints de Sincronización

### 1. Sincronizar Todas las Redes

**Endpoint:** `POST /api/v1/landingpage/admin/social-feed/sync`

**Permisos:** Usuario autenticado del tenant

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Sincronización completada",
  "results": {
    "twitter": {
      "synced": 5,
      "errors": []
    },
    "facebook": {
      "synced": 3,
      "errors": []
    },
    "instagram": {
      "synced": 7,
      "errors": []
    }
  },
  "total_synced": 15
}
```

---

### 2. Sincronizar Red Específica

**Endpoint:** `POST /api/v1/landingpage/admin/social-feed/sync/{platform}`

**Plataformas permitidas:** `twitter`, `facebook`, `instagram`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "success": true,
  "message": "Posts de Twitter sincronizados",
  "platform": "twitter",
  "synced": 5,
  "errors": []
}
```

---

### 3. Verificar Estado de Configuración

**Endpoint:** `GET /api/v1/landingpage/admin/social-feed/config`

**Headers:**
```json
{
  "Authorization": "Bearer {token}",
  "Content-Type": "application/json"
}
```

**Respuesta exitosa (200):**
```json
{
  "twitter": {
    "enabled": true,
    "configured": true,
    "last_sync": "2025-11-08T10:30:00Z"
  },
  "facebook": {
    "enabled": true,
    "configured": true,
    "last_sync": "2025-11-08T10:25:00Z"
  },
  "instagram": {
    "enabled": false,
    "configured": false,
    "last_sync": null
  },
  "auto_sync_enabled": true,
  "sync_interval_minutes": 15
}
```

---

## Controlador

**Archivo: `app/Http/Controllers/Api/V1/Landing/SocialMediaSyncController.php`**

```php
<?php

namespace App\Http\Controllers\Api\V1\Landing;

use App\Http\Controllers\Controller;
use App\Services\SocialMediaSyncService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SocialMediaSyncController extends Controller
{
    protected SocialMediaSyncService $syncService;

    public function __construct(SocialMediaSyncService $syncService)
    {
        $this->syncService = $syncService;
    }

    /**
     * Sincronizar todas las redes sociales
     */
    public function syncAll(Request $request): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = $user->tenant;

        $results = $this->syncService->syncAll($tenant);

        $totalSynced = array_sum(array_column($results, 'synced'));

        return response()->json([
            'success' => true,
            'message' => 'Sincronización completada',
            'results' => $results,
            'total_synced' => $totalSynced,
        ]);
    }

    /**
     * Sincronizar red social específica
     */
    public function syncPlatform(Request $request, string $platform): JsonResponse
    {
        /** @var \App\Models\User $user */
        $user = $request->user();
        $tenant = $user->tenant;

        $validPlatforms = ['twitter', 'facebook', 'instagram'];
        
        if (!in_array($platform, $validPlatforms)) {
            return response()->json([
                'error' => 'Plataforma no válida. Usa: twitter, facebook o instagram'
            ], 400);
        }

        $method = 'sync' . ucfirst($platform);
        $result = $this->syncService->$method($tenant);

        return response()->json([
            'success' => true,
            'message' => "Posts de {$platform} sincronizados",
            'platform' => $platform,
            'synced' => $result['synced'],
            'errors' => $result['errors'],
        ]);
    }

    /**
     * Ver configuración de redes sociales
     */
    public function getConfig(): JsonResponse
    {
        return response()->json([
            'twitter' => [
                'enabled' => config('social.twitter.enabled'),
                'configured' => !empty(config('social.twitter.bearer_token')),
            ],
            'facebook' => [
                'enabled' => config('social.facebook.enabled'),
                'configured' => !empty(config('social.facebook.access_token')),
            ],
            'instagram' => [
                'enabled' => config('social.instagram.enabled'),
                'configured' => !empty(config('social.instagram.access_token')),
            ],
            'auto_sync_enabled' => config('social.sync.auto_sync'),
            'sync_interval_minutes' => config('social.sync.sync_interval'),
        ]);
    }
}
```

---

## Automatización

### Job para Sincronización Automática

**Archivo: `app/Jobs/SyncSocialMediaJob.php`**

```php
<?php

namespace App\Jobs;

use App\Models\Tenant;
use App\Services\SocialMediaSyncService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SyncSocialMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected Tenant $tenant;

    public function __construct(Tenant $tenant)
    {
        $this->tenant = $tenant;
    }

    public function handle(SocialMediaSyncService $syncService): void
    {
        Log::info("Syncing social media for tenant: {$this->tenant->nombre}");
        
        $results = $syncService->syncAll($this->tenant);
        
        Log::info("Sync completed for tenant: {$this->tenant->nombre}", $results);
    }
}
```

---

### Comando Artisan

**Archivo: `app/Console/Commands/SyncSocialMediaCommand.php`**

```php
<?php

namespace App\Console\Commands;

use App\Jobs\SyncSocialMediaJob;
use App\Models\Tenant;
use Illuminate\Console\Command;

class SyncSocialMediaCommand extends Command
{
    protected $signature = 'social:sync {--tenant=} {--all}';
    protected $description = 'Sincronizar posts de redes sociales';

    public function handle(): int
    {
        if ($this->option('all')) {
            $tenants = Tenant::all();
            $this->info("Sincronizando {$tenants->count()} tenants...");
            
            foreach ($tenants as $tenant) {
                SyncSocialMediaJob::dispatch($tenant);
                $this->info("- Encolado: {$tenant->nombre}");
            }
        } elseif ($tenantId = $this->option('tenant')) {
            $tenant = Tenant::find($tenantId);
            
            if (!$tenant) {
                $this->error("Tenant no encontrado: {$tenantId}");
                return 1;
            }
            
            SyncSocialMediaJob::dispatch($tenant);
            $this->info("Sincronizando: {$tenant->nombre}");
        } else {
            $this->error('Especifica --tenant=ID o --all');
            return 1;
        }

        return 0;
    }
}
```

---

### Tarea Programada

**Archivo: `app/Console/Kernel.php`**

```php
protected function schedule(Schedule $schedule): void
{
    // Sincronizar cada 15 minutos si está habilitado
    if (config('social.sync.auto_sync')) {
        $interval = config('social.sync.sync_interval', 15);
        
        $schedule->command('social:sync --all')
            ->everyMinutes($interval)
            ->withoutOverlapping()
            ->runInBackground();
    }
}
```

---

## Rutas

Agregar en `routes/api.php`:

```php
// Social Media Sync (Protected - Admin only)
Route::middleware(['jwt.auth', 'tenant'])->prefix('landingpage/admin/social-feed')->group(function () {
    Route::post('/sync', [SocialMediaSyncController::class, 'syncAll']);
    Route::post('/sync/{platform}', [SocialMediaSyncController::class, 'syncPlatform']);
    Route::get('/config', [SocialMediaSyncController::class, 'getConfig']);
});
```

---

## Guía de Configuración

### Paso 1: Obtener Credenciales de Twitter

1. Ir a https://developer.twitter.com/
2. Crear una App
3. Obtener Bearer Token
4. Copiar tu User ID (encontrarlo en https://tweeterid.com/)

### Paso 2: Obtener Credenciales de Facebook

1. Ir a https://developers.facebook.com/
2. Crear una App
3. Configurar Graph API
4. Obtener Access Token de larga duración
5. Obtener Page ID

### Paso 3: Obtener Credenciales de Instagram

1. Convertir cuenta a Business/Creator
2. Conectar con Facebook Page
3. Usar Graph API de Instagram
4. Obtener Access Token
5. Obtener User ID

---

## Comandos Útiles

```bash
# Sincronizar manualmente todos los tenants
php artisan social:sync --all

# Sincronizar un tenant específico
php artisan social:sync --tenant=1

# Ver logs de sincronización
tail -f storage/logs/laravel.log | grep "Sync"

# Ejecutar queue worker
php artisan queue:work

# Ejecutar scheduler (desarrollo)
php artisan schedule:work
```

---

## Frontend - Ejemplo de Uso

```javascript
// Botón de sincronización manual en el admin
const sincronizarRedes = async () => {
  const response = await fetch('/api/v1/landingpage/admin/social-feed/sync', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    }
  });

  const data = await response.json();
  
  if (data.success) {
    alert(`Se sincronizaron ${data.total_synced} posts`);
  }
};

// Verificar configuración
const verificarConfig = async () => {
  const response = await fetch('/api/v1/landingpage/admin/social-feed/config', {
    headers: {
      'Authorization': `Bearer ${token}`
    }
  });

  const config = await response.json();
  console.log('Redes configuradas:', config);
};
```

---

## Notas Importantes

### Seguridad
- **NUNCA** expongas los tokens en el frontend
- Usa variables de entorno
- Rota tokens periódicamente
- Implementa rate limiting

### Performance
- Los posts se cachean en tu base de datos
- La sincronización es asíncrona (jobs)
- El frontend solo consulta tu API

### Límites de API
- Respeta los límites de cada plataforma
- Implementa retry logic con backoff
- Monitorea el uso de las APIs

### Mantenimiento
- Los tokens de Facebook/Instagram expiran (60 días)
- Debes renovarlos periódicamente
- Implementa notificaciones de expiración

---

**Fin de la Documentación de Integración**
