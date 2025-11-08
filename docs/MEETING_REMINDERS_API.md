# Meeting Reminders API - Documentación Completa

## 📋 Índice

1. [Descripción General](#descripción-general)
2. [Arquitectura del Sistema](#arquitectura-del-sistema)
3. [Estructura de Base de Datos](#estructura-de-base-de-datos)
4. [Uso en Endpoints](#uso-en-endpoints)
5. [Ejemplos JSON Completos](#ejemplos-json-completos)
6. [Validaciones](#validaciones)
7. [Estados y Flujo](#estados-y-flujo)
8. [Errores Comunes](#errores-comunes)

---

## Descripción General

El sistema de **recordatorios de reuniones** permite a los usuarios crear notificaciones programadas que se envían automáticamente vía WhatsApp a miembros del equipo seleccionados.

### Características Principales

- ✅ Envío automático de recordatorios vía WhatsApp
- ✅ Selección múltiple de destinatarios
- ✅ Validación de horarios (mínimo 5 horas antes)
- ✅ Mensajes personalizados opcionales
- ✅ Cancelación automática al actualizar/eliminar reunión
- ✅ Tracking de envíos (exitosos/fallidos)
- ✅ Sistema de jobs para envío programado

---

## Arquitectura del Sistema

### Componentes Principales

```
┌─────────────────────────────────────────────────────────────┐
│                    MEETING REMINDERS SYSTEM                  │
├─────────────────────────────────────────────────────────────┤
│                                                              │
│  1. API Layer (MeetingController)                           │
│     ├── POST /api/v1/meetings (con reminder)                │
│     ├── PUT /api/v1/meetings/{id} (con reminder)            │
│     └── DELETE /api/v1/meetings/{id} (cancela reminders)    │
│                                                              │
│  2. Validation Layer (StoreMeetingRequest)                  │
│     ├── Validar datetime (después de now, antes de meeting) │
│     ├── Validar mínimo 5 horas de antelación                │
│     └── Validar recipients (array no vacío, usuarios válidos)│
│                                                              │
│  3. Business Logic (createReminder method)                  │
│     ├── Crear MeetingReminder record                        │
│     ├── Calcular delay para job                             │
│     └── Dispatch SendMeetingReminderJob                     │
│                                                              │
│  4. Job System (SendMeetingReminderJob)                     │
│     ├── Ejecutar en datetime programado                     │
│     ├── Enviar WhatsApp a cada recipient                    │
│     ├── Actualizar contadores (sent_count, failed_count)    │
│     └── Marcar como enviado/fallido                         │
│                                                              │
│  5. WhatsApp Integration (WhatsAppNotificationService)      │
│     ├── Normalizar números telefónicos                      │
│     ├── Enviar a webhook N8N                                │
│     └── Logging de resultados                               │
│                                                              │
└─────────────────────────────────────────────────────────────┘
```

### Flujo de Datos

```
Usuario Crea Reunión + Reminder
          ↓
    Validación Request
          ↓
   Crear Meeting Record
          ↓
   Crear MeetingReminder
   (status: pending)
          ↓
   Dispatch SendMeetingReminderJob
   (delay: reminder_datetime - now())
          ↓
   [ESPERA HASTA DATETIME]
          ↓
   Job Ejecuta (status: processing)
          ↓
   Loop por cada recipient:
     - Enviar WhatsApp
     - Incrementar sent/failed count
          ↓
   Actualizar status: sent/failed
          ↓
   FIN (reminder completado)
```

---

## Estructura de Base de Datos

### Tabla: `meeting_reminders`

| Campo                  | Tipo        | Descripción                                    |
| ---------------------- | ----------- | ---------------------------------------------- |
| `id`                   | bigint      | ID único del recordatorio                      |
| `tenant_id`            | bigint      | ID del tenant (multi-tenancy)                  |
| `meeting_id`           | bigint      | FK a meetings                                  |
| `created_by_user_id`   | bigint      | FK al usuario que creó el recordatorio         |
| `reminder_datetime`    | timestamp   | Fecha/hora programada para envío               |
| `recipients`           | jsonb       | Array de destinatarios [{"user_id": 1, ...}]   |
| `status`               | enum        | pending, processing, sent, failed, cancelled   |
| `job_id`               | string      | ID del job para cancelación                    |
| `message`              | text        | Mensaje personalizado (opcional)               |
| `metadata`             | jsonb       | Datos adicionales (opcional)                   |
| `total_recipients`     | integer     | Total de destinatarios                         |
| `sent_count`           | integer     | Cantidad de envíos exitosos                    |
| `failed_count`         | integer     | Cantidad de envíos fallidos                    |
| `sent_at`              | timestamp   | Fecha/hora de envío completado                 |
| `error_message`        | text        | Mensaje de error (si falló)                    |
| `created_at`           | timestamp   | Fecha de creación                              |
| `updated_at`           | timestamp   | Fecha de última actualización                  |
| `deleted_at`           | timestamp   | Soft delete                                    |

#### Formato JSON: `recipients`

```json
[
  {
    "user_id": 3,
    "phone": "3001234567",
    "name": "Juan Pérez"
  },
  {
    "user_id": 5,
    "phone": "3009876543",
    "name": "María González"
  }
]
```

**NOTA:** Aunque el array en la base de datos contiene `phone` y `name`, el frontend **solo debe enviar `user_id`**. El backend enriquece automáticamente el array con los datos del usuario desde la base de datos.

### Estados Posibles

| Estado       | Descripción                                     |
| ------------ | ----------------------------------------------- |
| `pending`    | Recordatorio creado, esperando ejecución        |
| `processing` | Job en ejecución, enviando mensajes             |
| `sent`       | Enviado exitosamente (total o parcialmente)     |
| `failed`     | Falló completamente (todos los envíos fallaron) |
| `cancelled`  | Cancelado manualmente o por actualización       |

---

## Uso en Endpoints

### 1. Crear Reunión con Recordatorio

**Endpoint:** `POST /api/v1/meetings`

**Headers:**

```
Authorization: Bearer {token}
Content-Type: application/json
```

**Request Body:**

```json
{
  "title": "Reunión Comunitaria Barrio Centro",
  "description": "Discutir proyectos de infraestructura",
  "starts_at": "2025-11-15 14:00:00",
  "planner_user_id": 1,
  "lugar_nombre": "Casa Comunal",
  "department_id": 1,
  "municipality_id": 5,
  "commune_id": 8,
  "barrio_id": 15,

  "reminder": {
    "datetime": "2025-11-15 09:00:00",
    "recipients": [
      {
        "user_id": 3
      },
      {
        "user_id": 5
      },
      {
        "user_id": 7
      }
    ],
    "message": "Recordatorio: Reunión importante mañana a las 2 PM. No olvides asistir."
  }
}
```

**NOTA IMPORTANTE:** Solo necesitas enviar `user_id` en el array de recipients. El sistema obtiene automáticamente `phone` y `name` de la base de datos del usuario.

**Response (201 Created):**

```json
{
  "data": {
    "id": 25,
    "title": "Reunión Comunitaria Barrio Centro",
    "description": "Discutir proyectos de infraestructura",
    "starts_at": "2025-11-15T14:00:00.000000Z",
    "status": "scheduled",
    "lugar_nombre": "Casa Comunal",
    "qr_code": "MTG-25-XYZ123",
    "planner": {
      "id": 1,
      "name": "Admin User"
    },
    "activeReminder": {
      "id": 10,
      "reminder_datetime": "2025-11-15T09:00:00.000000Z",
      "status": "pending",
      "total_recipients": 3,
      "sent_count": 0,
      "failed_count": 0,
      "recipients": [
        {
          "user_id": 3,
          "phone": "3001234567",
          "name": "Juan Pérez"
        },
        {
          "user_id": 5,
          "phone": "3009876543",
          "name": "María González"
        },
        {
          "user_id": 7,
          "phone": "3015556789",
          "name": "Carlos Ruiz"
        }
      ],
      "message": "Recordatorio: Reunión importante mañana a las 2 PM. No olvides asistir.",
      "job_id": "abc123xyz",
      "sent_at": null,
      "created_at": "2025-11-07T20:30:00.000000Z"
    }
  },
  "message": "Meeting created successfully"
}
```

---

### 2. Actualizar Reunión con Nuevo Recordatorio

**Endpoint:** `PUT /api/v1/meetings/{id}`

**Comportamiento:**

- Si se envía `reminder` en el request, se cancela el recordatorio anterior (si existe) y se crea uno nuevo
- Si NO se envía `reminder`, el recordatorio existente permanece sin cambios

**Request Body:**

```json
{
  "starts_at": "2025-11-15 15:00:00",
  "reminder": {
    "datetime": "2025-11-15 10:00:00",
    "recipients": [
      {
        "user_id": 3,
        "phone": "3001234567",
        "name": "Juan Pérez"
      }
    ]
  }
}
```

**Response (200 OK):**

```json
{
  "data": {
    "id": 25,
    "title": "Reunión Comunitaria Barrio Centro",
    "starts_at": "2025-11-15T15:00:00.000000Z",
    "activeReminder": {
      "id": 11,
      "reminder_datetime": "2025-11-15T10:00:00.000000Z",
      "status": "pending",
      "total_recipients": 1,
      "sent_count": 0,
      "failed_count": 0
    }
  },
  "message": "Meeting updated successfully"
}
```

---

### 3. Eliminar Reunión (Cancela Recordatorios)

**Endpoint:** `DELETE /api/v1/meetings/{id}`

**Comportamiento:**

- Cancela automáticamente todos los recordatorios pendientes
- Marca los recordatorios como `cancelled`

**Response (200 OK):**

```json
{
  "message": "Meeting deleted successfully"
}
```

---

### 4. Ver Reunión con Recordatorio

**Endpoint:** `GET /api/v1/meetings/{id}`

**Response (200 OK):**

```json
{
  "data": {
    "id": 25,
    "title": "Reunión Comunitaria Barrio Centro",
    "starts_at": "2025-11-15T14:00:00.000000Z",
    "reminders": [
      {
        "id": 10,
        "reminder_datetime": "2025-11-15T09:00:00.000000Z",
        "status": "sent",
        "total_recipients": 3,
        "sent_count": 3,
        "failed_count": 0,
        "sent_at": "2025-11-15T09:00:15.000000Z",
        "message": "Recordatorio: Reunión importante mañana a las 2 PM.",
        "recipients": [...]
      }
    ]
  }
}
```

---

## Ejemplos JSON Completos

### Ejemplo 1: Recordatorio Simple (Sin Mensaje Personalizado)

```json
{
  "title": "Reunión de Coordinación",
  "starts_at": "2025-12-01 10:00:00",
  "planner_user_id": 1,
  "lugar_nombre": "Oficina Principal",

  "reminder": {
    "datetime": "2025-12-01 05:00:00",
    "recipients": [
      {
        "user_id": 10,
        "phone": "3201234567",
        "name": "Ana Torres"
      }
    ]
  }
}
```

### Ejemplo 2: Recordatorio con Mensaje Personalizado

```json
{
  "title": "Capacitación Nueva Plataforma",
  "starts_at": "2025-11-20 09:00:00",
  "planner_user_id": 2,

  "reminder": {
    "datetime": "2025-11-19 18:00:00",
    "recipients": [
      {
        "user_id": 15,
        "phone": "3101112222",
        "name": "Pedro Gómez"
      },
      {
        "user_id": 18,
        "phone": "3009998888",
        "name": "Laura Martínez"
      }
    ],
    "message": "Mañana es la capacitación de la nueva plataforma. Por favor llega puntual. Trae laptop."
  }
}
```

### Ejemplo 3: Recordatorio con Metadata Adicional

```json
{
  "title": "Reunión Estratégica Q4",
  "starts_at": "2025-11-30 14:00:00",
  "planner_user_id": 1,

  "reminder": {
    "datetime": "2025-11-30 08:00:00",
    "recipients": [
      {
        "user_id": 20,
        "phone": "3155554444",
        "name": "Director Regional"
      }
    ],
    "message": "Recordatorio: Reunión estratégica hoy. Prepara reportes trimestrales.",
    "metadata": {
      "priority": "high",
      "requires_preparation": true,
      "attachments": ["reporte_q3.pdf", "proyecciones_q4.xlsx"]
    }
  }
}
```

---

## Validaciones

### Reglas de Validación

| Campo                           | Reglas                                                           |
| ------------------------------- | ---------------------------------------------------------------- |
| `reminder`                      | opcional, debe ser objeto/array                                  |
| `reminder.datetime`             | requerido si reminder existe, date, after:now                    |
| `reminder.datetime` (custom)    | debe ser < starts_at (antes de la reunión)                       |
| `reminder.datetime` (custom)    | debe ser >= (starts_at - 5 horas)                                |
| `reminder.recipients`           | requerido si reminder existe, array, min:1                       |
| `reminder.recipients.*.user_id` | requerido, exists:users,id                                       |
| `reminder.message`              | opcional, string, max:500 caracteres                             |
| `reminder.metadata`             | opcional, array/objeto                                           |

**NOTA:** Ya NO se validan `phone` ni `name` porque se obtienen automáticamente de la base de datos.

### Ejemplos de Validación Fallida

#### Error 1: Recordatorio después de la reunión

**Request:**

```json
{
  "starts_at": "2025-11-15 14:00:00",
  "reminder": {
    "datetime": "2025-11-15 16:00:00",
    "recipients": [...]
  }
}
```

**Response (422):**

```json
{
  "message": "Validation failed",
  "errors": {
    "reminder.datetime": [
      "El recordatorio debe ser antes de la reunión."
    ]
  }
}
```

#### Error 2: Menos de 5 horas de antelación

**Request:**

```json
{
  "starts_at": "2025-11-15 14:00:00",
  "reminder": {
    "datetime": "2025-11-15 13:00:00",
    "recipients": [...]
  }
}
```

**Response (422):**

```json
{
  "message": "Validation failed",
  "errors": {
    "reminder.datetime": [
      "El recordatorio debe ser al menos 5 horas antes de la reunión."
    ]
  }
}
```

#### Error 3: Recipients vacío

**Request:**

```json
{
  "reminder": {
    "datetime": "2025-11-15 09:00:00",
    "recipients": []
  }
}
```

**Response (422):**

```json
{
  "message": "Validation failed",
  "errors": {
    "reminder.recipients": [
      "Debe seleccionar al menos un destinatario."
    ]
  }
}
```

#### Error 4: Usuario no existe

**Request:**

```json
{
  "reminder": {
    "datetime": "2025-11-15 09:00:00",
    "recipients": [
      {
        "user_id": 9999
      }
    ]
  }
}
```

**Response (422):**

```json
{
  "message": "Validation failed",
  "errors": {
    "reminder.recipients.0.user_id": [
      "El usuario seleccionado no existe."
    ]
  }
}
```

#### Error 5: Usuario sin teléfono

**Comportamiento:** El sistema omite automáticamente usuarios sin teléfono y loggea una advertencia.

**Request:**

```json
{
  "reminder": {
    "datetime": "2025-11-15 09:00:00",
    "recipients": [
      {
        "user_id": 10
      }
    ]
  }
}
```

**Comportamiento:** Si el usuario 10 existe pero no tiene `phone` en la base de datos:
- El recordatorio NO se crea
- Se registra en logs: `"No valid recipients with phone numbers"`
- La reunión se crea exitosamente (sin recordatorio)
```

**Response (422):**

```json
{
  "message": "Validation failed",
  "errors": {
    "reminder.recipients.0.user_id": [
      "El usuario seleccionado no existe."
    ]
  }
}
```

---

## Estados y Flujo

### Diagrama de Estados

```
      ┌─────────┐
      │ PENDING │ ◄───── Recordatorio creado
      └────┬────┘
           │
           │ (Al llegar reminder_datetime)
           │
      ┌────▼────────┐
      │ PROCESSING  │ ◄───── Job ejecutando
      └────┬────────┘
           │
           ├─── (Todos enviados) ───► ┌──────┐
           │                           │ SENT │
           │                           └──────┘
           │
           ├─── (Todos fallaron) ───► ┌────────┐
           │                           │ FAILED │
           │                           └────────┘
           │
           └─── (Usuario cancela) ──► ┌───────────┐
                                       │ CANCELLED │
                                       └───────────┘
```

### Transiciones de Estado

| Estado Actual | Acción                      | Estado Final |
| ------------- | --------------------------- | ------------ |
| `pending`     | Job ejecuta                 | `processing` |
| `processing`  | Envíos exitosos             | `sent`       |
| `processing`  | Todos fallaron              | `failed`     |
| `pending`     | Usuario cancela/elimina     | `cancelled`  |
| `processing`  | Usuario cancela (raramente) | `cancelled`  |

### Contadores de Envío

El sistema mantiene tres contadores:

- **`total_recipients`**: Cantidad inicial de destinatarios (se establece al crear)
- **`sent_count`**: Incrementa con cada envío exitoso
- **`failed_count`**: Incrementa con cada envío fallido

**Ejemplo durante ejecución:**

```
total_recipients: 5
sent_count: 3
failed_count: 2

Estado final: "sent" (porque al menos 1 se envió)
```

---

## Mensaje de WhatsApp

### Formato por Defecto (Sin Mensaje Personalizado)

```
🔔 *Recordatorio de Reunión*

📋 *Título:* Reunión Comunitaria Barrio Centro
📅 *Fecha:* 15/11/2025
🕐 *Hora:* 02:00 PM
📍 *Lugar:* Casa Comunal

📝 *Descripción:*
Discutir proyectos de infraestructura para el próximo año

¡No olvides asistir!
```

### Formato con Mensaje Personalizado

Si se proporciona `reminder.message`, se usa ese mensaje directamente:

```
Recordatorio: Reunión importante mañana a las 2 PM. No olvides asistir.
```

---

## Errores Comunes

### Error 1: Token WhatsApp No Configurado

**Síntoma:** Reminder se crea pero nunca se envía

**Log:**

```
No creator token available for WhatsApp sending
```

**Solución:** Asegurarse que el usuario creador tenga `whatsapp_token` configurado

---

### Error 2: Job No Se Ejecuta

**Síntoma:** Reminder permanece en `pending` indefinidamente

**Diagnóstico:**

```bash
php artisan queue:work
# Ver si hay jobs fallidos
php artisan queue:failed
```

**Solución:** Iniciar queue worker en producción

---

### Error 3: Número Telefónico Inválido

**Síntoma:** `sent_count` = 0, `failed_count` > 0

**Log:**

```
Failed to send meeting reminder
phone: 123  # número inválido
```

**Solución:** Validar que los números tengan formato correcto (10 dígitos colombianos)

---

## Testing

### Prueba Manual 1: Crear Recordatorio

```bash
curl -X POST http://localhost:8000/api/v1/meetings \
  -H "Authorization: Bearer {token}" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Test Meeting",
    "starts_at": "2025-11-15 14:00:00",
    "planner_user_id": 1,
    "reminder": {
      "datetime": "2025-11-15 09:00:00",
      "recipients": [
        {
          "user_id": 3,
          "phone": "3001234567",
          "name": "Test User"
        }
      ]
    }
  }'
```

### Prueba Manual 2: Verificar Estado

```bash
# Listar recordatorios pendientes
php artisan tinker

MeetingReminder::pending()->get();

# Ver detalles
MeetingReminder::find(10);
```

### Prueba Manual 3: Ejecutar Job Manualmente

```bash
php artisan tinker

$reminder = MeetingReminder::find(10);
SendMeetingReminderJob::dispatch($reminder);
```

---

## Notas Finales

### Consideraciones de Producción

1. **Queue Worker**: Debe estar corriendo para que los jobs se ejecuten

   ```bash
   php artisan queue:work --tries=3 --timeout=120
   ```

2. **Rate Limiting**: Hay un delay de 500ms entre envíos para evitar sobrecarga

3. **Retry Logic**: Los jobs se reintentarán hasta 3 veces en caso de fallo

4. **Cancelación**: Al actualizar la reunión, el recordatorio anterior se cancela automáticamente

### Próximas Mejoras Potenciales

- [ ] Envío de recordatorios por email además de WhatsApp
- [ ] Múltiples recordatorios por reunión (ej: 1 día antes, 1 hora antes)
- [ ] Dashboard para ver estadísticas de recordatorios
- [ ] Recordatorios recurrentes para reuniones periódicas
- [ ] Confirmación de asistencia vía WhatsApp

---

**Última actualización:** 2025-11-07  
**Versión:** 1.0.0  
**Autor:** Platform Politics Backend Team
