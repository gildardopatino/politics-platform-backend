# WhatsApp Instances - Ejemplos JSON de Entrada y Salida

## 📋 Resumen de Endpoints

### Para Super Admin (gestión de instancias WhatsApp)

**Nota:** Solo el super admin puede gestionar instancias de WhatsApp. Los usuarios normales del tenant no tienen acceso a estas APIs.

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/whatsapp-instances` | Listar todas las instancias |
| GET | `/api/v1/tenants/{tenant}/whatsapp-instances` | Listar instancias de un tenant |
| POST | `/api/v1/tenants/{tenant}/whatsapp-instances` | Crear instancia para tenant |
| GET | `/api/v1/tenants/{tenant}/whatsapp-instances/{id}` | Ver detalle |
| PUT | `/api/v1/tenants/{tenant}/whatsapp-instances/{id}` | Actualizar instancia |
| DELETE | `/api/v1/tenants/{tenant}/whatsapp-instances/{id}` | Eliminar instancia |
| POST | `/api/v1/tenants/{tenant}/whatsapp-instances/{id}/toggle-active` | Activar/Desactivar |
| POST | `/api/v1/tenants/{tenant}/whatsapp-instances/{id}/reset-counter` | Resetear contador |
| GET | `/api/v1/tenants/{tenant}/whatsapp-instances/{id}/statistics` | Ver estadísticas |

---

## 1️⃣ CREAR INSTANCIA WHATSAPP

### ▶️ Request JSON (Super Admin)
```json
POST /api/v1/tenants/5/whatsapp-instances
Content-Type: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc... (token de super admin)

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

**Notas:**
- Solo usuarios con rol **super admin** pueden crear instancias
- El `tenant_id` se toma del parámetro `{tenantId}` en la URL (debe ser el **ID numérico**, no el slug)
- En el ejemplo: `/tenants/5/whatsapp-instances` donde `5` es el ID del tenant
- Los usuarios normales del tenant NO tienen acceso a gestionar instancias

### ✅ Response 201 - Creado Exitosamente
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

### ❌ Response 422 - Error de Validación
```json
{
  "message": "The phone number field must be a valid phone number.",
  "errors": {
    "phone_number": [
      "El número de teléfono debe estar en formato internacional (ej: +573001234567)",
      "Este número de teléfono ya está registrado para este tenant"
    ],
    "daily_message_limit": [
      "El límite diario debe ser al menos 1"
    ],
    "instance_name": [
      "The instance name field is required."
    ]
  }
}
```

---

## 2️⃣ LISTAR INSTANCIAS

### ▶️ Request
```http
GET /api/v1/tenant/whatsapp-instances?is_active=true&per_page=10
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### ✅ Response 200
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
    "per_page": 10
  }
}
```

**Nota:** En el listado NO se muestra `evolution_api_key` por seguridad.

---

## 3️⃣ VER DETALLE DE INSTANCIA

### ▶️ Request
```http
GET /api/v1/tenant/whatsapp-instances/1
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### ✅ Response 200
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

**Nota:** Aquí SÍ se muestra `evolution_api_key` completo.

---

## 4️⃣ ACTUALIZAR INSTANCIA

### ▶️ Request JSON (todos los campos opcionales)
```json
PUT /api/v1/tenant/whatsapp-instances/1
Content-Type: application/json
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...

{
  "instance_name": "principal_actualizada",
  "daily_message_limit": 1500,
  "notes": "Límite aumentado por campaña intensiva"
}
```

### ✅ Response 200
```json
{
  "data": {
    "id": 1,
    "tenant_id": 5,
    "phone_number": "+573001234567",
    "instance_name": "principal_actualizada",
    "evolution_api_url": "https://evolution.midominio.com",
    "daily_message_limit": 1500,
    "messages_sent_today": 245,
    "remaining_quota": 1255,
    "last_reset_date": "2025-11-17",
    "is_active": true,
    "can_send_messages": true,
    "notes": "Límite aumentado por campaña intensiva",
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

## 5️⃣ ACTIVAR/DESACTIVAR INSTANCIA

### ▶️ Request
```http
POST /api/v1/tenant/whatsapp-instances/1/toggle-active
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### ✅ Response 200 - Desactivada
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

**Nota:** `can_send_messages` cambia a `false` cuando `is_active` es `false`.

---

## 6️⃣ VER ESTADÍSTICAS

### ▶️ Request
```http
GET /api/v1/tenant/whatsapp-instances/1/statistics
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### ✅ Response 200
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

## 7️⃣ ELIMINAR INSTANCIA

### ▶️ Request
```http
DELETE /api/v1/tenant/whatsapp-instances/1
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### ✅ Response 200
```json
{
  "message": "WhatsApp instance deleted successfully"
}
```

---

## 8️⃣ VER TENANT CON INSTANCIAS

### ▶️ Request
```http
GET /api/v1/tenants/candidato-alcaldia
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### ✅ Response 200 (fragmento relevante)
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
    "messaging_credits": {
      "emails": {
        "available": 1500,
        "used": 350,
        "total_cost": 35000,
        "unit_price": 100,
        "percentage_used": 18.92
      },
      "whatsapp": {
        "available": 2000,
        "used": 450,
        "total_cost": 90000,
        "unit_price": 200,
        "percentage_used": 18.37
      },
      "total_cost": 125000,
      "currency": "COP",
      "last_transaction_at": "2025-11-15T14:30:00.000000Z"
    },
    "created_at": "2025-01-15T08:00:00.000000Z",
    "updated_at": "2025-11-17T06:30:00.000000Z"
  }
}
```

---

## 📝 Campos Importantes

### En Request (Crear/Actualizar)

| Campo | Tipo | Requerido | Descripción | Ejemplo |
|-------|------|-----------|-------------|---------|
| `phone_number` | string | Sí (crear) | Formato E.164 | `"+573001234567"` |
| `instance_name` | string | Sí (crear) | Nombre identificador | `"principal_campana"` |
| `evolution_api_key` | string | Sí (crear) | API Key Evolution | `"B6D9F2E8-A3C4..."` |
| `evolution_api_url` | string | No | URL custom (opcional) | `"https://evo.com"` |
| `daily_message_limit` | integer | Sí (crear) | 1-100000 | `1000` |
| `is_active` | boolean | No | Default: true | `true` |
| `notes` | string | No | Máx 1000 chars | `"Instancia..."` |

### En Response

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | integer | ID de la instancia |
| `tenant_id` | integer | ID del tenant propietario |
| `phone_number` | string | Número en formato E.164 |
| `instance_name` | string | Nombre identificador |
| `evolution_api_key` | string | Solo en endpoint `show` |
| `evolution_api_url` | string/null | URL custom o null |
| `daily_message_limit` | integer | Límite diario configurado |
| `messages_sent_today` | integer | Contador actual del día |
| `remaining_quota` | integer | Cuota restante (calculado) |
| `last_reset_date` | string | Fecha último reseteo |
| `is_active` | boolean | Estado activo/inactivo |
| `can_send_messages` | boolean | Si puede enviar (calculado) |
| `notes` | string/null | Notas adicionales |
| `created_at` | string | ISO 8601 timestamp |
| `updated_at` | string | ISO 8601 timestamp |
| `tenant` | object | Info del tenant (cuando loaded) |

---

## 🔄 Escenarios de Uso

### Scenario 1: Configuración Inicial
```json
// 1. Crear primera instancia
POST /api/v1/tenant/whatsapp-instances
{
  "phone_number": "+573001234567",
  "instance_name": "principal",
  "evolution_api_key": "xxx",
  "daily_message_limit": 1000
}

// 2. Verificar estadísticas
GET /api/v1/tenant/whatsapp-instances/1/statistics
```

### Scenario 2: Múltiples Instancias
```json
// Instancia 1 - Campaña
POST /api/v1/tenant/whatsapp-instances
{
  "phone_number": "+573001234567",
  "instance_name": "campana_masiva",
  "evolution_api_key": "xxx",
  "daily_message_limit": 2000
}

// Instancia 2 - Recordatorios
POST /api/v1/tenant/whatsapp-instances
{
  "phone_number": "+573009876543",
  "instance_name": "recordatorios",
  "evolution_api_key": "yyy",
  "daily_message_limit": 500
}

// Listar todas activas
GET /api/v1/tenant/whatsapp-instances?is_active=true
```

### Scenario 3: Aumentar Límite en Campaña
```json
// Actualizar límite
PUT /api/v1/tenant/whatsapp-instances/1
{
  "daily_message_limit": 5000,
  "notes": "Aumentado para cierre de campaña"
}
```

### Scenario 4: Desactivar Temporalmente
```json
// Desactivar instancia
POST /api/v1/tenant/whatsapp-instances/1/toggle-active

// Reactivar después
POST /api/v1/tenant/whatsapp-instances/1/toggle-active
```

---

## ⚠️ Errores Comunes

### Error: Número Duplicado
```json
{
  "message": "The phone number has already been taken.",
  "errors": {
    "phone_number": [
      "Este número de teléfono ya está registrado para este tenant"
    ]
  }
}
```
**Solución:** Usa `PUT` para actualizar la instancia existente.

### Error: Formato Incorrecto
```json
{
  "message": "The phone number format is invalid.",
  "errors": {
    "phone_number": [
      "El número de teléfono debe estar en formato internacional (ej: +573001234567)"
    ]
  }
}
```
**Solución:** Agrega `+` y código de país: `+57` para Colombia.

### Error: Límite Excedido
```json
{
  "data": {
    "can_send_messages": false,
    "usage_percentage": 100,
    "remaining_today": 0
  }
}
```
**Solución:** Espera al reseteo automático (00:00) o aumenta `daily_message_limit`.

---

## 🎯 Próximos Pasos de Integración

1. Modificar `WhatsAppNotificationService` para:
   - Obtener instancia disponible del tenant
   - Usar API key y URL de la instancia
   - Incrementar contador después de envío exitoso
   - Rotar a siguiente instancia si se agota cuota

2. Implementar rotación automática:
   ```php
   // Obtener instancia disponible
   $instance = $tenant->activeWhatsappInstances()
       ->withAvailableQuota()
       ->first();
   
   if (!$instance) {
       throw new Exception('No WhatsApp instances available');
   }
   ```

3. Agregar logging de envíos por instancia

4. Implementar webhooks Evolution API
