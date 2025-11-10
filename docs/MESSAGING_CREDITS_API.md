# Sistema de Control de Mensajería - API Documentation

## Resumen

Sistema completo para control y monetización de mensajes (Email y WhatsApp) por tenant, con:
- ✅ Precios configurables por el superadmin
- ✅ Créditos por tenant (emails y WhatsApp)
- ✅ Sistema de solicitud y aprobación de recargas
- ✅ Consumo automático al enviar mensajes
- ✅ Historial completo de transacciones
- ✅ Validación de permisos (solo superadmin puede crear tenants)

---

## Configuración Actual

**Precios por defecto:**
- Email: **50 COP** por unidad
- WhatsApp: **100 COP** por unidad

**Créditos iniciales para tenant existente:**
- Emails: **1000** unidades
- WhatsApp: **500** unidades

---

## Endpoints - Tenant (Usuarios del candidato)

### 1. Ver créditos disponibles

```http
GET /api/v1/messaging/credits
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "tenant_id": 1,
    "summary": {
      "emails": {
        "available": 1000,
        "used": 0,
        "total_cost": 0,
        "unit_price": 50
      },
      "whatsapp": {
        "available": 500,
        "used": 0,
        "total_cost": 0,
        "unit_price": 100
      },
      "total_cost": 0
    },
    "last_updated": "2025-11-09T19:45:00-05:00"
  }
}
```

### 2. Ver historial de transacciones

```http
GET /api/v1/messaging/transactions?type=whatsapp&per_page=20
Authorization: Bearer {token}
```

**Query Parameters:**
- `type` (opcional): `email` o `whatsapp`
- `transaction_type` (opcional): `purchase`, `consumption`, `refund`, `adjustment`
- `per_page` (opcional): Número de resultados por página

**Response:**
```json
{
  "data": [
    {
      "id": 15,
      "tenant_id": 1,
      "type": "whatsapp",
      "transaction_type": "consumption",
      "quantity": -1,
      "unit_price": 100,
      "total_cost": 100,
      "reference": "Meeting reminder #23 to 573116677099",
      "status": "completed",
      "created_at": "2025-11-09T19:29:30-05:00"
    }
  ],
  "meta": {
    "total": 15,
    "current_page": 1,
    "last_page": 1,
    "per_page": 20
  }
}
```

### 3. Solicitar recarga de créditos

```http
POST /api/v1/messaging/request-recharge
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "type": "whatsapp",
  "quantity": 1000,
  "notes": "Necesitamos créditos para campaña de recordatorios"
}
```

**Response:**
```json
{
  "data": {
    "id": 25,
    "tenant_id": 1,
    "type": "whatsapp",
    "transaction_type": "purchase",
    "quantity": 1000,
    "unit_price": 100,
    "total_cost": 100000,
    "status": "pending",
    "requested_by_user_id": 2,
    "notes": "Necesitamos créditos para campaña de recordatorios",
    "created_at": "2025-11-09T19:50:00-05:00"
  },
  "message": "Solicitud de recarga creada. Pendiente de aprobación por el superadministrador."
}
```

### 4. Ver precios actuales

```http
GET /api/v1/messaging/pricing
Authorization: Bearer {token}
```

**Response:**
```json
{
  "data": {
    "email_price": 50,
    "whatsapp_price": 100,
    "currency": "COP"
  }
}
```

---

## Endpoints - Superadmin

### 1. Ver solicitudes pendientes

```http
GET /api/v1/superadmin/messaging/pending-requests
Authorization: Bearer {superadmin_token}
```

**Response:**
```json
{
  "data": [
    {
      "id": 25,
      "tenant_id": 1,
      "tenant": {
        "id": 1,
        "nombre": "Candidato Ejemplo"
      },
      "type": "whatsapp",
      "quantity": 1000,
      "total_cost": 100000,
      "notes": "Necesitamos créditos para campaña de recordatorios",
      "requested_by": {
        "id": 2,
        "name": "Usuario Admin",
        "email": "admin@tenant.com"
      },
      "status": "pending",
      "created_at": "2025-11-09T19:50:00-05:00"
    }
  ],
  "meta": {
    "total": 1,
    "current_page": 1,
    "last_page": 1
  }
}
```

### 2. Aprobar solicitud

```http
POST /api/v1/superadmin/messaging/approve/25
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Body (opcional):**
```json
{
  "notes": "Aprobado para campaña Q4"
}
```

**Notas:**
- ✅ **NO se crea una nueva transacción** - solo se actualiza el registro existente
- ✅ El status cambia de `pending` → `approved`
- ✅ Se agregan los créditos al tenant automáticamente
- ✅ Se registra quién aprobó (`approved_by_user_id`) y cuándo (`approved_at`)

**Response:**
```json
{
  "data": {
    "id": 25,
    "status": "approved",
    "approved_by_user_id": 1,
    "approved_at": "2025-11-09T20:00:00-05:00",
    "notes": "Aprobado para campaña Q4"
  },
  "message": "Solicitud aprobada y créditos agregados"
}
```

### 3. Rechazar solicitud

```http
POST /api/v1/superadmin/messaging/reject/25
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Body (opcional):**
```json
{
  "notes": "Presupuesto insuficiente este mes"
}
```

**Response:**
```json
{
  "data": {
    "id": 25,
    "status": "rejected",
    "approved_by_user_id": 1,
    "approved_at": "2025-11-09T20:00:00-05:00",
    "notes": "Presupuesto insuficiente este mes"
  },
  "message": "Solicitud rechazada"
}
```

### 4. Agregar créditos manualmente

```http
POST /api/v1/superadmin/messaging/add-credits
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Body:**
```json
{
  "tenant_id": 1,
  "type": "email",
  "quantity": 500,
  "notes": "Créditos de cortesía por buen uso"
}
```

**Response:**
```json
{
  "data": {
    "emails": {
      "available": 1500,
      "used": 0,
      "total_cost": 0,
      "unit_price": 50
    },
    "whatsapp": {
      "available": 500,
      "used": 1,
      "total_cost": 100,
      "unit_price": 100
    },
    "total_cost": 100
  },
  "message": "Créditos agregados exitosamente"
}
```

### 5. Ver créditos de todos los tenants

```http
GET /api/v1/superadmin/messaging/all-tenants
Authorization: Bearer {superadmin_token}
```

**Response:**
```json
{
  "data": [
    {
      "tenant_id": 1,
      "tenant_name": "Candidato Ejemplo",
      "summary": {
        "emails": {
          "available": 1000,
          "used": 0,
          "total_cost": 0,
          "unit_price": 50
        },
        "whatsapp": {
          "available": 500,
          "used": 1,
          "total_cost": 100,
          "unit_price": 100
        },
        "total_cost": 100
      },
      "last_updated": "2025-11-09T19:45:00-05:00"
    }
  ]
}
```

### 6. Ver/Actualizar precios

**Ver precios:**
```http
GET /api/v1/superadmin/messaging/pricing
Authorization: Bearer {superadmin_token}
```

**Actualizar precios:**
```http
PUT /api/v1/superadmin/messaging/pricing
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Body:**
```json
{
  "email_price": 45,
  "whatsapp_price": 95
}
```

**Response:**
```json
{
  "data": {
    "email_price": 45,
    "whatsapp_price": 95,
    "currency": "COP"
  },
  "message": "Precios actualizados exitosamente"
}
```

---

## Crear Tenant (Solo Superadmin)

```http
POST /api/v1/tenants
Authorization: Bearer {superadmin_token}
Content-Type: application/json
```

**Body:**
```json
{
  "nombre": "Nuevo Candidato",
  "slug": "nuevo-candidato",
  "tipo_cargo": "alcalde",
  "identificacion": "12345678",
  "initial_emails": 2000,
  "initial_whatsapp": 1000
}
```

**Notas:**
- ✅ **Solo el superadmin** (user con `tenant_id = null`) puede crear tenants
- Los campos `initial_emails` y `initial_whatsapp` son opcionales
- Por defecto: 1000 emails y 500 WhatsApp si no se especifican

**Response:**
```json
{
  "data": {
    "id": 2,
    "nombre": "Nuevo Candidato",
    "slug": "nuevo-candidato",
    "tipo_cargo": "alcalde",
    "identificacion": "12345678",
    "metadata": null,
    "messaging_credits": {
      "emails": {
        "available": 2000,
        "used": 0,
        "total_cost": 0,
        "unit_price": 50,
        "percentage_used": 0
      },
      "whatsapp": {
        "available": 1000,
        "used": 0,
        "total_cost": 0,
        "unit_price": 100,
        "percentage_used": 0
      },
      "total_cost": 0,
      "currency": "COP",
      "last_transaction_at": "2025-11-09T20:15:00-05:00"
    },
    "created_at": "2025-11-09T20:15:00-05:00",
    "updated_at": "2025-11-09T20:15:00-05:00"
  },
  "message": "Tenant created successfully"
}
```

---

## Ver/Listar Tenants (Con información de créditos)

### Listar todos los tenants

```http
GET /api/v1/tenants?per_page=15
Authorization: Bearer {token}
```

**Query Parameters:**
- `per_page` (opcional): Número de resultados por página (default: 15)
- `filter[nombre]` (opcional): Filtrar por nombre
- `filter[tipo_cargo]` (opcional): Filtrar por tipo de cargo
- `filter[identificacion]` (opcional): Filtrar por identificación
- `sort` (opcional): Ordenar por campo (`nombre`, `created_at`, `-created_at` para desc)

**Notas:**
- ✅ Endpoint accesible tanto para **tenants** (ven solo su propio tenant) como para **superadmin** (ven todos)
- Para superadmin: lista todos los tenants con información de créditos
- Para tenant users: retorna solo su tenant

**Response:**
```json
{
  "data": [
    {
      "id": 1,
      "slug": "candidato-ejemplo",
      "nombre": "Candidato Ejemplo",
      "tipo_cargo": "alcalde",
      "identificacion": "12345678",
      "metadata": null,
      "messaging_credits": {
        "emails": {
          "available": 950,
          "used": 50,
          "total_cost": 2500,
          "unit_price": 50,
          "percentage_used": 5
        },
        "whatsapp": {
          "available": 485,
          "used": 15,
          "total_cost": 1500,
          "unit_price": 100,
          "percentage_used": 3
        },
        "total_cost": 4000,
        "currency": "COP",
        "last_transaction_at": "2025-11-09T19:45:00-05:00"
      },
      "created_at": "2025-10-01T10:00:00-05:00",
      "updated_at": "2025-11-09T19:45:00-05:00"
    }
  ],
  "meta": {
    "total": 1,
    "current_page": 1,
    "last_page": 1,
    "per_page": 15
  }
}
```

### Ver un tenant específico

```http
GET /api/v1/tenants/{id}
Authorization: Bearer {token}
```

**Notas:**
- ✅ **Superadmin**: Puede ver cualquier tenant por ID
- ✅ **Tenant users**: Solo pueden ver su propio tenant (validación automática por TenantScope)
- ℹ️ **Para settings personalizados del tenant autenticado**, los usuarios del tenant deben usar: `GET /api/v1/tenant/settings` (no requiere ID)

**Response:**
```json
{
  "data": {
    "id": 1,
    "slug": "candidato-ejemplo",
    "nombre": "Candidato Ejemplo",
    "tipo_cargo": "alcalde",
    "identificacion": "12345678",
    "metadata": null,
    "users_count": 25,
    "meetings_count": 120,
    "campaigns_count": 5,
    "messaging_credits": {
      "emails": {
        "available": 950,
        "used": 50,
        "total_cost": 2500,
        "unit_price": 50,
        "percentage_used": 5
      },
      "whatsapp": {
        "available": 485,
        "used": 15,
        "total_cost": 1500,
        "unit_price": 100,
        "percentage_used": 3
      },
      "total_cost": 4000,
      "currency": "COP",
      "last_transaction_at": "2025-11-09T19:45:00-05:00"
    },
    "created_at": "2025-10-01T10:00:00-05:00",
    "updated_at": "2025-11-09T19:45:00-05:00"
  }
}
```

### Actualizar un tenant

```http
PUT /api/v1/tenants/{id}
Authorization: Bearer {token}
Content-Type: application/json
```

**Body:**
```json
{
  "nombre": "Candidato Ejemplo Actualizado",
  "tipo_cargo": "gobernador"
}
```

**Response:** (Igual estructura que GET /tenants/{id} con los datos actualizados + información completa de créditos)

---

## Tenant Settings (Configuración del Tenant)

### Ver settings del tenant autenticado

```http
GET /api/v1/tenant/settings
Authorization: Bearer {tenant_user_token}
```

**Notas:**
- ⚠️ **Solo para usuarios del tenant** (con `tenant_id` asignado)
- ❌ **Superadmin NO puede usar este endpoint** (debe usar `GET /api/v1/tenants/{id}`)
- Retorna configuración completa incluyendo tema, logo, jerarquía, etc.

**Response:**
```json
{
  "data": {
    "id": 1,
    "slug": "candidato-ejemplo",
    "nombre": "Candidato Ejemplo",
    "tipo_cargo": "alcalde",
    "identificacion": "12345678",
    "logo": "https://signed-url.wasabi.com/...",
    "logo_key": "tenants/logos/abc123.jpg",
    "theme": {
      "sidebar_bg_color": "#1a202c",
      "sidebar_text_color": "#ffffff",
      "header_bg_color": "#2d3748",
      "header_text_color": "#ffffff",
      "content_bg_color": "#ffffff",
      "content_text_color": "#000000"
    },
    "hierarchy_settings": {
      "hierarchy_mode": "single",
      "auto_assign_hierarchy": true,
      "hierarchy_conflict_resolution": "newest",
      "require_hierarchy_config": false
    }
  }
}
```

### Actualizar settings del tenant autenticado

```http
PUT /api/v1/tenant/settings
Authorization: Bearer {tenant_user_token}
Content-Type: multipart/form-data
```

**Notas:**
- ⚠️ **Solo para usuarios del tenant** (con `tenant_id` asignado)
- ❌ **Superadmin NO puede usar este endpoint** (debe usar `PUT /api/v1/tenants/{id}`)
- Acepta `multipart/form-data` para subir logo
- Logo se sube a Wasabi S3 con bucket por tenant

**Body (form-data):**
```
nombre: "Candidato Actualizado"
tipo_cargo: "gobernador"
sidebar_bg_color: "#1e3a8a"
sidebar_text_color: "#ffffff"
logo: [archivo de imagen]
```

**Response:** (Igual estructura que GET /api/v1/tenant/settings con datos actualizados)

---

## Consumo Automático de Créditos

### WhatsApp (Recordatorios de Reuniones)

Cuando se envía un recordatorio de reunión por WhatsApp:

1. **Antes de enviar**: Se verifica que el tenant tenga créditos suficientes
2. **Si hay créditos**: Se envía el mensaje
3. **Después de enviar exitosamente**: Se consume 1 crédito automáticamente
4. **Si no hay créditos**: El job falla con mensaje claro

**Ejemplo de log cuando hay créditos:**
```
[2025-11-09 19:29:30] WhatsApp reminder sent via n8n webhook
[2025-11-09 19:29:30] Meeting reminder sent successfully
[2025-11-09 19:29:30] WhatsApp credit consumed: 1 unit
```

**Ejemplo de log cuando NO hay créditos:**
```
[2025-11-09 19:29:30] Insufficient WhatsApp credits
  tenant_id: 1
  available: 0
  required: 1
[2025-11-09 19:29:30] Reminder failed: Créditos insuficientes. Disponibles: 0, Requeridos: 1
```

---

## Flujo Completo de Uso

### Para el Tenant (Candidato):

1. **Verificar créditos disponibles**
   ```
   GET /api/v1/messaging/credits
   ```

2. **Si necesita más créditos, solicitar recarga**
   ```
   POST /api/v1/messaging/request-recharge
   {
     "type": "whatsapp",
     "quantity": 1000
   }
   ```

3. **Esperar aprobación del superadmin**

4. **Usar el sistema normalmente** (crear reuniones con recordatorios)
   - Los créditos se consumen automáticamente

5. **Ver historial de consumo**
   ```
   GET /api/v1/messaging/transactions?type=whatsapp
   ```

### Para el Superadmin:

1. **Revisar solicitudes pendientes**
   ```
   GET /api/v1/superadmin/messaging/pending-requests
   ```

2. **Aprobar o rechazar solicitudes**
   ```
   POST /api/v1/superadmin/messaging/approve/{id}
   POST /api/v1/superadmin/messaging/reject/{id}
   ```

3. **O agregar créditos directamente sin solicitud**
   ```
   POST /api/v1/superadmin/messaging/add-credits
   ```

4. **Monitorear consumo de todos los tenants**
   ```
   GET /api/v1/superadmin/messaging/all-tenants
   ```

5. **Ajustar precios según sea necesario**
   ```
   PUT /api/v1/superadmin/messaging/pricing
   ```

---

## Base de Datos

### Tablas Creadas

1. **messaging_config**: Configuración de precios
2. **tenant_messaging_credits**: Créditos por tenant
3. **messaging_credit_transactions**: Historial de transacciones

### Modelos

1. **MessagingConfig**: Gestión de precios
2. **TenantMessagingCredit**: Créditos y métodos de consumo
3. **MessagingCreditTransaction**: Auditoría de transacciones

---

## Seguridad

- ✅ Solo superadmin puede crear tenants
- ✅ Solo superadmin puede aprobar recargas
- ✅ Solo superadmin puede cambiar precios
- ✅ Cada tenant solo ve sus propios créditos
- ✅ Todas las transacciones quedan auditadas
- ✅ Sistema de transacciones DB garantiza consistencia

---

## Testing

**Verificar créditos actuales:**
```bash
php artisan tinker --execute="
\$credit = \App\Models\TenantMessagingCredit::where('tenant_id', 1)->first();
echo json_encode(\$credit->getSummary(), JSON_PRETTY_PRINT);
"
```

**Ver últimas transacciones:**
```bash
php artisan tinker --execute="
\$trans = \App\Models\MessagingCreditTransaction::where('tenant_id', 1)
  ->orderBy('created_at', 'desc')
  ->limit(5)
  ->get(['type', 'transaction_type', 'quantity', 'total_cost', 'created_at']);
echo json_encode(\$trans, JSON_PRETTY_PRINT);
"
```

---

## Próximos Pasos

1. ✅ Sistema implementado y funcional
2. ⏳ Integrar con sistema de emails (cuando se implemente)
3. ⏳ Dashboard con gráficas de consumo
4. ⏳ Alertas cuando créditos estén bajos
5. ⏳ Sistema de facturación mensual

---

**Fecha de implementación**: 9 de noviembre de 2025
**Versión**: 1.0.0
