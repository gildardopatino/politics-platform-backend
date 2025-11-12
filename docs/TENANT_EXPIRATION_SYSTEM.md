# Sistema de Expiración de Tenants

**Fecha de Implementación:** 12 de Noviembre, 2025  
**Versión:** 1.0

---

## Resumen

Sistema que permite al superadministrador controlar el acceso temporal de los tenants mediante fechas de inicio y expiración. Los usuarios de un tenant expirado o no iniciado no pueden acceder al sistema hasta que se comuniquen con el superadmin.

---

## Componentes Implementados

### 1. Base de Datos

**Migración:** `2025_11_12_124506_add_start_and_expiration_dates_to_tenants_table.php`

```sql
-- Campos agregados a la tabla tenants:
ALTER TABLE tenants ADD COLUMN start_date TIMESTAMP NULL;
ALTER TABLE tenants ADD COLUMN expiration_date TIMESTAMP NULL;
```

### 2. Modelo Tenant

**Archivo:** `app/Models/Tenant.php`

**Campos agregados al `$fillable`:**
- `start_date`
- `expiration_date`

**Casts agregados:**
```php
'start_date' => 'datetime',
'expiration_date' => 'datetime',
```

**Métodos nuevos:**

#### `isActive(): bool`
Verifica si el tenant está activo (entre start_date y expiration_date, o sin restricciones).

```php
public function isActive(): bool
{
    if (!$this->expiration_date) {
        return true; // Sin expiración = siempre activo
    }
    
    if (!$this->start_date) {
        return now()->lte($this->expiration_date);
    }
    
    return now()->gte($this->start_date) && now()->lte($this->expiration_date);
}
```

#### `isExpired(): bool`
Verifica si el tenant ha expirado.

```php
public function isExpired(): bool
{
    if (!$this->expiration_date) {
        return false;
    }
    
    return now()->gt($this->expiration_date);
}
```

#### `isNotStarted(): bool`
Verifica si el tenant aún no ha iniciado.

```php
public function isNotStarted(): bool
{
    if (!$this->start_date) {
        return false;
    }
    
    return now()->lt($this->start_date);
}
```

#### `daysUntilExpiration(): ?int`
Retorna los días hasta la expiración (negativo si ya expiró, null si no tiene fecha).

```php
public function daysUntilExpiration(): ?int
{
    if (!$this->expiration_date) {
        return null;
    }
    
    return now()->diffInDays($this->expiration_date, false);
}
```

---

### 3. Middleware CheckTenantExpiration

**Archivo:** `app/Http/Middleware/CheckTenantExpiration.php`

**Funcionalidad:**
- Verifica si el usuario autenticado pertenece a un tenant
- Si el tenant no ha iniciado → devuelve 403 con mensaje
- Si el tenant ha expirado → devuelve 403 con mensaje
- Si el usuario es superadmin (tenant_id = null) → permite acceso
- Los mensajes incluyen el email de contacto del superadmin

**Respuesta cuando el tenant no ha iniciado:**
```json
{
  "message": "Su cuenta aún no está activa. Por favor, comuníquese con el administrador del sistema al correo admin@appcore.com.co",
  "error": "TENANT_NOT_STARTED",
  "admin_email": "admin@appcore.com.co",
  "start_date": "2025-11-15T00:00:00.000000Z"
}
```

**Respuesta cuando el tenant ha expirado:**
```json
{
  "message": "Su cuenta ha expirado. Por favor, comuníquese con el administrador del sistema al correo admin@appcore.com.co",
  "error": "TENANT_EXPIRED",
  "admin_email": "admin@appcore.com.co",
  "expiration_date": "2025-11-01T23:59:59.000000Z"
}
```

---

### 4. Variables de Entorno

**Archivo:** `.env` y `.env.example`

**Variable agregada:**
```env
# Admin contact email for tenant support
ADMIN_EMAIL=admin@appcore.com.co
```

Esta variable se usa en el middleware para mostrar el email de contacto en los mensajes de error.

---

### 5. Request Validators

#### StoreTenantRequest
**Archivo:** `app/Http/Requests/Api/V1/Tenant/StoreTenantRequest.php`

**Reglas agregadas:**
```php
'start_date' => 'nullable|date',
'expiration_date' => 'nullable|date|after:start_date',
'initial_emails' => 'nullable|integer|min:0',
'initial_whatsapp' => 'nullable|integer|min:0',
```

#### UpdateTenantRequest
**Archivo:** `app/Http/Requests/Api/V1/Tenant/UpdateTenantRequest.php`

**Reglas agregadas:**
```php
'start_date' => 'nullable|date',
'expiration_date' => 'nullable|date|after:start_date',
```

---

### 6. TenantResource

**Archivo:** `app/Http/Resources/Api/V1/TenantResource.php`

**Campos agregados al response:**
```php
'start_date' => $this->start_date?->toISOString(),
'expiration_date' => $this->expiration_date?->toISOString(),
'is_active' => $this->isActive(),
'is_expired' => $this->isExpired(),
'is_not_started' => $this->isNotStarted(),
'days_until_expiration' => $this->daysUntilExpiration(),
```

---

### 7. Rutas API

**Archivo:** `routes/api.php`

**Middleware registrado:**
```php
// En bootstrap/app.php
'tenant.active' => \App\Http\Middleware\CheckTenantExpiration::class,
```

**Aplicado a todas las rutas tenant-scoped:**
```php
Route::middleware(['tenant', 'tenant.active'])->group(function () {
    // Todas las rutas de tenant verifican expiración
});
```

**Rutas excluidas de la verificación:**
- `/login` (pública)
- `/register` (superadmin)
- `/tenants/*` (superadmin, para poder administrar tenants expirados)
- Landing pages públicas
- Webhooks públicos (MercadoPago)

---

## Flujo de Uso

### 1. Crear Tenant con Fechas (Super Admin)

```bash
POST /api/v1/tenants
Authorization: Bearer {super_admin_token}

{
  "slug": "candidato-2025",
  "nombre": "Juan Pérez",
  "tipo_cargo": "Alcalde",
  "identificacion": "123456789",
  "start_date": "2025-11-15T00:00:00",
  "expiration_date": "2026-11-15T23:59:59",
  "initial_emails": 1000,
  "initial_whatsapp": 500
}
```

### 2. Actualizar Fechas de Tenant (Super Admin)

```bash
PUT /api/v1/tenants/{id}
Authorization: Bearer {super_admin_token}

{
  "start_date": "2025-11-01T00:00:00",
  "expiration_date": "2027-11-01T23:59:59"
}
```

### 3. Verificar Estado del Tenant

```bash
GET /api/v1/tenants/{id}
Authorization: Bearer {super_admin_token}

# Response incluye:
{
  "data": {
    "id": 1,
    "nombre": "Juan Pérez",
    "start_date": "2025-11-15T00:00:00.000000Z",
    "expiration_date": "2026-11-15T23:59:59.000000Z",
    "is_active": true,
    "is_expired": false,
    "is_not_started": false,
    "days_until_expiration": 365
  }
}
```

### 4. Usuario Intenta Acceder a Sistema con Tenant Expirado

```bash
GET /api/v1/meetings
Authorization: Bearer {tenant_user_token}

# Response: 403 Forbidden
{
  "message": "Su cuenta ha expirado. Por favor, comuníquese con el administrador del sistema al correo admin@appcore.com.co",
  "error": "TENANT_EXPIRED",
  "admin_email": "admin@appcore.com.co",
  "expiration_date": "2025-11-01T23:59:59.000000Z"
}
```

---

## Casos de Uso

### Caso 1: Tenant Sin Restricciones
```php
start_date: null
expiration_date: null

// Resultado: Siempre activo, sin expiración
is_active: true
is_expired: false
is_not_started: false
days_until_expiration: null
```

### Caso 2: Tenant con Solo Fecha de Expiración
```php
start_date: null
expiration_date: "2025-12-31T23:59:59"

// Si hoy es 2025-11-12:
is_active: true
is_expired: false
is_not_started: false
days_until_expiration: 49
```

### Caso 3: Tenant con Fechas de Inicio y Expiración
```php
start_date: "2025-11-15T00:00:00"
expiration_date: "2026-11-15T23:59:59"

// Si hoy es 2025-11-12:
is_active: false
is_expired: false
is_not_started: true
days_until_expiration: 368
```

### Caso 4: Tenant Expirado
```php
start_date: "2025-01-01T00:00:00"
expiration_date: "2025-10-31T23:59:59"

// Si hoy es 2025-11-12:
is_active: false
is_expired: true
is_not_started: false
days_until_expiration: -12
```

---

## Excepciones y Consideraciones

### ✅ Excepciones al Bloqueo

1. **Super Admin**: Usuarios con `tenant_id = null` nunca son bloqueados
2. **Rutas Públicas**: Login, landing pages, webhooks no verifican expiración
3. **Gestión de Tenants**: Super admin puede gestionar tenants expirados

### ⚠️ Consideraciones Importantes

1. **Timezone**: Las fechas se almacenan en UTC y se comparan con `now()` (timezone de la aplicación: America/Bogota)
2. **Precisión**: Las comparaciones incluyen hora, minuto y segundo
3. **Nullable**: Si ambas fechas son `null`, el tenant nunca expira
4. **Validación**: `expiration_date` debe ser posterior a `start_date`
5. **Créditos Iniciales**: Se pueden asignar al crear el tenant con `initial_emails` e `initial_whatsapp`

---

## Mensajes de Error

### TENANT_NOT_STARTED (403)
```json
{
  "message": "Su cuenta aún no está activa. Por favor, comuníquese con el administrador del sistema al correo admin@appcore.com.co",
  "error": "TENANT_NOT_STARTED",
  "admin_email": "admin@appcore.com.co",
  "start_date": "2025-11-15T00:00:00.000000Z"
}
```

### TENANT_EXPIRED (403)
```json
{
  "message": "Su cuenta ha expirado. Por favor, comuníquese con el administrador del sistema al correo admin@appcore.com.co",
  "error": "TENANT_EXPIRED",
  "admin_email": "admin@appcore.com.co",
  "expiration_date": "2025-10-31T23:59:59.000000Z"
}
```

### TENANT_NOT_FOUND (404)
```json
{
  "message": "Tenant not found.",
  "error": "TENANT_NOT_FOUND"
}
```

---

## Testing

### Test Manual en Postman/Insomnia

1. **Crear tenant con fecha futura:**
   ```json
   POST /api/v1/tenants
   {
     "start_date": "2025-12-01T00:00:00",
     "expiration_date": "2026-12-01T23:59:59",
     ...
   }
   ```

2. **Intentar acceder con usuario del tenant:**
   ```bash
   GET /api/v1/meetings
   # Debería recibir error TENANT_NOT_STARTED
   ```

3. **Actualizar fecha de inicio al pasado:**
   ```json
   PUT /api/v1/tenants/{id}
   {
     "start_date": "2025-01-01T00:00:00"
   }
   ```

4. **Intentar acceder nuevamente:**
   ```bash
   GET /api/v1/meetings
   # Ahora debería permitir acceso
   ```

---

## Migración de Tenants Existentes

Los tenants existentes en la base de datos tendrán `start_date` y `expiration_date` como `null`, lo que significa que **no tienen restricciones** y permanecen activos indefinidamente.

Para agregar fechas a tenants existentes:

```sql
-- Ejemplo: Configurar expiración para un tenant específico
UPDATE tenants 
SET 
  start_date = '2025-01-01 00:00:00',
  expiration_date = '2025-12-31 23:59:59'
WHERE id = 1;
```

O mediante API:

```bash
PUT /api/v1/tenants/1
Authorization: Bearer {super_admin_token}

{
  "start_date": "2025-01-01T00:00:00",
  "expiration_date": "2025-12-31T23:59:59"
}
```

---

## Documentación Actualizada

- ✅ **TENANT_ADMIN_API.md**: Documentación completa actualizada con sistema de expiración
- ✅ **.env.example**: Variable ADMIN_EMAIL agregada
- ✅ **README**: (pendiente de actualizar si existe)

---

**Fin del Documento**
