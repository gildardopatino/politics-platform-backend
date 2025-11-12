# Control de Expiración de Tenant en el Login

## Descripción

Al hacer login, el backend ahora incluye información sobre el estado de expiración del tenant del usuario. Esto permite al frontend validar y controlar el acceso antes de permitir que el usuario ingrese al sistema.

## Respuesta del Login

### Usuario con Tenant (Candidato/Administrador/Usuario)

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "bearer",
  "expires_in": 3600,
  "expires_at": "2025-10-29T20:00:00.000000Z",
  "refresh_expires_in": 1209600,
  "refresh_expires_at": "2025-11-12T19:00:00.000000Z",
  "user": {
    "id": 2,
    "name": "Gildardo Patiño",
    "email": "gildardo@gmail.com",
    "tenant_id": 1,
    ...
  },
  "tenant_status": {
    "start_date": "2025-11-04T14:17:00.000000Z",
    "expiration_date": "2025-11-11T14:17:00.000000Z",
    "is_active": true,
    "is_expired": false,
    "is_not_started": false,
    "days_until_expiration": 5
  }
}
```

### Superadmin (Sin Tenant)

```json
{
  "access_token": "eyJ0eXAiOiJKV1QiLCJhbGc...",
  "token_type": "bearer",
  "expires_in": 3600,
  "expires_at": "2025-10-29T20:00:00.000000Z",
  "refresh_expires_in": 1209600,
  "refresh_expires_at": "2025-11-12T19:00:00.000000Z",
  "user": {
    "id": 1,
    "name": "Super Admin",
    "email": "admin@appcore.com.co",
    "tenant_id": null,
    ...
  }
}
```

**Nota:** El campo `tenant_status` **solo se incluye** cuando el usuario pertenece a un tenant.

## Campos de tenant_status

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `start_date` | string\|null | Fecha/hora ISO 8601 de inicio del tenant. `null` si no está configurada |
| `expiration_date` | string\|null | Fecha/hora ISO 8601 de expiración del tenant. `null` si no está configurada |
| `is_active` | boolean | `true` si el tenant está activo (entre start_date y expiration_date) |
| `is_expired` | boolean | `true` si el tenant ya expiró (fecha actual > expiration_date) |
| `is_not_started` | boolean | `true` si el tenant aún no ha iniciado (fecha actual < start_date) |
| `days_until_expiration` | number\|null | Días hasta la expiración. Negativo si ya expiró. `null` si no hay fecha de expiración |

## Implementación en el Frontend

### 1. Validar Estado del Tenant

```typescript
interface TenantStatus {
  start_date: string | null;
  expiration_date: string | null;
  is_active: boolean;
  is_expired: boolean;
  is_not_started: boolean;
  days_until_expiration: number | null;
}

interface LoginResponse {
  access_token: string;
  token_type: string;
  expires_in: number;
  expires_at: string;
  refresh_expires_in: number;
  refresh_expires_at: string;
  user: User;
  tenant_status?: TenantStatus; // Solo presente si el usuario tiene tenant
}

async function handleLogin(email: string, password: string) {
  const response = await fetch('/api/v1/login', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ email, password })
  });

  const data: LoginResponse = await response.json();

  // Verificar estado del tenant si existe
  if (data.tenant_status) {
    if (data.tenant_status.is_expired) {
      throw new Error(
        `Tu suscripción expiró el ${formatDate(data.tenant_status.expiration_date)}. ` +
        `Por favor contacta al administrador en ${ADMIN_EMAIL}`
      );
    }

    if (data.tenant_status.is_not_started) {
      throw new Error(
        `Tu suscripción iniciará el ${formatDate(data.tenant_status.start_date)}. ` +
        `Por favor contacta al administrador en ${ADMIN_EMAIL}`
      );
    }

    // Advertencia si está cerca de expirar (menos de 7 días)
    if (data.tenant_status.days_until_expiration !== null && 
        data.tenant_status.days_until_expiration < 7 &&
        data.tenant_status.days_until_expiration > 0) {
      showWarning(
        `Tu suscripción expirará en ${data.tenant_status.days_until_expiration} días. ` +
        `Por favor renueva pronto.`
      );
    }
  }

  // Guardar token y continuar
  localStorage.setItem('token', data.access_token);
  localStorage.setItem('user', JSON.stringify(data.user));
  
  // Guardar tenant_status para referencia futura
  if (data.tenant_status) {
    localStorage.setItem('tenant_status', JSON.stringify(data.tenant_status));
  }

  return data;
}
```

### 2. Mostrar Mensajes de Error

#### Tenant Expirado

```typescript
if (tenantStatus.is_expired) {
  // Mostrar modal o página de error
  return (
    <div className="error-page">
      <h1>Suscripción Expirada</h1>
      <p>
        Tu suscripción expiró el{' '}
        <strong>{formatDate(tenantStatus.expiration_date)}</strong>
      </p>
      <p>
        Por favor contacta al administrador en{' '}
        <a href={`mailto:${ADMIN_EMAIL}`}>{ADMIN_EMAIL}</a>
        {' '}para renovar tu suscripción.
      </p>
    </div>
  );
}
```

#### Tenant No Iniciado

```typescript
if (tenantStatus.is_not_started) {
  return (
    <div className="error-page">
      <h1>Suscripción No Iniciada</h1>
      <p>
        Tu suscripción iniciará el{' '}
        <strong>{formatDate(tenantStatus.start_date)}</strong>
      </p>
      <p>
        Por favor contacta al administrador en{' '}
        <a href={`mailto:${ADMIN_EMAIL}`}>{ADMIN_EMAIL}</a>
        {' '}si necesitas acceder antes.
      </p>
    </div>
  );
}
```

### 3. Advertencia de Próxima Expiración

```typescript
function ExpirationWarning({ tenantStatus }: { tenantStatus: TenantStatus }) {
  if (!tenantStatus.days_until_expiration || 
      tenantStatus.days_until_expiration > 7 ||
      tenantStatus.days_until_expiration < 0) {
    return null;
  }

  return (
    <div className="alert alert-warning">
      <strong>⚠️ Advertencia:</strong> Tu suscripción expirará en{' '}
      <strong>{tenantStatus.days_until_expiration} días</strong>
      {' '}({formatDate(tenantStatus.expiration_date)}).
      Por favor renueva pronto para evitar interrupciones.
    </div>
  );
}
```

## Casos de Uso

### Caso 1: Login Exitoso (Tenant Activo)

**Request:**
```http
POST /api/v1/login
Content-Type: application/json

{
  "email": "gildardo@gmail.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "access_token": "...",
  "user": { ... },
  "tenant_status": {
    "start_date": "2025-11-01T00:00:00.000000Z",
    "expiration_date": "2025-12-01T00:00:00.000000Z",
    "is_active": true,
    "is_expired": false,
    "is_not_started": false,
    "days_until_expiration": 20
  }
}
```

**Frontend:** Permitir acceso normal. Opcional: mostrar días hasta expiración en el dashboard.

---

### Caso 2: Login con Tenant Expirado

**Request:**
```http
POST /api/v1/login
Content-Type: application/json

{
  "email": "gildardo@gmail.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "access_token": "...",
  "user": { ... },
  "tenant_status": {
    "start_date": "2025-10-01T00:00:00.000000Z",
    "expiration_date": "2025-11-10T23:59:59.000000Z",
    "is_active": false,
    "is_expired": true,
    "is_not_started": false,
    "days_until_expiration": -2
  }
}
```

**Frontend:** Mostrar mensaje de error y no permitir acceso. Mostrar contacto del administrador.

---

### Caso 3: Login con Tenant Próximo a Expirar

**Request:**
```http
POST /api/v1/login
Content-Type: application/json

{
  "email": "gildardo@gmail.com",
  "password": "password123"
}
```

**Response:**
```json
{
  "access_token": "...",
  "user": { ... },
  "tenant_status": {
    "start_date": "2025-11-01T00:00:00.000000Z",
    "expiration_date": "2025-11-15T23:59:59.000000Z",
    "is_active": true,
    "is_expired": false,
    "is_not_started": false,
    "days_until_expiration": 3
  }
}
```

**Frontend:** Permitir acceso pero mostrar advertencia prominente sobre la próxima expiración.

---

### Caso 4: Login de Superadmin

**Request:**
```http
POST /api/v1/login
Content-Type: application/json

{
  "email": "admin@appcore.com.co",
  "password": "password123"
}
```

**Response:**
```json
{
  "access_token": "...",
  "user": {
    "id": 1,
    "tenant_id": null,
    ...
  }
}
```

**Nota:** Sin campo `tenant_status` porque el superadmin no pertenece a ningún tenant.

**Frontend:** Permitir acceso sin restricciones. No mostrar advertencias de expiración.

## Configuración

El email del administrador se configura en el archivo `.env`:

```env
ADMIN_EMAIL=admin@appcore.com.co
```

Este email se debe mostrar en los mensajes de error cuando el tenant está expirado o no ha iniciado.

## Notas Importantes

1. **Fechas en hora de Colombia:** Todas las fechas están en zona horaria de Colombia (America/Bogota)
2. **Sin conversión UTC:** Las fechas se guardan y devuelven sin conversión automática a UTC
3. **Formato ISO 8601:** Las fechas se devuelven en formato ISO 8601 con sufijo Z
4. **Superadmin sin restricciones:** El superadmin nunca es bloqueado por expiración de tenant
5. **Middleware CheckTenantExpiration:** Además del login, hay un middleware que valida en cada request
