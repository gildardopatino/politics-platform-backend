# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Overview

Multitenant RESTful API for managing Colombian political campaigns. Laravel 12, PHP 8.2, PostgreSQL, Redis, JWT auth. Each candidate is a tenant. All API surface lives under `/api/v1/` in `routes/api.php`. Docs for individual feature areas are in `docs/` (one markdown file per subsystem — consult these before changing a subsystem's API contract).

## Commands

```bash
# Run all tests (clears config first)
composer test
# or
php artisan test

# Single test file / filter
php artisan test tests/Feature/SomeTest.php
php artisan test --filter=test_method_name

# Full dev loop: serve + queue worker + log tail (pail) + vite, concurrently
composer dev

# Lint / format (Laravel Pint)
./vendor/bin/pint
./vendor/bin/pint --test   # check only, no writes

# Queue worker (campaigns, reminders, QR, social sync run async)
php artisan queue:work        # or queue:listen --tries=1

# Fresh DB with seed data
php artisan migrate:fresh --seed

# Docker (Postgres + Redis + Nginx)
docker-compose up -d
```

Tests run on SQLite `:memory:` with sync queue and array cache/mail (see `phpunit.xml`). Production/dev runtime uses PostgreSQL (`pgsql`), Redis for queue/cache/session, and SMS provider `log` by default. Only `tests/Unit/ExampleTest.php` and `tests/Feature/ExampleTest.php` scaffolds exist — there is no real test suite yet.

## Multitenancy — the core architecture

This is the single most important thing to understand. Tenant isolation is automatic and container-driven:

1. `App\Http\Middleware\EnsureTenant` (alias `tenant`) reads the authenticated user and binds `current_tenant_id` into the service container. A super admin (`is_super_admin === true` and `tenant_id === null`) binds `null`, meaning **no filtering** — they see all tenants' data.
2. `App\Scopes\TenantScope` is a global Eloquent scope that reads `current_tenant_id` from the container and adds `WHERE tenant_id = ?`. If the binding is absent or `null`, no filter applies.
3. `App\Traits\HasTenant` registers `TenantScope` on a model AND auto-fills `tenant_id` from the authenticated user on `creating`. Apply this trait to any tenant-owned model.

Consequences when writing code:
- Models using `HasTenant` are auto-scoped on every query and auto-stamped on create — do not manually set `tenant_id` unless creating cross-tenant (super admin) records.
- To query across tenants (super-admin tooling, lookups by ID like in `EnsureTenant` itself), use `Model::withoutGlobalScope(TenantScope::class)`.
- In contexts with no HTTP request / no container binding (jobs, console commands, seeders), the scope does NOT filter. Be explicit about `tenant_id` there.

## Auth & middleware chain

JWT via `tymon/jwt-auth`; the `api` guard uses the `jwt` driver. `User implements JWTSubject`. Middleware aliases (registered in `bootstrap/app.php`):

- `jwt.auth` → `JwtMiddleware` — authenticates the request.
- `superadmin` → `CheckSuperAdmin` — global super-admin-only routes (tenant CRUD, cross-tenant WhatsApp instances, messaging credit approval).
- `tenant` → `EnsureTenant` — establishes tenant context (see above).
- `tenant.active` → `CheckTenantExpiration` — blocks expired tenants.

Route structure: public routes first (login, password reset, public meeting check-in by QR, MercadoPago + registraduria webhooks, landing page public reads, voting-place image gen). Then `jwt.auth` group wrapping a `superadmin` subgroup and a `['tenant','tenant.active']` subgroup that holds the bulk of the app.

## Layout & layered conventions

Standard Laravel layering, namespaced by API version:
- Controllers: `app/Http/Controllers/Api/V1/` (plus `Landing/` and `Settings/` subdirs).
- FormRequests: `app/Http/Requests/Api/V1/<Domain>/`.
- API Resources: `app/Http/Resources/Api/V1/`.
- Business logic in `app/Services/` (e.g. `CampaignService`, `QRCodeService`, `AttendeeHierarchyService`, `WasabiStorageService`). SMS uses an interface adapter: `app/Services/SMS/SMSInterface` with `TwilioSMS` / `LogSMS` implementations selected by `SMS_PROVIDER`.
- Async work in `app/Jobs/` (`Campaigns/SendCampaignJob`, `Meetings/GenerateQRCodeJob`, reminder + social-sync jobs).
- Roles/permissions via `spatie/laravel-permission`; auditing via `owen-it/laravel-auditing` + `spatie/laravel-activitylog` (models implement `Auditable`, use `LogsActivity`).

## External integrations

- **MercadoPago** (`mercadopago/dx-php`) — messaging-credit purchases; webhook is public, payment routes authenticated.
- **WhatsApp via Evolution API** — `WhatsAppNotificationService`, per-tenant `TenantWhatsAppInstance`. (Migrated off an older provider — see `docs/WHATSAPP_*`.)
- **Wasabi / S3** (`league/flysystem-aws-s3-v3`) — tenant file/image storage via `WasabiStorageService`; QR codes, logos, voting-place images.
- **n8n webhooks** — outbound for transactional email / password reset; inbound registraduria voter-sync webhooks (public routes).
- **Social media sync** — `SocialMediaSyncService` + `SyncSocialMediaJob` pull Twitter/Facebook/Instagram/YouTube feeds for landing pages.
- **Sentry** — error reporting wired in `bootstrap/app.php`.

## Notes

- Root-level `test-*.php` and `check-*.php` files are ad-hoc manual integration scripts (MercadoPago, Wasabi, Evolution API, voting API), not part of the PHPUnit suite. Run with `php <file>.php`.
- `README.md` is largely stale Laravel boilerplate plus an early "to-do" plan; the codebase has progressed well past it. Trust the code and `docs/` over the README.
- App timezone and locale: see `docs/TIMEZONE_STANDARD.md`. Geography is Colombia-specific: departments → municipalities → communes → barrios, and corregimientos → veredas.
