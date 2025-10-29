# EXAMPLES - Ejemplos de código para completar el proyecto

## 1. Actualizar Modelos

### app/Models/Tenant.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Spatie\Activitylog\Traits\LogsActivity;
use Spatie\Activitylog\LogOptions;

class Tenant extends Model
{
    use HasFactory, SoftDeletes, LogsActivity;

    protected $fillable = [
        'slug', 'nombre', 'tipo_cargo', 'identificacion',
        'email_contacto', 'phone_contacto', 'metadata', 'is_active',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_active' => 'boolean',
    ];

    public function getActivitylogOptions(): LogOptions
    {
        return LogOptions::defaults()->logOnly(['nombre', 'tipo_cargo'])->logOnlyDirty();
    }

    public function users()
    {
        return $this->hasMany(User::class);
    }

    public function meetings()
    {
        return $this->hasMany(Meeting::class);
    }

    public function campaigns()
    {
        return $this->hasMany(Campaign::class);
    }

    public function getRouteKeyName()
    {
        return 'slug';
    }
}
```

### app/Models/Meeting.php
```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasTenant;

class Meeting extends Model
{
    use HasFactory, SoftDeletes, HasTenant;

    protected $fillable = [
        'tenant_id', 'title', 'description', 'starts_at', 'ends_at',
        'lugar_nombre', 'direccion', 'latitude', 'longitude', 'qr_code',
        'planner_user_id', 'department_id', 'city_id', 'template_id', 'status'
    ];

    protected $casts = [
        'starts_at' => 'datetime',
        'ends_at' => 'datetime',
        'metadata' => 'array',
    ];

    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function planner()
    {
        return $this->belongsTo(User::class, 'planner_user_id');
    }

    public function city()
    {
        return $this->belongsTo(City::class);
    }

    public function attendees()
    {
        return $this->hasMany(MeetingAttendee::class);
    }

    public function commitments()
    {
        return $this->hasMany(Commitment::class);
    }
}
```

## 2. AuthController

### app/Http/Controllers/Api/V1/AuthController.php
```php
<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

class AuthController extends Controller
{
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->only(['email', 'password']);

        if (!$token = auth('api')->attempt($credentials)) {
            return response()->json(['error' => 'Unauthorized'], 401);
        }

        return $this->respondWithToken($token);
    }

    public function me(): JsonResponse
    {
        return response()->json(auth('api')->user());
    }

    public function logout(): JsonResponse
    {
        auth('api')->logout();
        return response()->json(['message' => 'Successfully logged out']);
    }

    public function refresh(): JsonResponse
    {
        return $this->respondWithToken(auth('api')->refresh());
    }

    protected function respondWithToken($token): JsonResponse
    {
        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
            'user' => auth('api')->user()
        ]);
    }
}
```

## 3. Middleware EnsureTenant

### app/Http/Middleware/EnsureTenant.php
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureTenant
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
        }

        // Superadmin global puede acceder a todo
        if ($user->isSuperAdminGlobal()) {
            return $next($request);
        }

        // Usuario debe tener tenant
        if (!$user->tenant_id) {
            return response()->json(['error' => 'No tenant assigned'], 403);
        }

        // Setear tenant en el contenedor
        app()->instance('tenant', $user->tenant);

        return $next($request);
    }
}
```

## 4. Middleware CheckSuperAdmin

### app/Http/Middleware/CheckSuperAdmin.php
```php
<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (!$user || !$user->isSuperAdminGlobal()) {
            return response()->json(['error' => 'Forbidden'], 403);
        }

        return $next($request);
    }
}
```

## 5. LoginRequest

### app/Http/Requests/Api/V1/Auth/LoginRequest.php
```php
<?php

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string|min:6',
        ];
    }
}
```

## 6. StoreTenantRequest

### app/Http/Requests/Api/V1/Tenant/StoreTenantRequest.php
```php
<?php

namespace App\Http\Requests\Api\V1\Tenant;

use Illuminate\Foundation\Http\FormRequest;

class StoreTenantRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->isSuperAdminGlobal();
    }

    public function rules(): array
    {
        return [
            'slug' => 'required|string|unique:tenants,slug|alpha_dash',
            'nombre' => 'required|string|max:255',
            'tipo_cargo' => 'required|in:Gobernacion,Alcaldia,Concejo,Congresista,Diputado,Otro',
            'identificacion' => 'required|string|unique:tenants,identificacion',
            'email_contacto' => 'required|email',
            'phone_contacto' => 'nullable|string',
            'metadata' => 'nullable|array',
        ];
    }
}
```

## 7. UserResource

### app/Http/Resources/Api/V1/UserResource.php
```php
<?php

namespace App\Http\Resources\Api\V1;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'tenant_id' => $this->tenant_id,
            'name' => $this->name,
            'email' => $this->email,
            'phone' => $this->phone,
            'cedula' => $this->cedula,
            'is_team_leader' => $this->is_team_leader,
            'is_super_admin' => $this->is_super_admin,
            'tenant' => new TenantResource($this->whenLoaded('tenant')),
            'supervisor' => new UserResource($this->whenLoaded('supervisor')),
            'subordinates' => UserResource::collection($this->whenLoaded('subordinates')),
            'roles' => $this->roles->pluck('name'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
```

## 8. TenantPolicy

### app/Policies/TenantPolicy.php
```php
<?php

namespace App\Policies;

use App\Models\Tenant;
use App\Models\User;

class TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->isSuperAdminGlobal();
    }

    public function view(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdminGlobal() || $user->tenant_id === $tenant->id;
    }

    public function create(User $user): bool
    {
        return $user->isSuperAdminGlobal();
    }

    public function update(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdminGlobal() || 
               ($user->tenant_id === $tenant->id && $user->isTenantSuperAdmin());
    }

    public function delete(User $user, Tenant $tenant): bool
    {
        return $user->isSuperAdminGlobal();
    }
}
```

## 9. SuperAdminSeeder

### database/seeders/SuperAdminSeeder.php
```php
<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        User::create([
            'tenant_id' => null,
            'name' => env('SUPERADMIN_NAME', 'Super Administrator'),
            'email' => env('SUPERADMIN_EMAIL', 'admin@politics-platform.com'),
            'password' => Hash::make(env('SUPERADMIN_PASSWORD', 'SuperAdmin2025!')),
            'is_super_admin' => true,
            'is_team_leader' => false,
            'email_verified_at' => now(),
        ]);
    }
}
```

## 10. RolesAndPermissionsSeeder

### database/seeders/RolesAndPermissionsSeeder.php
```php
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class RolesAndPermissionsSeeder extends Seeder
{
    public function run(): void
    {
        // Reset cached roles and permissions
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Crear permisos
        $permissions = [
            'view users', 'create users', 'update users', 'delete users',
            'view meetings', 'create meetings', 'update meetings', 'delete meetings',
            'view campaigns', 'create campaigns', 'send campaigns',
            'view commitments', 'create commitments', 'update commitments',
            'view resources', 'allocate resources',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission]);
        }

        // Crear roles base
        $tenantSuperAdmin = Role::create(['name' => 'tenant-superadmin']);
        $tenantSuperAdmin->givePermissionTo(Permission::all());

        $coordinator = Role::create(['name' => 'coordinator']);
        $coordinator->givePermissionTo([
            'view users', 'view meetings', 'create meetings', 'update meetings',
            'view commitments', 'create commitments',
        ]);

        $teamLeader = Role::create(['name' => 'team-leader']);
        $teamLeader->givePermissionTo([
            'view meetings', 'view commitments', 'view resources',
        ]);
    }
}
```

## 11. Routes

### routes/api.php
```php
<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\TenantController;
use App\Http\Controllers\Api\V1\UserController;
use App\Http\Controllers\Api\V1\MeetingController;
use App\Http\Controllers\Api\V1\CampaignController;

Route::prefix('v1')->group(function () {
    
    // Auth routes
    Route::prefix('auth')->group(function () {
        Route::post('/login', [AuthController::class, 'login']);
        Route::middleware('auth:api')->group(function () {
            Route::post('/logout', [AuthController::class, 'logout']);
            Route::post('/refresh', [AuthController::class, 'refresh']);
            Route::get('/me', [AuthController::class, 'me']);
        });
    });

    // Protected routes
    Route::middleware(['auth:api', 'tenant'])->group(function () {
        
        // Tenants (solo superadmin)
        Route::middleware('superadmin')->group(function () {
            Route::apiResource('tenants', TenantController::class);
        });

        // Users
        Route::apiResource('users', UserController::class);
        Route::get('users/team-tree', [UserController::class, 'teamTree']);

        // Meetings
        Route::apiResource('meetings', MeetingController::class);
        Route::get('meetings/{meeting}/qr', [MeetingController::class, 'showQR']);
        Route::post('meetings/{meeting}/attendees', [MeetingController::class, 'storeAttendees']);

        // Campaigns
        Route::apiResource('campaigns', CampaignController::class);
        Route::post('campaigns/{campaign}/send', [CampaignController::class, 'send']);
        Route::post('campaigns/{campaign}/preview', [CampaignController::class, 'preview']);
    });

    // Public routes
    Route::post('meetings/checkin/{qr}', [MeetingController::class, 'checkin']);
});
```

## 12. Configurar Auth en config/auth.php

```php
'defaults' => [
    'guard' => 'api',
    'passwords' => 'users',
],

'guards' => [
    'api' => [
        'driver' => 'jwt',
        'provider' => 'users',
    ],
],
```

## 13. Registrar Middlewares en bootstrap/app.php

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->alias([
        'tenant' => \App\Http\Middleware\EnsureTenant::class,
        'superadmin' => \App\Http\Middleware\CheckSuperAdmin::class,
    ]);
})
```

## 14. Registrar Policies en AuthServiceProvider

```php
protected $policies = [
    \App\Models\Tenant::class => \App\Policies\TenantPolicy::class,
    \App\Models\Meeting::class => \App\Policies\MeetingPolicy::class,
    \App\Models\Campaign::class => \App\Policies\CampaignPolicy::class,
];
```

## 15. Test Example

### tests/Feature/Api/V1/Auth/AuthTest.php
```php
<?php

namespace Tests\Feature\Api\V1\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials()
    {
        $user = User::factory()->create([
            'email' => 'test@example.com',
            'password' => bcrypt('password123'),
        ]);

        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'test@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
                 ->assertJsonStructure([
                     'access_token',
                     'token_type',
                     'expires_in',
                     'user'
                 ]);
    }

    public function test_user_cannot_login_with_incorrect_credentials()
    {
        $response = $this->postJson('/api/v1/auth/login', [
            'email' => 'wrong@example.com',
            'password' => 'wrongpassword',
        ]);

        $response->assertStatus(401);
    }
}
```

---

Con estos ejemplos, tienes la base para implementar todos los componentes restantes del proyecto.
