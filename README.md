# Politics Platform Backend - Laravel 12 API Multitenant<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>



API RESTful multitenant para gestión de campañas políticas construida con Laravel 12, PostgreSQL, Redis y JWT.<p align="center">

<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>

## 🚀 Estado del Proyecto<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>

<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>

### ✅ Completado<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>

- [x] Proyecto Laravel 12 instalado y configurado</p>

- [x] Dependencias instaladas (JWT, Spatie Permission, QR Codes, etc.)

- [x] Docker Compose configurado (Postgres, Redis, Nginx)## About Laravel

- [x] Todas las migrations creadas y estructuradas

- [x] Configuración de entorno (.env.example)Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [x] JWT configurado con secret

- [x] Trait HasTenant y TenantScope implementados- [Simple, fast routing engine](https://laravel.com/docs/routing).

- [x] Modelo User completo con JWT y multitenancy- [Powerful dependency injection container](https://laravel.com/docs/container).

- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.

### 🚧 Por Completar- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).

- [ ] Actualizar modelos restantes (Tenant, Meeting, Campaign, etc.)- Database agnostic [schema migrations](https://laravel.com/docs/migrations).

- [ ] Crear Controllers con lógica de negocio- [Robust background job processing](https://laravel.com/docs/queues).

- [ ] Implementar FormRequests con validaciones- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

- [ ] Crear API Resources

- [ ] Implementar Middlewares (EnsureTenant, CheckSuperAdmin)Laravel is accessible, powerful, and provides tools required for large, robust applications.

- [ ] Crear Policies

- [ ] Implementar Seeders## Learning Laravel

- [ ] Crear Jobs para campañas

- [ ] Configurar rutas en `/api/v1/`Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

- [ ] Tests Feature y Unit

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## 📋 Instalación Rápida

## Laravel Sponsors

```bash

# 1. Copiar variables de entornoWe would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

cp .env.example .env

### Premium Partners

# 2. Editar .env con tus credenciales

# DB_CONNECTION=pgsql, etc.- **[Vehikl](https://vehikl.com)**

- **[Tighten Co.](https://tighten.co)**

# 3. Instalar dependencias- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**

composer install- **[64 Robots](https://64robots.com)**

- **[Curotec](https://www.curotec.com/services/technologies/laravel)**

# 4. Generar claves- **[DevSquad](https://devsquad.com/hire-laravel-developers)**

php artisan key:generate- **[Redberry](https://redberry.international/laravel-development)**

php artisan jwt:secret  # Ya ejecutado- **[Active Logic](https://activelogic.com)**



# 5. Ejecutar con Docker## Contributing

docker-compose up -d

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

# 6. Migrations

docker-compose exec app php artisan migrate --seed## Code of Conduct

```

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## 🔧 Comandos para Continuar el Desarrollo

## Security Vulnerabilities

### 1. Actualizar Modelos Restantes

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

Los modelos ya fueron creados, necesitan ser actualizados con relaciones y lógica:

## License

```bash

# Archivos a actualizar:The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

# app/Models/Tenant.php
# app/Models/Meeting.php
# app/Models/MeetingTemplate.php
# app/Models/MeetingAttendee.php
# app/Models/Campaign.php
# app/Models/CampaignRecipient.php
# app/Models/Commitment.php
# app/Models/Priority.php
# app/Models/ResourceAllocation.php
# app/Models/Department.php
# app/Models/City.php
# app/Models/Commune.php
# app/Models/Barrio.php
```

### 2. Crear Controllers

```bash
php artisan make:controller Api/V1/AuthController
php artisan make:controller Api/V1/TenantController --api --model=Tenant
php artisan make:controller Api/V1/UserController --api --model=User
php artisan make:controller Api/V1/MeetingController --api --model=Meeting
php artisan make:controller Api/V1/MeetingTemplateController --api --model=MeetingTemplate
php artisan make:controller Api/V1/MeetingAttendeeController --api
php artisan make:controller Api/V1/CampaignController --api --model=Campaign
php artisan make:controller Api/V1/CommitmentController --api --model=Commitment
php artisan make:controller Api/V1/ResourceAllocationController --api --model=ResourceAllocation
php artisan make:controller Api/V1/GeographyController --api
```

### 3. Crear Requests (Validaciones)

```bash
php artisan make:request Api/V1/Auth/LoginRequest
php artisan make:request Api/V1/Auth/RegisterRequest
php artisan make:request Api/V1/Tenant/StoreTenantRequest
php artisan make:request Api/V1/Tenant/UpdateTenantRequest
php artisan make:request Api/V1/User/StoreUserRequest
php artisan make:request Api/V1/User/UpdateUserRequest
php artisan make:request Api/V1/Meeting/StoreMeetingRequest
php artisan make:request Api/V1/Meeting/UpdateMeetingRequest
php artisan make:request Api/V1/Meeting/StoreAttendeeRequest
php artisan make:request Api/V1/Campaign/StoreCampaignRequest
php artisan make:request Api/V1/Campaign/SendCampaignRequest
```

### 4. Crear Resources (Transformadores)

```bash
php artisan make:resource Api/V1/UserResource
php artisan make:resource Api/V1/TenantResource
php artisan make:resource Api/V1/MeetingResource
php artisan make:resource Api/V1/MeetingTemplateResource
php artisan make:resource Api/V1/CampaignResource
php artisan make:resource Api/V1/CommitmentResource
php artisan make:resource Api/V1/ResourceAllocationResource
```

### 5. Crear Middlewares

```bash
php artisan make:middleware EnsureTenant
php artisan make:middleware CheckSuperAdmin
```

### 6. Crear Policies

```bash
php artisan make:policy TenantPolicy --model=Tenant
php artisan make:policy MeetingPolicy --model=Meeting
php artisan make:policy CampaignPolicy --model=Campaign
php artisan make:policy CommitmentPolicy --model=Commitment
```

### 7. Crear Seeders

```bash
php artisan make:seeder SuperAdminSeeder
php artisan make:seeder RolesAndPermissionsSeeder
php artisan make:seeder GeographySeeder
php artisan make:seeder PrioritySeeder
php artisan make:seeder DemoDataSeeder
```

### 8. Crear Jobs

```bash
php artisan make:job Campaigns/SendCampaignJob
php artisan make:job Meetings/GenerateQRCodeJob
```

### 9. Crear Services

```bash
mkdir -p app/Services/SMS
# Crear manualmente:
# app/Services/CampaignService.php
# app/Services/QRCodeService.php
# app/Services/SMS/SMSInterface.php
# app/Services/SMS/TwilioSMS.php
# app/Services/SMS/LogSMS.php
```

### 10. Crear Tests

```bash
php artisan make:test Api/V1/Auth/AuthTest
php artisan make:test Api/V1/TenantTest
php artisan make:test Api/V1/MeetingTest
php artisan make:test Api/V1/CampaignTest
```

## 📁 Estructura de Archivos Creados

```
platform-politics-backend/
├── app/
│   ├── Models/
│   │   ├── User.php ✅ (Completo con JWT y multitenancy)
│   │   ├── Tenant.php ⚠️ (Creado, falta contenido)
│   │   ├── Meeting.php ⚠️
│   │   ├── Campaign.php ⚠️
│   │   └── ... (otros modelos creados)
│   ├── Traits/
│   │   └── HasTenant.php ✅
│   └── Scopes/
│       └── TenantScope.php ✅
├── database/
│   └── migrations/
│       ├── 2025_10_29_154049_create_tenants_table.php ✅
│       ├── 0001_01_01_000000_create_users_table.php ✅
│       ├── 2025_10_29_154229_create_geography_tables.php ✅
│       ├── 2025_10_29_154229_create_meeting_templates_table.php ✅
│       ├── 2025_10_29_154229_create_meetings_table.php ✅
│       ├── 2025_10_29_154230_create_meeting_attendees_table.php ✅
│       ├── 2025_10_29_154230_create_priorities_table.php ✅
│       ├── 2025_10_29_154230_create_commitments_table.php ✅
│       ├── 2025_10_29_154231_create_resource_allocations_table.php ✅
│       ├── 2025_10_29_154231_create_campaigns_table.php ✅
│       ├── 2025_10_29_154231_create_campaign_recipients_table.php ✅
│       ├── 2025_10_29_153902_create_permission_tables.php ✅
│       └── 2025_10_29_153903_create_activity_log_table.php ✅
├── docker/
│   ├── nginx/
│   │   └── conf.d/
│   │       └── default.conf ✅
│   └── php/
│       └── local.ini ✅
├── docker-compose.yml ✅
├── Dockerfile ✅
└── .env.example ✅ (Configurado para Postgres, Redis, JWT)
```

## 🔑 Configuración Actual

### Base de Datos (PostgreSQL)
```env
DB_CONNECTION=pgsql
DB_HOST=postgres
DB_PORT=5432
DB_DATABASE=politics_platform
DB_USERNAME=postgres
DB_PASSWORD=secret
```

### Redis
```env
REDIS_CLIENT=predis
REDIS_HOST=redis
REDIS_PORT=6379
QUEUE_CONNECTION=redis
CACHE_STORE=redis
SESSION_DRIVER=redis
```

### JWT
```env
JWT_SECRET=OZ52GSjio0BaxUPy40eZIVsvAgFlamnjDYhuSWn1zNypuewzWPT4FN0Hx6rrnJmn
JWT_TTL=60
JWT_REFRESH_TTL=20160
```

### Superadmin por Defecto
```env
SUPERADMIN_NAME="Super Administrator"
SUPERADMIN_EMAIL=admin@politics-platform.com
SUPERADMIN_PASSWORD=SuperAdmin2025!
```

## 📊 Base de Datos - Esquema

### Tablas Principales

1. **tenants** - Candidatos (cada uno es un tenant)
   - slug, nombre, tipo_cargo, identificacion, email, phone, metadata

2. **users** - Usuarios multitenant
   - tenant_id, name, email, phone, cedula, password
   - is_team_leader, is_super_admin, reports_to, created_by_user_id

3. **meetings** - Reuniones con QR
   - tenant_id, title, starts_at, lugar_nombre, direccion, lat/long
   - qr_code (único), planner_user_id, template_id, status

4. **meeting_attendees** - Asistentes
   - meeting_id, cedula, nombres, apellidos, direccion, telefono
   - extra_fields (JSON dinámico), checked_in, checked_in_at

5. **campaigns** - Campañas de mensajería
   - tenant_id, title, message, channel (sms/email/both)
   - status, scheduled_at, total_recipients, sent_count

6. **campaign_recipients** - Destinatarios
   - campaign_id, recipient_type, recipient_value, status, sent_at

7. **geography** - Geografía colombiana
   - departments, cities, communes, barrios, corregimientos, veredas

## 🎯 Reglas de Negocio Clave

1. **Superadmin Global**
   - Solo UNO en todo el sistema
   - `tenant_id = NULL` y `is_super_admin = true`
   - Puede crear/gestionar todos los tenants

2. **Tenant Creation**
   - Al crear un tenant, automáticamente crear su usuario superadmin
   - Este usuario tendrá `tenant_id = X` y `is_super_admin = true`

3. **Multitenancy**
   - Trait `HasTenant` + `TenantScope` filtran automáticamente por `tenant_id`
   - Middleware `EnsureTenant` valida acceso

4. **Jerarquía de Usuarios**
   - Todos deben reportar a alguien (`reports_to`)
   - Excepto el tenant superadmin

5. **Meetings con QR**
   - Al crear meeting, generar QR único
   - QR apunta a endpoint de check-in público

6. **Campañas Asíncronas**
   - Envíos en lotes vía Jobs
   - Rate limiting configurableconfigurado

## 📝 Próximos Pasos Detallados

### Fase 1: Modelos y Relaciones (1-2 horas)
1. Actualizar `Tenant.php` con fillable, casts, relaciones
2. Actualizar `Meeting.php` con todas las relaciones
3. Actualizar `Campaign.php`
4. Actualizar modelos de geografía

### Fase 2: Controllers Básicos (2-3 horas)
1. `AuthController`: login, logout, refresh, me
2. `TenantController`: CRUD solo para superadmin
3. `UserController`: CRUD con validación de tenant
4. `MeetingController`: CRUD + QR generation
5. `CampaignController`: CRUD + send logic

### Fase 3: Validaciones y Resources (1-2 horas)
1. Crear todos los FormRequests
2. Crear API Resources para transformar datos
3. Validaciones específicas por rol/tenant

### Fase 4: Seguridad y Autorización (2-3 horas)
1. Middleware `EnsureTenant`
2. Middleware `CheckSuperAdmin`
3. Policies para cada modelo
4. Configurar gates en `AuthServiceProvider`

### Fase 5: Seeders y Datos de Prueba (1-2 horas)
1. SuperAdminSeeder
2. RolesAndPermissionsSeeder
3. GeographySeeder (departamentos/ciudades Colombia)
4. DemoDataSeeder (tenants, users, meetings de prueba)

### Fase 6: Jobs y Servicios (2-3 horas)
1. `SendCampaignJob` con rate limiting
2. `GenerateQRCodeJob`
3. `CampaignService` con lógica de filtros
4. `QRCodeService` para generación
5. SMS adapters (Twilio, Log)

### Fase 7: Rutas API (30 min)
1. Configurar rutas en `routes/api.php`
2. Agrupar por versión `/api/v1/`
3. Aplicar middlewares

### Fase 8: Tests (3-4 horas)
1. Tests de autenticación
2. Tests de multitenancy
3. Tests de permisos
4. Tests de meetings y QR
5. Tests de campaigns

### Fase 9: Documentación (1 hora)
1. Postman Collection
2. OpenAPI/Swagger
3. README de endpoints

### Fase 10: Optimización (1-2 horas)
1. Eager loading en queries
2. Índices de BD
3. Cache de consultas frecuentes
4. Configurar Horizon para queues

## 🚀 Para Ejecutar el Proyecto

```bash
# 1. Levantar servicios
docker-compose up -d

# 2. Ver logs
docker-compose logs -f app

# 3. Ejecutar comandos dentro del contenedor
docker-compose exec app php artisan migrate:fresh --seed

# 4. Acceder a la API
# http://localhost:8000/api/v1/

# 5. Ver cola de trabajos
docker-compose exec app php artisan queue:work

# 6. Ejecutar tests
docker-compose exec app php artisan test
```

## 📚 Recursos

- [Laravel 12 Docs](https://laravel.com/docs/12.x)
- [JWT Auth](https://jwt-auth.readthedocs.io/)
- [Spatie Permission](https://spatie.be/docs/laravel-permission)
- [Simple QR Code](https://www.simplesoftware.io/#/docs/simple-qrcode)

---

**Nota**: Este README documenta el estado actual del proyecto Laravel 12 multitenant. Todas las bases están configuradas correctamente usando los comandos nativos de Laravel. El proyecto está listo para continuar con el desarrollo de la lógica de negocio.
