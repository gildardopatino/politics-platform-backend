# Meeting Reminders - Guía Rápida Frontend

## 🚀 Quick Start

### Crear Reunión con Recordatorio

```javascript
POST /api/v1/meetings

{
  // Datos normales de la reunión
  "title": "Reunión Comunitaria",
  "starts_at": "2025-11-15 14:00:00",
  "planner_user_id": 1,
  "lugar_nombre": "Casa Comunal",
  
  // NUEVO: Recordatorio (OPCIONAL)
  "reminder": {
    "datetime": "2025-11-15 09:00:00",  // Al menos 5 horas antes
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
      }
    ],
    "message": "Texto personalizado (opcional, máx 500 caracteres)"
  }
}
```

---

## 📝 Estructura del Objeto `reminder`

```typescript
interface Reminder {
  datetime: string;           // ISO 8601 o "YYYY-MM-DD HH:mm:ss"
  recipients: Recipient[];    // Array de destinatarios (mínimo 1)
  message?: string;           // Opcional, máx 500 caracteres
  metadata?: object;          // Opcional, cualquier dato adicional
}

interface Recipient {
  user_id: number;           // ID del usuario (debe existir)
  phone: string;             // Teléfono (10 dígitos sin +57)
  name: string;              // Nombre completo
}
```

---

## ✅ Validaciones Importantes

| Regla                      | Descripción                                             |
| -------------------------- | ------------------------------------------------------- |
| **datetime > now**         | El recordatorio debe ser en el futuro                   |
| **datetime < starts_at**   | El recordatorio debe ser ANTES de la reunión            |
| **datetime <= starts_at - 5h** | Mínimo 5 horas de anticipación                      |
| **recipients.length >= 1** | Debe haber al menos 1 destinatario                      |
| **message.length <= 500**  | Mensaje personalizado máximo 500 caracteres             |

---

## 📥 Respuesta al Crear/Actualizar

```json
{
  "data": {
    "id": 25,
    "title": "Reunión Comunitaria",
    "starts_at": "2025-11-15T14:00:00.000000Z",
    
    "activeReminder": {
      "id": 10,
      "reminder_datetime": "2025-11-15T09:00:00.000000Z",
      "status": "pending",           // pending, processing, sent, failed, cancelled
      "total_recipients": 2,
      "sent_count": 0,
      "failed_count": 0,
      "recipients": [...],           // Array completo de destinatarios
      "message": "...",
      "job_id": "abc123",
      "sent_at": null
    }
  }
}
```

---

## 🎨 Ejemplo Frontend (React)

```jsx
const [reminderData, setReminderData] = useState({
  datetime: '',
  recipients: [],
  message: ''
});

const handleAddRecipient = (user) => {
  setReminderData(prev => ({
    ...prev,
    recipients: [...prev.recipients, {
      user_id: user.id,
      phone: user.phone,
      name: user.name
    }]
  }));
};

const handleSubmit = async () => {
  const meetingData = {
    title: 'Mi Reunión',
    starts_at: '2025-11-15 14:00:00',
    planner_user_id: currentUser.id,
    
    // Solo incluir reminder si hay destinatarios
    ...(reminderData.recipients.length > 0 && {
      reminder: reminderData
    })
  };

  const response = await fetch('/api/v1/meetings', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(meetingData)
  });

  const result = await response.json();
  
  if (response.ok) {
    console.log('Reunión creada:', result.data);
    console.log('Recordatorio programado:', result.data.activeReminder);
  }
};
```

---

## 🔄 Actualizar Reunión con Recordatorio

```javascript
PUT /api/v1/meetings/{id}

// OPCIÓN 1: Cambiar hora de reunión Y actualizar recordatorio
{
  "starts_at": "2025-11-15 15:00:00",
  "reminder": {
    "datetime": "2025-11-15 10:00:00",
    "recipients": [...]
  }
}

// OPCIÓN 2: Solo actualizar datos de reunión (recordatorio no cambia)
{
  "title": "Título actualizado",
  "starts_at": "2025-11-15 15:00:00"
  // Sin "reminder" -> el recordatorio existente permanece
}

// OPCIÓN 3: Cancelar recordatorio existente y crear uno nuevo
{
  "reminder": {
    "datetime": "2025-11-15 11:00:00",
    "recipients": [...]  // Nuevos destinatarios
  }
}
```

**Comportamiento:**

- Si envías `reminder` → cancela el anterior (si existe) y crea uno nuevo
- Si NO envías `reminder` → el recordatorio actual NO cambia

---

## ❌ Errores Comunes

### Error 1: Recordatorio muy cerca de la reunión

```json
// REQUEST
{
  "starts_at": "2025-11-15 14:00:00",
  "reminder": {
    "datetime": "2025-11-15 13:00:00"  // Solo 1 hora antes ❌
  }
}

// RESPONSE 422
{
  "message": "Validation failed",
  "errors": {
    "reminder.datetime": [
      "El recordatorio debe ser al menos 5 horas antes de la reunión."
    ]
  }
}
```

### Error 2: Recordatorio después de la reunión

```json
// REQUEST
{
  "starts_at": "2025-11-15 14:00:00",
  "reminder": {
    "datetime": "2025-11-15 16:00:00"  // Después de la reunión ❌
  }
}

// RESPONSE 422
{
  "errors": {
    "reminder.datetime": [
      "El recordatorio debe ser antes de la reunión."
    ]
  }
}
```

### Error 3: Sin destinatarios

```json
// REQUEST
{
  "reminder": {
    "datetime": "2025-11-15 09:00:00",
    "recipients": []  // Array vacío ❌
  }
}

// RESPONSE 422
{
  "errors": {
    "reminder.recipients": [
      "Debe seleccionar al menos un destinatario."
    ]
  }
}
```

---

## 📊 Estados del Recordatorio

| Estado       | Descripción                                   | Visible en UI |
| ------------ | --------------------------------------------- | ------------- |
| `pending`    | Programado, esperando envío                   | ✅ Mostrar    |
| `processing` | Enviando mensajes en este momento             | ✅ Mostrar    |
| `sent`       | Enviado exitosamente                          | ✅ Mostrar    |
| `failed`     | Falló (problema técnico)                      | ⚠️ Mostrar    |
| `cancelled`  | Cancelado por usuario o actualización         | ❌ No mostrar |

### Ejemplo de UI

```jsx
const ReminderBadge = ({ reminder }) => {
  if (!reminder) return null;

  const statusConfig = {
    pending: { color: 'blue', icon: '⏰', text: 'Programado' },
    processing: { color: 'orange', icon: '📤', text: 'Enviando' },
    sent: { color: 'green', icon: '✅', text: 'Enviado' },
    failed: { color: 'red', icon: '❌', text: 'Fallido' },
    cancelled: { color: 'gray', icon: '🚫', text: 'Cancelado' }
  };

  const config = statusConfig[reminder.status];

  return (
    <div className={`badge badge-${config.color}`}>
      <span>{config.icon}</span>
      <span>{config.text}</span>
      <span className="ml-2">
        {reminder.sent_count}/{reminder.total_recipients} enviados
      </span>
    </div>
  );
};
```

---

## 🎯 Casos de Uso Comunes

### 1. Reunión sin recordatorio

```json
{
  "title": "Reunión sin recordatorio",
  "starts_at": "2025-11-15 14:00:00",
  "planner_user_id": 1
  // Sin campo "reminder"
}
```

### 2. Recordatorio a 1 persona

```json
{
  "title": "Reunión 1-on-1",
  "starts_at": "2025-11-15 14:00:00",
  "planner_user_id": 1,
  "reminder": {
    "datetime": "2025-11-15 09:00:00",
    "recipients": [
      {
        "user_id": 5,
        "phone": "3001234567",
        "name": "Juan Pérez"
      }
    ]
  }
}
```

### 3. Recordatorio a todo el equipo

```json
{
  "title": "Reunión General",
  "starts_at": "2025-11-20 09:00:00",
  "planner_user_id": 1,
  "reminder": {
    "datetime": "2025-11-19 18:00:00",  // 1 día antes
    "recipients": [
      { "user_id": 3, "phone": "3001111111", "name": "Ana Torres" },
      { "user_id": 5, "phone": "3002222222", "name": "Carlos Ruiz" },
      { "user_id": 7, "phone": "3003333333", "name": "María López" },
      { "user_id": 9, "phone": "3004444444", "name": "Pedro Gómez" }
    ],
    "message": "Reunión general mañana. Asistencia obligatoria."
  }
}
```

### 4. Recordatorio con mensaje personalizado

```json
{
  "title": "Capacitación Técnica",
  "starts_at": "2025-11-25 10:00:00",
  "planner_user_id": 1,
  "reminder": {
    "datetime": "2025-11-24 19:00:00",
    "recipients": [...],
    "message": "Mañana es la capacitación de la nueva plataforma. Por favor:\n\n✅ Lleva tu laptop\n✅ Instala el software previamente\n✅ Llega 10 min antes"
  }
}
```

---

## 🛠️ Testing con CURL

```bash
# Crear reunión con recordatorio
curl -X POST http://localhost:8000/api/v1/meetings \
  -H "Authorization: Bearer YOUR_TOKEN" \
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

# Ver reunión con recordatorio
curl -X GET http://localhost:8000/api/v1/meetings/25 \
  -H "Authorization: Bearer YOUR_TOKEN"

# Actualizar recordatorio
curl -X PUT http://localhost:8000/api/v1/meetings/25 \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "reminder": {
      "datetime": "2025-11-15 10:00:00",
      "recipients": [...]
    }
  }'
```

---

## 📞 Soporte

**Documentación completa:** `docs/MEETING_REMINDERS_API.md`

**Contacto:** Platform Politics Backend Team

---

**Última actualización:** 2025-11-07  
**Versión:** 1.0.0
