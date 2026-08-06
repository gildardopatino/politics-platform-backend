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

`TenantFactory` (con `expired()`), `MeetingFactory` y `CommitmentFactory` (ambas
con `forTenant()`; `CommitmentFactory` además `overdue()` y `completed()`), más
`UserFactory` ampliada con `forTenant()` y `superAdmin()`.

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

## Pruebas de caracterización (comportamiento defectuoso conocido)

Dos pruebas documentan bugs que **quedan fuera** del alcance de la Spec 0001.
Están marcadas con el prefijo `test_caracterizacion_*` y fallarán —a propósito—
cuando se corrijan:

1. **Fuga cross-tenant por binding implícito** — `SubstituteBindings` viene del
   grupo `api` y corre antes de `jwt.auth`/`tenant`, así que al resolver
   `{meeting}` todavía no existe `current_tenant_id` y `TenantScope` no filtra:
   `GET /meetings/{id}` devuelve reuniones de otro tenant. Afecta a todo recurso
   con binding implícito. → Spec 0004 (fix fuga cross-tenant).
   `tests/Feature/TenantIsolationTest.php`.
2. **Permisos no aplicados en el backend** — `routes/api.php` no usa el
   middleware `permission:` en ninguna ruta; el permiso solo lo comprueba el
   frontend. Un usuario sin `view_commitments` recibe 200. → Spec 0005
   (enforcement de permisos).
   `tests/Feature/Authorization/PermissionEnforcementCharacterizationTest.php`.
