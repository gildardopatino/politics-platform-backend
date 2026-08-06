# Datos demo

Implementado por la **Spec 0003 — Provisión de tenants y datos demo**.

> ⚠️ Solo para desarrollo. Las contraseñas de abajo están en el repositorio a
> propósito: son datos de prueba. Nunca sembrar `DemoDataSeeder` en producción.

## Cómo poblar la base

```bash
php artisan migrate:fresh --seed     # borra y reconstruye TODO
php artisan db:seed --class=DemoDataSeeder   # solo los datos demo (idempotente)
```

`DatabaseSeeder` corre en este orden: `SuperAdminSeeder` →
`RolesAndPermissionsSeeder` → `GeographySeeder` → `PrioritySeeder` →
`TipoVotanteSeeder` → `DemoDataSeeder`. El orden importa: el seeder demo clona
los roles plantilla y necesita geografía, prioridades y tipos de votante ya
sembrados.

## Super admin global

Sin tenant (`tenant_id = null`), salta todos los permisos. Lo crea
`SuperAdminSeeder` **desde el `.env`**, no está hard-codeado:

| Variable | Valor por defecto |
| --- | --- |
| `SUPERADMIN_NAME` | `Super Administrator` |
| `SUPERADMIN_EMAIL` | `admin@politics-platform.com` |
| `SUPERADMIN_PASSWORD` | (sin valor por defecto) |

Si falta cualquiera de las dos últimas, el seeder se salta a sí mismo y avisa;
en ese caso usa `php artisan superadmin:create`.

## Tenants demo

| Slug | Nombre | Tipo | Créditos email / WhatsApp |
| --- | --- | --- | --- |
| `alcaldia-medellin` | Alcaldía de Medellín | Alcaldia | 5000 / 2000 |
| `gobernacion-antioquia` | Gobernación de Antioquia | Gobernacion | 3000 / 1000 |

Cada tenant recibe su **propio juego de roles** clonado del catálogo global
(`admin`, `coordinator`, `operator`, `viewer` con `tenant_id` del tenant), vía
`TenantProvisioningService` — el mismo servicio que usa `POST /api/v1/tenants`.

## Usuarios

**Contraseña de todos los usuarios demo: `Demo1234!`**

### Alcaldía de Medellín

| Email | Nombre | Rol |
| --- | --- | --- |
| `admin@medellin.demo` | Carlos Rodríguez | admin |
| `coordinador@medellin.demo` | Coordinador Alcaldía de Medellín | coordinator |
| `operador@medellin.demo` | Operador Alcaldía de Medellín | operator |
| `visor@medellin.demo` | Visor Alcaldía de Medellín | viewer |

### Gobernación de Antioquia

| Email | Nombre | Rol |
| --- | --- | --- |
| `admin@antioquia.demo` | Marta Gómez | admin |
| `coordinador@antioquia.demo` | Coordinador Gobernación de Antioquia | coordinator |
| `operador@antioquia.demo` | Operador Gobernación de Antioquia | operator |
| `visor@antioquia.demo` | Visor Gobernación de Antioquia | viewer |

Qué puede hacer cada rol: ver `Permissions::byRole()` en
`app/Support/Permissions.php` y `docs/ROLES_PERMISSIONS_RESUMEN.md`. Recuerda
que hoy el backend **no aplica** permisos por ruta (Spec 0005 pendiente): el
gating es del frontend, así que por API cualquiera de estos usuarios llega a
cualquier endpoint de su tenant.

## Qué datos trae cada tenant

| Módulo | Contenido |
| --- | --- |
| Votantes | 4, con barrio y tipo "Elector" |
| Reuniones | 2 — una celebrada (`completed`) y una futura (`scheduled`) |
| Asistentes | 3 en Medellín (2 con check-in), 2 en Antioquia (1 con check-in) |
| Compromisos | 3 — uno **vencido** (`pending`), uno `in_progress`, uno `completed` |
| Campañas | 1 en `draft`, canal WhatsApp |
| Recursos | 4 ítems (mobiliario, vehículo, material, caja menor) |
| Landing | 1 banner, 2 propuestas, 1 evento |
| Mensajería | Créditos inicializados |

El compromiso vencido existe para poder ejercitar
`GET /api/v1/commitments/overdue` con datos reales.

## Determinismo

Todas las fechas se calculan a partir de `DemoDataSeeder::FECHA_BASE`
(`2026-08-01 08:00:00`), no de `now()`: los datos son estables y se pueden
aserir en pruebas. Si cambias esa constante, revisa
`tests/Feature/Tenants/DemoDataSeederTest.php`.

## Notas de implementación

- **`tenant_id` explícito en cada `create`.** En un seeder no hay usuario
  autenticado, así que el trait `HasTenant` no autorrellena `tenant_id` y los
  modelos con esa columna NOT NULL fallan — era lo que rompía
  `migrate:fresh --seed`. Además el seeder enlaza `current_tenant_id` en el
  contenedor por bloque de tenant y lo suelta al terminar.
- **El seeder es idempotente** (`firstOrCreate` por claves naturales), así que se
  puede re-ejecutar sin duplicar.
- **Roles por tenant, no globales.** Es el motivo de la spec: antes el seeder
  asignaba las plantillas globales a usuarios de tenant mientras `POST /tenants`
  clonaba por tenant. Ahora ambos caminos pasan por
  `TenantProvisioningService`.
