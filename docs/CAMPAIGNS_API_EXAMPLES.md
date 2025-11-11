# API de Campañas - Ejemplos para Frontend

## 🎯 Capacidades del Sistema

El sistema de campañas te permite enviar mensajes por **WhatsApp**, **Email** o **Ambos** a:

✅ **Todos los usuarios** del tenant  
✅ **Asistentes de UNA reunión** específica  
✅ **Asistentes de MÚLTIPLES reuniones** (elimina duplicados automáticamente)  
✅ **Lista personalizada** de emails/teléfonos específicos (contactos externos, VIPs, etc.)  
✅ **Por ubicación geográfica** (Departamento, Municipio, Comuna, Barrio) - ⭐ NUEVO

**Características:**
- Envío inmediato o programado para fecha/hora futura
- Deduplicación automática de destinatarios
- Tracking de envío (pending, sent, failed)
- Contadores automáticos de éxito/fallos
- Filtrado geográfico con cascada automática (más específico tiene prioridad)

---

## Endpoint Base
```
POST /campaigns
```

## Headers Requeridos
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
Accept: application/json
```

---

## 📋 Campos Disponibles

### Campos Obligatorios
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `title` | string (max 255) | Título de la campaña |
| `message` | string (text) | Mensaje a enviar |
| `channel` | string (enum) | Canal de envío: `"whatsapp"`, `"email"`, o `"both"` |

### Campos Opcionales
| Campo | Tipo | Descripción |
|-------|------|-------------|
| `filter_json` | object | Filtros para segmentar destinatarios |
| `scheduled_at` | datetime | Fecha/hora programada (ISO 8601) - debe ser futura |

---

## 📨 Ejemplos de Requests

### 1. Campaña Simple - Email Inmediato
Envía un email a todos los usuarios inmediatamente.

```json
{
  "title": "Invitación a Reunión Comunitaria",
  "message": "Te invitamos a participar en nuestra próxima reunión comunitaria este sábado 2 de noviembre a las 10:00 AM en la Casa Comunal.",
  "channel": "email"
}
```

**Nota:** Sin `filter_json` ni `scheduled_at`, se envía inmediatamente a todos los usuarios del tenant.

---

### 2. Campaña WhatsApp Inmediata
Envía WhatsApp a todos los usuarios inmediatamente.

```json
{
  "title": "Recordatorio Importante",
  "message": "Recordatorio: Mañana reunión a las 10AM en Casa Comunal. Confirma tu asistencia.",
  "channel": "whatsapp"
}
```

---

### 3. Campaña Dual (Email + WhatsApp)
Envía tanto por email como por WhatsApp.

```json
{
  "title": "Anuncio Urgente",
  "message": "Información importante: La reunión del viernes ha sido reprogramada para el lunes 4 de noviembre a las 3:00 PM.",
  "channel": "both"
}
```

**Comportamiento:**
- Si el usuario tiene email → recibe email
- Si el usuario tiene teléfono → recibe WhatsApp
- Si tiene ambos → recibe por ambos canales

---

### 4. Campaña Programada
Programa el envío para una fecha/hora específica.

```json
{
  "title": "Recordatorio de Evento",
  "message": "Te recordamos que mañana tenemos reunión. No faltes!",
  "channel": "both",
  "scheduled_at": "2025-11-03T08:00:00Z"
}
```

**Importante:**
- `scheduled_at` debe ser en formato ISO 8601
- La fecha debe ser futura (después de "now")
- Hora en UTC (ajustar timezone según necesidad)

---

### 5. Campaña para Todos los Usuarios
Envía a todos los usuarios del tenant.

```json
{
  "title": "Anuncio General",
  "message": "Les informamos sobre las nuevas actividades de la campaña política.",
  "channel": "email",
  "filter_json": {
    "target": "all_users"
  }
}
```

---

### 6. Campaña para Asistentes de UNA Reunión Específica
Envía solo a los asistentes registrados en una reunión.

```json
{
  "title": "Seguimiento Post-Reunión",
  "message": "Gracias por asistir a nuestra reunión. Aquí están los compromisos acordados...",
  "channel": "email",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [15]
  }
}
```

**Uso común:**
- Enviar resumen de reunión
- Recordar compromisos adquiridos
- Encuestas post-evento
- Material de seguimiento

---

### 7. Campaña para Asistentes de MÚLTIPLES Reuniones
Envía a todos los asistentes de varias reuniones (elimina duplicados automáticamente).

```json
{
  "title": "Seguimiento Reuniones del Mes",
  "message": "Gracias por participar en nuestras reuniones este mes. Aquí el resumen de compromisos...",
  "channel": "email",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [15, 18, 20, 22]
  }
}
```

**Casos de uso:**
- Enviar resumen mensual a todos los participantes activos
- Campaña a asistentes de una serie de reuniones temáticas
- Seguimiento a múltiples eventos relacionados
- Newsletter para participantes de varios sectores

---

### 8. Campaña a Números/Emails Específicos (Lista Personalizada)
Envía a una lista específica de contactos individuales.

```json
{
  "title": "Invitación Personalizada",
  "message": "Te invitamos a participar en nuestro evento especial...",
  "channel": "both",
  "filter_json": {
    "target": "custom_list",
    "custom_recipients": [
      {
        "type": "email",
        "value": "juan.perez@example.com",
        "name": "Juan Pérez"
      },
      {
        "type": "phone",
        "value": "+573001234567",
        "name": "María González"
      },
      {
        "type": "email",
        "value": "carlos.rodriguez@example.com",
        "name": "Carlos Rodríguez"
      },
      {
        "type": "phone",
        "value": "+573009876543"
      }
    ]
  }
}
```

**Campos de `custom_recipients`:**
- `type` (obligatorio): `"email"` o `"phone"`
- `value` (obligatorio): Email o teléfono (con código país para WhatsApp)
- `name` (opcional): Nombre del destinatario

**Casos de uso:**
- Invitaciones VIP a líderes específicos
- Contactar personas que no están registradas en el sistema
- Enviar a contactos externos (aliados, autoridades, etc.)
- Testing antes de enviar campaña masiva

---

### 9. Campaña Mixta: Asistentes + Lista Personalizada
**NOTA:** No es posible mezclar en una sola campaña. Debes crear 2 campañas separadas.

**Campaña 1 - Asistentes:**
```json
{
  "title": "Seguimiento Reunión",
  "message": "Gracias por asistir...",
  "channel": "email",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [15]
  }
}
```

**Campaña 2 - Invitados externos:**
```json
{
  "title": "Seguimiento Reunión",
  "message": "Gracias por asistir...",
  "channel": "email",
  "filter_json": {
    "target": "custom_list",
    "custom_recipients": [...]
  }
}
```

---

### 10. Campaña Programada para Asistentes
Combina filtro de asistentes + programación.

```json
{
  "title": "Recordatorio Pre-Reunión",
  "message": "Mañana es nuestra reunión. Te esperamos!",
  "channel": "whatsapp",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [20]
  },
  "scheduled_at": "2025-11-05T18:00:00Z"
}
```

**Caso de uso:** Enviar recordatorio 12-24 horas antes del evento.

---

### 11. Campaña con Mensaje Largo
Para emails con contenido extenso.

```json
{
  "title": "Resumen de Actividades del Mes",
  "message": "Estimados compañeros,\n\nLes compartimos el resumen de las actividades realizadas durante el mes:\n\n1. Reuniones comunitarias: 15\n2. Compromisos cumplidos: 45\n3. Nuevos afiliados: 120\n\nAgradecemos su participación.\n\nSaludos,\nEquipo de Coordinación",
  "channel": "email"
}
```

**Nota:** Usa `\n` para saltos de línea en el mensaje.

---

### 12. Campaña WhatsApp Corta (Recomendado)
Los mensajes de WhatsApp tienen mejor rendimiento cuando son concisos.

```json
{
  "title": "Alerta Rápida",
  "message": "URGENTE: Cambio de ubicación. Nueva dirección: Calle 50 #30-20. Confirma.",
  "channel": "whatsapp"
}
```

**Tip:** Mantén mensajes claros y concisos para mejor engagement.

---

## 📊 Respuesta Exitosa (201 Created)

```json
{
  "data": {
    "id": 25,
    "tenant_id": 1,
    "title": "Invitación a Reunión Comunitaria",
    "message": "Te invitamos a participar...",
    "channel": "email",
    "filter_json": {
      "target": "meeting_attendees",
      "meeting_id": 15
    },
    "scheduled_at": "2025-11-03T08:00:00.000000Z",
    "sent_at": null,
    "status": "pending",
    "total_recipients": 45,
    "sent_count": 0,
    "failed_count": 0,
    "created_by": 5,
    "creator": {
      "id": 5,
      "name": "Juan Pérez",
      "email": "juan@example.com"
    },
    "created_at": "2025-10-30T12:00:00.000000Z",
    "updated_at": "2025-10-30T12:00:00.000000Z"
  },
  "message": "Campaign created and queued for sending"
}
```

---

## ⚠️ Validaciones y Errores

### Error 422 - Validation Error

#### Título faltante
```json
{
  "message": "The title field is required.",
  "errors": {
    "title": ["The title field is required."]
  }
}
```

#### Mensaje faltante
```json
{
  "message": "The message field is required.",
  "errors": {
    "message": ["The message field is required."]
  }
}
```

#### Canal inválido
```json
{
  "message": "The selected channel is invalid.",
  "errors": {
    "channel": ["The selected channel is invalid."]
  }
}
```

**Valores válidos:** `"whatsapp"`, `"email"`, `"both"` (case-sensitive)

#### Fecha programada en el pasado
```json
{
  "message": "The scheduled at field must be a date after now.",
  "errors": {
    "scheduled_at": ["The scheduled at field must be a date after now."]
  }
}
```

#### filter_json no es un objeto
```json
{
  "message": "The filter json field must be an array.",
  "errors": {
    "filter_json": ["The filter json field must be an array."]
  }
}
```

---

## 🔄 Estados de Campaña

Después de crear, la campaña pasa por estos estados:

| Estado | Descripción |
|--------|-------------|
| `pending` | Creada, en cola para envío |
| `in_progress` | Enviando mensajes |
| `completed` | Todos los mensajes enviados |
| `cancelled` | Cancelada manualmente |
| `failed` | Error en el envío |

---

## 🎯 Opciones de `filter_json`

### Opción 1: Todos los usuarios
```json
"filter_json": {
  "target": "all_users"
}
```
Envía a todos los usuarios del tenant que tengan email/teléfono según el canal.

### Opción 2: Asistentes de UNA o VARIAS reuniones
```json
"filter_json": {
  "target": "meeting_attendees",
  "meeting_ids": [15, 18, 20]
}
```
Envía a los asistentes registrados de las reuniones especificadas. Si un asistente participó en varias reuniones, solo recibe UN mensaje (se eliminan duplicados automáticamente).

**Para una sola reunión:**
```json
"filter_json": {
  "target": "meeting_attendees",
  "meeting_ids": [15]
}
```

### Opción 3: Lista personalizada (emails/teléfonos específicos)
```json
"filter_json": {
  "target": "custom_list",
  "custom_recipients": [
    {
      "type": "email",
      "value": "contacto@example.com",
      "name": "Juan Pérez"
    },
    {
      "type": "phone",
      "value": "+573001234567",
      "name": "María González"
    }
  ]
}
```

**Validaciones:**
- `type`: Debe ser `"email"` o `"phone"` (case-sensitive)
- `value`: Email válido o teléfono con código país (ej: +57...)
- `name`: Opcional, nombre del destinatario
- Si `type="email"` pero `channel="whatsapp"` → se ignora
- Si `type="phone"` pero `channel="email"` → se ignora

### Opción 4: Sin filtro (default)
```json
// No incluir filter_json
```
Comportamiento igual a `"target": "all_users"`.

---

## 📅 Formatos de Fecha para `scheduled_at`

### JavaScript/Frontend
```javascript
// Opción 1: Usar Date ISO string
const scheduledDate = new Date('2025-11-03T08:00:00');
const payload = {
  title: "Mi Campaña",
  message: "Mensaje",
  channel: "email",
  scheduled_at: scheduledDate.toISOString() // "2025-11-03T08:00:00.000Z"
};

// Opción 2: Construir manualmente
const payload = {
  scheduled_at: "2025-11-03T08:00:00Z"
};

// Opción 3: Agregar horas a fecha actual
const tomorrow = new Date();
tomorrow.setDate(tomorrow.getDate() + 1);
tomorrow.setHours(10, 0, 0, 0);
const payload = {
  scheduled_at: tomorrow.toISOString()
};
```

---

## 🔗 Otros Endpoints Relacionados

### Listar Campañas
```
GET /campaigns
```

**Query params opcionales:**
- `?filter[status]=pending` - Filtrar por estado
- `?filter[channel]=email` - Filtrar por canal
- `?sort=-created_at` - Ordenar (usar `-` para descendente)
- `?per_page=20` - Items por página

### Ver Campaña
```
GET /campaigns/{id}
```

### Actualizar Campaña (solo si status=pending)
```
PUT /campaigns/{id}
PATCH /campaigns/{id}
```

**Body:** Mismos campos que crear (todos opcionales en update)

### Eliminar Campaña
```
DELETE /campaigns/{id}
```

**Restricción:** No se puede eliminar si status=in_progress

### Enviar Campaña Manualmente
```
POST /campaigns/{id}/send
```

Fuerza el envío de una campaña pending.

### Cancelar Campaña
```
POST /campaigns/{id}/cancel
```

Cancela una campaña (excepto si ya está completed).

### Ver Destinatarios
```
GET /campaigns/{id}/recipients
```

Lista todos los destinatarios de la campaña con su estado de envío.

---

## 💡 Tips para Frontend

### 1. Validación Previa
```javascript
// Validar antes de enviar
function validateCampaign(data) {
  const errors = {};
  
  if (!data.title || data.title.trim() === '') {
    errors.title = 'Título es requerido';
  }
  
  if (!data.message || data.message.trim() === '') {
    errors.message = 'Mensaje es requerido';
  }
  
  if (!['whatsapp', 'email', 'both'].includes(data.channel)) {
    errors.channel = 'Canal inválido';
  }
  
  if (data.channel === 'whatsapp' && data.message.length > 4096) {
    errors.message = 'WhatsApp debe ser menor a 4096 caracteres';
  }
  
  if (data.scheduled_at) {
    const scheduledDate = new Date(data.scheduled_at);
    if (scheduledDate <= new Date()) {
      errors.scheduled_at = 'Fecha debe ser futura';
    }
  }
  
  return errors;
}
```

### 2. Componente de Ejemplo (React/Vue)
```javascript
const [formData, setFormData] = useState({
  title: '',
  message: '',
  channel: 'email',
  filter_json: { target: 'all_users' },
  scheduled_at: null
});

const handleSubmit = async (e) => {
  e.preventDefault();
  
  try {
    const response = await fetch('/campaigns', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify(formData)
    });
    
    if (!response.ok) {
      const error = await response.json();
      console.error('Validation errors:', error.errors);
      return;
    }
    
    const result = await response.json();
    console.log('Campaign created:', result.data);
    alert('Campaña creada y enviada!');
    
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### 3. Selector de Canal
```javascript
<select value={channel} onChange={e => setChannel(e.target.value)}>
  <option value="email">📧 Email</option>
  <option value="whatsapp">� WhatsApp</option>
  <option value="both">📧� Ambos</option>
</select>
```

### 4. Selector de Audiencia
```javascript
<select value={filterTarget} onChange={e => updateFilter(e.target.value)}>
  <option value="all_users">Todos los usuarios</option>
  <option value="meeting_attendees">Asistentes de reunión</option>
</select>

{filterTarget === 'meeting_attendees' && (
  <select value={meetingId} onChange={e => setMeetingId(e.target.value)}>
    <option value="">Seleccionar reunión...</option>
    {meetings.map(m => (
      <option key={m.id} value={m.id}>{m.title}</option>
    ))}
  </select>
)}
```

### 5. Programador de Fecha
```javascript
<input 
  type="datetime-local"
  value={scheduledAt}
  min={new Date().toISOString().slice(0, 16)}
  onChange={e => setScheduledAt(e.target.value)}
/>
```

---

## 🎨 Casos de Uso Comunes

### Caso 1: Confirmación de Asistencia
```json
{
  "title": "Confirma tu Asistencia",
  "message": "¿Asistirás a la reunión del sábado? Responde SÍ o NO.",
  "channel": "whatsapp",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [25]
  }
}
```

### Caso 2: Newsletter Mensual
```json
{
  "title": "Newsletter - Octubre 2025",
  "message": "Resumen de actividades del mes...",
  "channel": "email",
  "filter_json": {
    "target": "all_users"
  }
}
```

### Caso 3: Recordatorio Automático
```json
{
  "title": "Recordatorio 24h antes",
  "message": "Mañana es la reunión. Te esperamos!",
  "channel": "both",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [30]
  },
  "scheduled_at": "2025-11-04T10:00:00Z"
}
```

### Caso 4: Alerta Urgente
```json
{
  "title": "URGENTE: Cambio de Horario",
  "message": "La reunión de hoy cambia de 3PM a 5PM. Disculpen las molestias.",
  "channel": "both"
}
```

### Caso 5: Campaña a Líderes Específicos (Lista VIP)
```json
{
  "title": "Reunión de Coordinadores",
  "message": "Los invitamos a la reunión de coordinación este viernes a las 6PM.",
  "channel": "both",
  "filter_json": {
    "target": "custom_list",
    "custom_recipients": [
      {"type": "email", "value": "coordinador.norte@example.com", "name": "Juan López"},
      {"type": "phone", "value": "+573001234567", "name": "María Pérez"},
      {"type": "email", "value": "coordinador.sur@example.com", "name": "Carlos Gómez"},
      {"type": "phone", "value": "+573009876543", "name": "Ana Martínez"}
    ]
  }
}
```

### Caso 6: Seguimiento a Múltiples Reuniones del Mes
```json
{
  "title": "Resumen del Mes",
  "message": "Gracias por participar en nuestras 4 reuniones este mes. Aquí el resumen de compromisos...",
  "channel": "email",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [45, 46, 47, 48]
  }
}
```

---

## 🔐 Permisos Requeridos

- Usuario debe estar autenticado (JWT token)
- Usuario debe pertenecer a un tenant
- La campaña se crea automáticamente para el tenant del usuario

---

## ⚙️ Comportamiento del Sistema

1. **Creación:** Campaign se crea con status `pending`
2. **Recipients:** Sistema genera automáticamente los destinatarios según filtros
3. **Queue:** Campaign se encola para envío (Job asíncrono)
4. **Envío:** 
   - Si `scheduled_at` es NULL → envía inmediatamente
   - Si `scheduled_at` tiene fecha → programa para esa hora
5. **Tracking:** Sistema actualiza `sent_count` y `failed_count` conforme envía

---

## 📱 Integración con Reuniones

Para enviar campaña después de crear una reunión:

```javascript
// 1. Crear reunión
const meeting = await createMeeting(meetingData);

// 2. Enviar campaña a los asistentes
const campaign = await createCampaign({
  title: `Invitación: ${meeting.title}`,
  message: `Te invitamos a ${meeting.title} el ${meeting.starts_at}`,
  channel: "both",
  filter_json: {
    target: "meeting_attendees",
    meeting_ids: [meeting.id]
  }
});
```

### Enviar a Asistentes de Múltiples Reuniones

```javascript
// Obtener IDs de reuniones del mes
const meetings = await getMeetingsThisMonth();
const meetingIds = meetings.map(m => m.id);

// Enviar resumen a todos los participantes
const campaign = await createCampaign({
  title: "Resumen del Mes",
  message: "Gracias por participar...",
  channel: "email",
  filter_json: {
    target: "meeting_attendees",
    meeting_ids: meetingIds  // [15, 18, 20, 22]
  }
});
```

### Enviar a Lista Personalizada + Validación

```javascript
const customRecipients = [
  { type: "email", value: "juan@example.com", name: "Juan" },
  { type: "phone", value: "+573001234567", name: "María" }
];

// Validar formato de teléfonos
const validRecipients = customRecipients.filter(r => {
  if (r.type === 'phone') {
    return /^\+57\d{10}$/.test(r.value); // Validar formato Colombia
  }
  if (r.type === 'email') {
    return /^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(r.value);
  }
  return false;
});

const campaign = await createCampaign({
  title: "Invitación Especial",
  message: "Los invitamos...",
  channel: "both",
  filter_json: {
    target: "custom_list",
    custom_recipients: validRecipients
  }
});
```

---

## 🚀 Mejores Prácticas

1. **WhatsApp:** Mantener mensajes claros y concisos (máximo 4096 caracteres)
2. **Programación:** Dar al menos 1 hora de anticipación al programar
3. **Filtros:** Validar que `meeting_ids` existan antes de usar filtro
4. **Canales:** Usar `both` solo cuando sea realmente necesario
5. **Testing:** Probar primero con pocos destinatarios
6. **Formato:** WhatsApp soporta formato markdown (*negrita*, _cursiva_, ~tachado~)

---

## 📞 Soporte

Para dudas sobre implementación, referirse a:
- `app/Http/Controllers/CampaignController.php`
- `app/Services/CampaignService.php`
- `app/Models/Campaign.php`
