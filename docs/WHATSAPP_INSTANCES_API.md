# WhatsApp Instances API - Evolution API Integration

## Descripción General

Sistema para gestionar múltiples instancias de WhatsApp por tenant usando Evolution API. Cada tenant puede tener varios números de WhatsApp configurados, cada uno con su propia API key, límite diario de mensajes y seguimiento de uso.

---

## Endpoints

### 1. Listar Instancias WhatsApp del Tenant Actual

**Endpoint:** `GET /api/v1/tenant/whatsapp-instances`

**Autenticación:** Bearer Token (tenant user)

**Query Parameters:**
- `is_active` (boolean, opcional): Filtrar por estado activo/inactivo
- `with_quota` (boolean, opcional): Solo mostrar instancias con cuota disponible
- `per_page` (integer, opcional): Cantidad de resultados por página (default: 15)

**Request:**
```http
GET /api/v1/tenant/whatsapp-instances?is_active=true&with_quota=true
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "data": [
    {
      "id": 1,
      "tenant_id": 5,
      "phone_number": "+573001234567",
      "instance_name": "principal_campana",
      "evolution_api_url": "https://evolution.midominio.com",
      "daily_message_limit": 1000,
      "messages_sent_today": 245,
      "remaining_quota": 755,
      "last_reset_date": "2025-11-17",
      "is_active": true,
      "can_send_messages": true,
      "notes": "Instancia principal para campaña electoral",
      "created_at": "2025-11-17T06:30:00.000000Z",
      "updated_at": "2025-11-17T10:15:00.000000Z"
    },
    {
      "id": 2,
      "tenant_id": 5,
      "phone_number": "+573009876543",
      "instance_name": "secundaria_recordatorios",
      "evolution_api_url": null,
      "daily_message_limit": 500,
      "messages_sent_today": 120,
      "remaining_quota": 380,
      "last_reset_date": "2025-11-17",
      "is_active": true,
      "can_send_messages": true,
      "notes": "Para recordatorios de reuniones y compromisos",
      "created_at": "2025-11-17T07:00:00.000000Z",
      "updated_at": "2025-11-17T09:45:00.000000Z"
    }
  ],
  "meta": {
    "total": 2,
    "current_page": 1,
    "last_page": 1,
    "per_page": 15
  }
}
```

---

### 2. Crear Nueva Instancia WhatsApp

**Endpoint:** `POST /api/v1/tenant/whatsapp-instances`

**Autenticación:** Bearer Token (tenant user)

**Request Body:**
```json
{
  "phone_number": "+573001234567",
  "instance_name": "principal_campana",
  "evolution_api_key": "B6D9F2E8-A3C4-4D5E-9F8A-1B2C3D4E5F6A",
  "evolution_api_url": "https://evolution.midominio.com",
  "daily_message_limit": 1000,
  "is_active": true,
  "notes": "Instancia principal para campaña electoral"
}
```

**Campos:**
- `phone_number` (string, requerido): Número en formato E.164 (ej: +573001234567)
- `instance_name` (string, requerido): Nombre identificador de la instancia
- `evolution_api_key` (string, requerido): API Key de Evolution API
- `evolution_api_url` (string, opcional): URL base de Evolution API (usa default si no se provee)
- `daily_message_limit` (integer, requerido): Límite diario de mensajes (1-100000)
- `is_active` (boolean, opcional): Estado inicial (default: true)
- `notes` (string, opcional): Notas adicionales (máx 1000 caracteres)

**Response 201:**
```json
{
  "data": {
    "id": 1,
    "tenant_id": 5,
    "phone_number": "+573001234567",
    "instance_name": "principal_campana",
    "evolution_api_key": "B6D9F2E8-A3C4-4D5E-9F8A-1B2C3D4E5F6A",
    "evolution_api_url": "https://evolution.midominio.com",
    "daily_message_limit": 1000,
    "messages_sent_today": 0,
    "remaining_quota": 1000,
    "last_reset_date": "2025-11-17",
    "is_active": true,
    "can_send_messages": true,
    "notes": "Instancia principal para campaña electoral",
    "created_at": "2025-11-17T06:30:00.000000Z",
    "updated_at": "2025-11-17T06:30:00.000000Z",
    "tenant": {
      "id": 5,
      "slug": "candidato-alcaldia",
      "nombre": "Juan Pérez - Alcaldía"
    }
  },
  "message": "WhatsApp instance created successfully"
}
```

**Response 422 (Validación):**
```json
{
  "message": "The phone number field must be a valid phone number in E.164 format. (and 1 more error)",
  "errors": {
    "phone_number": [
      "El número de teléfono debe estar en formato internacional (ej: +573001234567)",
      "Este número de teléfono ya está registrado para este tenant"
    ],
    "daily_message_limit": [
      "El límite diario debe ser al menos 1"
    ]
  }
}
```

---

### 3. Ver Detalle de Instancia WhatsApp

**Endpoint:** `GET /api/v1/tenant/whatsapp-instances/{id}`

**Autenticación:** Bearer Token (tenant user)

**Request:**
```http
GET /api/v1/tenant/whatsapp-instances/1
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "data": {
    "id": 1,
    "tenant_id": 5,
    "phone_number": "+573001234567",
    "instance_name": "principal_campana",
    "evolution_api_key": "B6D9F2E8-A3C4-4D5E-9F8A-1B2C3D4E5F6A",
    "evolution_api_url": "https://evolution.midominio.com",
    "daily_message_limit": 1000,
    "messages_sent_today": 245,
    "remaining_quota": 755,
    "last_reset_date": "2025-11-17",
    "is_active": true,
    "can_send_messages": true,
    "notes": "Instancia principal para campaña electoral",
    "created_at": "2025-11-17T06:30:00.000000Z",
    "updated_at": "2025-11-17T10:15:00.000000Z",
    "tenant": {
      "id": 5,
      "slug": "candidato-alcaldia",
      "nombre": "Juan Pérez - Alcaldía"
    }
  }
}
```

**Nota:** El `evolution_api_key` solo se muestra en el endpoint `show`, no en `index` por seguridad.

---

### 4. Actualizar Instancia WhatsApp

**Endpoint:** `PUT /api/v1/tenant/whatsapp-instances/{id}`

**Autenticación:** Bearer Token (tenant user)

**Request Body (todos los campos opcionales):**
```json
{
  "phone_number": "+573001234568",
  "instance_name": "principal_campana_actualizada",
  "evolution_api_key": "NEW-API-KEY-HERE",
  "evolution_api_url": "https://new-evolution.midominio.com",
  "daily_message_limit": 1500,
  "is_active": false,
  "notes": "Instancia actualizada con nuevo límite"
}
```

**Response 200:**
```json
{
  "data": {
    "id": 1,
    "tenant_id": 5,
    "phone_number": "+573001234568",
    "instance_name": "principal_campana_actualizada",
    "evolution_api_url": "https://new-evolution.midominio.com",
    "daily_message_limit": 1500,
    "messages_sent_today": 245,
    "remaining_quota": 1255,
    "last_reset_date": "2025-11-17",
    "is_active": false,
    "can_send_messages": false,
    "notes": "Instancia actualizada con nuevo límite",
    "created_at": "2025-11-17T06:30:00.000000Z",
    "updated_at": "2025-11-17T11:00:00.000000Z",
    "tenant": {
      "id": 5,
      "slug": "candidato-alcaldia",
      "nombre": "Juan Pérez - Alcaldía"
    }
  },
  "message": "WhatsApp instance updated successfully"
}
```

---

### 5. Eliminar Instancia WhatsApp

**Endpoint:** `DELETE /api/v1/tenant/whatsapp-instances/{id}`

**Autenticación:** Bearer Token (tenant user)

**Request:**
```http
DELETE /api/v1/tenant/whatsapp-instances/1
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "message": "WhatsApp instance deleted successfully"
}
```

**Nota:** Se usa soft delete, por lo que la instancia se marca como eliminada pero permanece en la BD.

---

### 6. Activar/Desactivar Instancia

**Endpoint:** `POST /api/v1/tenant/whatsapp-instances/{id}/toggle-active`

**Autenticación:** Bearer Token (tenant user)

**Request:**
```http
POST /api/v1/tenant/whatsapp-instances/1/toggle-active
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "data": {
    "id": 1,
    "tenant_id": 5,
    "phone_number": "+573001234567",
    "instance_name": "principal_campana",
    "evolution_api_url": "https://evolution.midominio.com",
    "daily_message_limit": 1000,
    "messages_sent_today": 245,
    "remaining_quota": 755,
    "last_reset_date": "2025-11-17",
    "is_active": false,
    "can_send_messages": false,
    "notes": "Instancia principal para campaña electoral",
    "created_at": "2025-11-17T06:30:00.000000Z",
    "updated_at": "2025-11-17T11:30:00.000000Z"
  },
  "message": "WhatsApp instance deactivated"
}
```

---

### 7. Resetear Contador Diario

**Endpoint:** `POST /api/v1/tenant/whatsapp-instances/{id}/reset-counter`

**Autenticación:** Bearer Token (super admin)

**Request:**
```http
POST /api/v1/whatsapp-instances/1/reset-counter
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "data": {
    "id": 1,
    "tenant_id": 5,
    "phone_number": "+573001234567",
    "instance_name": "principal_campana",
    "evolution_api_url": "https://evolution.midominio.com",
    "daily_message_limit": 1000,
    "messages_sent_today": 0,
    "remaining_quota": 1000,
    "last_reset_date": "2025-11-17",
    "is_active": true,
    "can_send_messages": true,
    "notes": "Instancia principal para campaña electoral",
    "created_at": "2025-11-17T06:30:00.000000Z",
    "updated_at": "2025-11-17T12:00:00.000000Z"
  },
  "message": "Daily counter reset successfully"
}
```

**Nota:** El contador se resetea automáticamente cada día, este endpoint es para reseteo manual de emergencia.

---

### 8. Obtener Estadísticas de Instancia

**Endpoint:** `GET /api/v1/tenant/whatsapp-instances/{id}/statistics`

**Autenticación:** Bearer Token (tenant user)

**Request:**
```http
GET /api/v1/tenant/whatsapp-instances/1/statistics
Authorization: Bearer {token}
```

**Response 200:**
```json
{
  "data": {
    "instance_id": 1,
    "phone_number": "+573001234567",
    "is_active": true,
    "daily_limit": 1000,
    "sent_today": 245,
    "remaining_today": 755,
    "usage_percentage": 24.5,
    "can_send": true,
    "last_reset": "2025-11-17 00:00:00"
  }
}
```

---

### 9. Ver Tenant con Instancias WhatsApp

**Endpoint:** `GET /api/v1/tenants/{slug}`

**Autenticación:** Bearer Token (super admin)

**Request:**
```http
GET /api/v1/tenants/candidato-alcaldia
Authorization: Bearer {token}
```

**Response 200 (fragmento relevante):**
```json
{
  "data": {
    "id": 5,
    "slug": "candidato-alcaldia",
    "nombre": "Juan Pérez - Alcaldía",
    "tipo_cargo": "Alcalde",
    "identificacion": "1234567890",
    "whatsapp_instances": [
      {
        "id": 1,
        "tenant_id": 5,
        "phone_number": "+573001234567",
        "instance_name": "principal_campana",
        "evolution_api_url": "https://evolution.midominio.com",
        "daily_message_limit": 1000,
        "messages_sent_today": 245,
        "remaining_quota": 755,
        "last_reset_date": "2025-11-17",
        "is_active": true,
        "can_send_messages": true,
        "notes": "Instancia principal para campaña electoral",
        "created_at": "2025-11-17T06:30:00.000000Z",
        "updated_at": "2025-11-17T10:15:00.000000Z"
      },
      {
        "id": 2,
        "tenant_id": 5,
        "phone_number": "+573009876543",
        "instance_name": "secundaria_recordatorios",
        "evolution_api_url": null,
        "daily_message_limit": 500,
        "messages_sent_today": 120,
        "remaining_quota": 380,
        "last_reset_date": "2025-11-17",
        "is_active": true,
        "can_send_messages": true,
        "notes": "Para recordatorios de reuniones y compromisos",
        "created_at": "2025-11-17T07:00:00.000000Z",
        "updated_at": "2025-11-17T09:45:00.000000Z"
      }
    ],
    "whatsapp_instances_count": 2,
    "active_whatsapp_instances_count": 2,
    "created_at": "2025-01-15T08:00:00.000000Z",
    "updated_at": "2025-11-17T06:30:00.000000Z"
  }
}
```

---

## Rutas Disponibles

### Solo para Super Admin (requiere rol superadmin)

**Importante:** Solo usuarios con rol **super admin** pueden gestionar instancias de WhatsApp. Los usuarios normales del tenant no tienen acceso a estas APIs.

```
# Listar todas las instancias de todos los tenants
GET    /api/v1/whatsapp-instances

# Gestión de instancias de un tenant específico (anidadas bajo tenant)
GET    /api/v1/tenants/{tenantId}/whatsapp-instances              - Listar instancias del tenant
POST   /api/v1/tenants/{tenantId}/whatsapp-instances              - Crear instancia para el tenant
GET    /api/v1/tenants/{tenantId}/whatsapp-instances/{id}         - Ver detalle
PUT    /api/v1/tenants/{tenantId}/whatsapp-instances/{id}         - Actualizar instancia
DELETE /api/v1/tenants/{tenantId}/whatsapp-instances/{id}         - Eliminar instancia
POST   /api/v1/tenants/{tenantId}/whatsapp-instances/{id}/toggle-active  - Activar/desactivar
POST   /api/v1/tenants/{tenantId}/whatsapp-instances/{id}/reset-counter  - Resetear contador manual
GET    /api/v1/tenants/{tenantId}/whatsapp-instances/{id}/statistics     - Ver estadísticas
```

**Nota:** El parámetro `{tenantId}` debe ser el **ID numérico** del tenant (ejemplo: 1, 2, 5), NO el slug.

---

## Modelo de Datos

### Tabla: `tenant_whatsapp_instances`

```sql
id                      - bigint (PK)
tenant_id               - bigint (FK -> tenants)
phone_number            - varchar(20)
instance_name           - varchar(255)
evolution_api_key       - text
evolution_api_url       - varchar(255) nullable
daily_message_limit     - integer (default: 1000)
messages_sent_today     - integer (default: 0)
last_reset_date         - date nullable
is_active               - boolean (default: true)
notes                   - text nullable
created_at              - timestamp
updated_at              - timestamp
deleted_at              - timestamp nullable
```

**Índices:**
- `tenant_id` (index)
- `tenant_id, is_active` (index)
- `tenant_id, phone_number` (unique - un número por tenant)

---

## Métodos del Modelo

### TenantWhatsAppInstance

```php
// Verificar si puede enviar mensajes
$instance->canSendMessage(): bool

// Obtener cuota restante del día
$instance->getRemainingQuota(): int

// Incrementar contador de mensajes enviados
$instance->incrementSentCount(int $count = 1): void

// Resetear contador si es nuevo día
$instance->resetDailyCounterIfNeeded(): void

// Obtener URL base de Evolution API
$instance->getEvolutionApiBaseUrl(): string
```

### Scopes Disponibles

```php
// Solo instancias activas
TenantWhatsAppInstance::active()->get();

// Solo instancias con cuota disponible
TenantWhatsAppInstance::withAvailableQuota()->get();

// Instancias de un tenant específico
$tenant->whatsappInstances;
$tenant->activeWhatsappInstances;
```

---

## Validaciones

### Formato de Número de Teléfono
- Debe seguir formato E.164: `+[código país][número]`
- Ejemplos válidos:
  - `+573001234567` (Colombia)
  - `+5491112345678` (Argentina)
  - `+521234567890` (México)

### Límite Diario
- Mínimo: 1 mensaje
- Máximo: 100,000 mensajes
- Se resetea automáticamente cada día a las 00:00

### Unicidad
- Un número de teléfono solo puede estar registrado una vez por tenant
- Diferentes tenants pueden usar el mismo número (casos especiales)

---

## Casos de Uso

### 1. Configurar Primera Instancia WhatsApp (Super Admin)

```bash
POST /api/v1/tenants/5/whatsapp-instances
Content-Type: application/json
Authorization: Bearer {token-super-admin}

{
  "phone_number": "+573001234567",
  "instance_name": "principal",
  "evolution_api_key": "tu-api-key-aqui",
  "daily_message_limit": 1000,
  "is_active": true,
  "notes": "Instancia principal"
}
```

### 2. Listar Instancias Activas con Cuota Disponible

```bash
# Listar instancias de un tenant específico
GET /api/v1/tenants/5/whatsapp-instances?is_active=true&with_quota=true
Authorization: Bearer {token-super-admin}

# Listar todas las instancias de todos los tenants
GET /api/v1/whatsapp-instances?is_active=true
Authorization: Bearer {token-super-admin}
```

### 3. Verificar Estadísticas de Uso

```bash
GET /api/v1/tenant/whatsapp-instances/1/statistics
Authorization: Bearer {token}
```

### 4. Desactivar Temporalmente una Instancia

```bash
POST /api/v1/tenant/whatsapp-instances/1/toggle-active
Authorization: Bearer {token}
```

### 5. Aumentar Límite Diario

```bash
PUT /api/v1/tenant/whatsapp-instances/1
Content-Type: application/json
Authorization: Bearer {token}

{
  "daily_message_limit": 2000
}
```

---

## Integración con Evolution API

### Próximos Pasos
1. Modificar `WhatsAppNotificationService` para usar estas instancias
2. Implementar rotación automática de instancias cuando se alcance el límite
3. Agregar balanceo de carga entre múltiples instancias activas
4. Implementar webhooks de Evolution API para sincronizar estados

### Configuración Requerida en Evolution API
- Crear instancia en Evolution API
- Obtener API Key de la instancia
- Configurar webhook para recibir eventos (opcional)
- Vincular número de WhatsApp Business a la instancia

---

## Notas de Seguridad

1. **API Keys**: Solo se muestran en el endpoint `show`, no en listados
2. **Validación**: Números en formato E.164 internacional
3. **Soft Delete**: Las instancias eliminadas se conservan en BD
4. **Rate Limiting**: Control diario automático de envíos
5. **Tenant Isolation**: Cada tenant solo ve sus propias instancias

---

## Errores Comunes

### Error: "Este número de teléfono ya está registrado para este tenant"
**Solución:** Cada tenant solo puede tener un número registrado una vez. Usa el endpoint `PUT` para actualizar la instancia existente.

### Error: "El número de teléfono debe estar en formato internacional"
**Solución:** Incluye el código de país con `+`. Ejemplo: `+573001234567`

### Error: "can_send_messages: false" en response
**Causas posibles:**
- `is_active` está en `false`
- Se alcanzó el `daily_message_limit`
- El contador no se ha reseteado correctamente

**Solución:** Verifica `statistics` endpoint para diagnóstico detallado.
