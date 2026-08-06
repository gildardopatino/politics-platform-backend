# Testing del backend

Implementado por la **Spec 0001 — Fundación de testing del backend + harness**
(`../../specs/0001-backend-testing-foundation/`).

## Cómo correr la suite

```bash
composer test           # limpia config + php artisan test
php artisan test        # equivalente
php artisan test --filter=CommitmentOverdueTest
./vendor/bin/pint       # formato (usar rutas concretas: el repo tiene deuda previa)
```

Requisitos del entorno: **PHP >= 8.2** con las extensiones **`pdo_sqlite` y
`sqlite3` habilitadas**. `phpunit.xml` corre sobre SQLite `:memory:`, cola `sync`
y cache/mail `array`; PostgreSQL solo se usa en runtime.

## Harness (`tests/TestCase.php`)

`TestCase` usa `RefreshDatabase` y siembra `RolesAndPermissionsSeeder`
(idempotente) en cada `setUp`. Helpers disponibles:

| Helper | Qué hace |
| --- | --- |
| `createTenantWithUser(array $permissions = [], ?Tenant $tenant = null, array $attributes = [])` | Crea tenant activo + usuario suyo, le asigna los permisos (creando los que falten con guard `api`) y devuelve `[$user, $token]`. |
| `actingAsTenantUser(User $user, ?string $token = null)` | Fija el header `Authorization: Bearer <jwt>`. |
| `actingAsSuperAdmin()` | Crea y autentica un super admin global (`is_super_admin = true`, `tenant_id = null`), para el que `TenantScope` no filtra. |
| `givePermissions(User $user, array $permissions)` | Asigna permisos limpiando la cache de spatie. |

Patrón típico:

```php
[$user, $token] = $this->createTenantWithUser(['view_commitments'], $tenant);

$this->actingAsTenantUser($user, $token)
    ->getJson('/api/v1/commitments/overdue')
    ->assertStatus(200);
```

## Factories

`TenantFactory` (con `expired()`), `MeetingFactory`, `CommitmentFactory` y
`VoterFactory` (todas con `forTenant()`; `CommitmentFactory` además `overdue()` y
`completed()`), más `UserFactory` ampliada con `forTenant()` y `superAdmin()`.

Las factories de `Meeting`/`Commitment` propagan el `tenant_id` a las entidades
que crean por debajo (planificador, reunión, autor), de modo que un árbol de
datos nunca queda mezclado entre tenants.

## Migraciones y SQLite

Tres migraciones usaban DDL exclusivo de PostgreSQL y rompían `migrate` en
SQLite. Ahora hacen `DB::getDriverName()` y mantienen **idéntico** el camino de
PostgreSQL:

- `2025_10_30_173908_update_campaigns_status_enum` — se omite fuera de pgsql.
- `2025_11_12_203159_make_type_nullable_in_resource_allocations_table` — fuera de
  pgsql se reconstruye la tabla vía `Blueprint::change()`.
- `2025_11_08_232624_migrate_user_geographic_data_to_pivot_table` — migración de
  datos con `NOW()`; se omite fuera de pgsql (en pruebas la BD arranca vacía).

## Bug resuelto: `GET /api/v1/commitments/overdue`

`Route::apiResource('commitments', ...)` se declaraba **antes** que la ruta
literal `/commitments/overdue`, así que `GET /commitments/{commitment}` capturaba
`overdue`, intentaba bindear un `Commitment` con id `"overdue"` y respondía
**404**: el método `CommitmentController@overdue` era código muerto.

Corregido en `routes/api.php` moviendo las rutas literales de commitments antes
del `apiResource`. Cubierto por `tests/Feature/Commitments/CommitmentOverdueTest.php`
(la prueba se escribió primero y fallaba con 404).

**Regla general:** en un mismo prefijo, declarar siempre las rutas literales
antes de las paramétricas.

## Orden de middleware (Spec 0004)

`bootstrap/app.php` declara `$middleware->priority([...])`. **No es cosmético:**
sin esa lista, `SubstituteBindings` (grupo `api`) se resolvía antes que
`jwt.auth`/`tenant`, el binding implícito ocurría sin `current_tenant_id` en el
contenedor, `TenantScope` no filtraba y `GET/PUT/DELETE /<recurso>/{id}` devolvía
datos de **otro tenant**. Orden garantizado hoy en una ruta de tenant:

```
throttle → jwt.auth → tenant → tenant.active → SubstituteBindings → resto
```

Con el tenant ya fijado, el `firstOrFail` del binding responde **404** ante un id
ajeno. Fijado por `tests/Feature/Middleware/MiddlewarePriorityTest.php`, que
además comprueba que las rutas públicas y las de super admin siguen bien.

Al tocar la cadena de middleware:

- La lista de prioridad **reemplaza** la de Laravel; si actualizas el framework,
  compárala con `Illuminate\Foundation\Http\Kernel::$middlewarePriority`.
- Solo se reordenan entre sí los middleware que aparecen en la lista; el resto
  conserva su posición.
- `tymon/jwt-auth` **re-registra el alias `jwt.auth`** en el `boot()` de su
  service provider, o sea después de `bootstrap/app.php`: en runtime apunta a
  `Tymon\JWTAuth\Http\Middleware\Authenticate`, no a `App\Http\Middleware\JwtMiddleware`
  (ese nunca corre). La lista incluye ambas clases para que el orden sea correcto
  apunte a donde apunte el alias.

## Pruebas de caracterización (comportamiento defectuoso conocido)

Las pruebas con prefijo `test_caracteriza_*` fijan comportamiento **defectuoso**
que queda fuera del alcance de la spec que las escribió; fallarán —a propósito—
cuando el bug se corrija.

1. ~~**Fuga cross-tenant por binding implícito**~~ — **RESUELTA por la Spec
   0004** (ver orden de middleware arriba). La prueba dejó de ser caracterización
   y es regresión de aislamiento: `tests/Feature/TenantIsolationTest.php` exige
   404 en GET/PUT/DELETE cross-tenant sobre meetings, commitments y voters.
2. **Permisos no aplicados en el backend** — `routes/api.php` no usa el
   middleware `permission:` en ninguna ruta; el permiso solo lo comprueba el
   frontend. Un usuario sin `view_commitments` recibe 200. → Spec 0005
   (enforcement de permisos).
   `tests/Feature/Authorization/PermissionEnforcementCharacterizationTest.php`.
