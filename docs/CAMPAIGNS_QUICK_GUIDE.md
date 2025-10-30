# 📨 Guía Rápida - Campañas API

## 🎯 3 Formas de Enviar Campañas

### 1️⃣ Asistentes de UNA o VARIAS Reuniones
Perfecto para seguimiento post-reunión o campañas a participantes activos.

```json
{
  "title": "Seguimiento Reuniones",
  "message": "Gracias por participar en nuestras reuniones...",
  "channel": "email",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [15, 18, 20, 22]
  }
}
```

**Una sola reunión:** `"meeting_ids": [15]`  
**Múltiples reuniones:** `"meeting_ids": [15, 18, 20]`  
**Duplicados:** Se eliminan automáticamente (si alguien asistió a varias reuniones, recibe solo 1 mensaje)

---

### 2️⃣ Lista Personalizada (Emails/Teléfonos Específicos)
Para contactos VIP, externos, autoridades, o personas no registradas.

```json
{
  "title": "Invitación Especial",
  "message": "Los invitamos a reunión de coordinadores...",
  "channel": "both",
  "filter_json": {
    "target": "custom_list",
    "custom_recipients": [
      {
        "type": "email",
        "value": "coordinador@example.com",
        "name": "Juan López"
      },
      {
        "type": "phone",
        "value": "+573001234567",
        "name": "María Pérez"
      }
    ]
  }
}
```

**Campos obligatorios:**
- `type`: `"email"` o `"phone"`
- `value`: Email o teléfono con código país (+57...)

**Campo opcional:**
- `name`: Nombre del destinatario

---

### 3️⃣ Todos los Usuarios del Tenant
Para anuncios generales o newsletters.

```json
{
  "title": "Anuncio General",
  "message": "Les informamos sobre las nuevas actividades...",
  "channel": "email",
  "filter_json": {
    "target": "all_users"
  }
}
```

O simplemente omite `filter_json`:

```json
{
  "title": "Anuncio General",
  "message": "Les informamos...",
  "channel": "email"
}
```

---

## 📅 Programar Envío (Opcional)

Agrega `scheduled_at` para enviar en fecha/hora futura:

```json
{
  "title": "Recordatorio Reunión",
  "message": "Mañana es la reunión!",
  "channel": "sms",
  "filter_json": {
    "target": "meeting_attendees",
    "meeting_ids": [25]
  },
  "scheduled_at": "2025-11-05T18:00:00Z"
}
```

**Formato:** ISO 8601 en UTC (`YYYY-MM-DDTHH:MM:SSZ`)  
**Restricción:** Debe ser fecha futura

---

## 📱 Canales Disponibles

| Canal | Valor | Descripción |
|-------|-------|-------------|
| Solo Email | `"email"` | Envía solo a emails |
| Solo WhatsApp | `"whatsapp"` | Envía solo a teléfonos vía WhatsApp |
| Ambos | `"both"` | Envía por email Y WhatsApp si están disponibles |

---

## ✅ Respuesta Exitosa

```json
{
  "data": {
    "id": 25,
    "title": "Seguimiento Reuniones",
    "message": "Gracias por participar...",
    "channel": "email",
    "status": "pending",
    "total_recipients": 45,
    "sent_count": 0,
    "failed_count": 0,
    "scheduled_at": null,
    "created_at": "2025-10-30T12:00:00.000000Z"
  },
  "message": "Campaign created and queued for sending"
}
```

---

## 🚨 Errores Comunes

### Meeting IDs inválidos
```json
{
  "message": "The filter_json.meeting_ids.0 field must exist in meetings table."
}
```
✅ **Solución:** Verificar que los IDs de reunión existan.

### Tipo de destinatario inválido
```json
{
  "message": "The selected filter_json.custom_recipients.0.type is invalid."
}
```
✅ **Solución:** Usar solo `"email"` o `"phone"` (case-sensitive).

### Fecha programada en el pasado
```json
{
  "message": "The scheduled at field must be a date after now."
}
```
✅ **Solución:** Usar fecha futura o omitir `scheduled_at` para envío inmediato.

---

## 💡 Casos de Uso Más Comunes

### Seguimiento después de una reunión
```javascript
// Después de crear/completar reunión
await createCampaign({
  title: "Gracias por Asistir",
  message: "Aquí el resumen y compromisos...",
  channel: "email",
  filter_json: {
    target: "meeting_attendees",
    meeting_ids: [meetingId]
  }
});
```

### Recordatorio 24h antes de evento
```javascript
const tomorrow = new Date();
tomorrow.setDate(tomorrow.getDate() + 1);
tomorrow.setHours(10, 0, 0, 0);

await createCampaign({
  title: "Recordatorio: Reunión Mañana",
  message: "Te esperamos mañana a las 3PM",
  channel: "whatsapp",
  filter_json: {
    target: "meeting_attendees",
    meeting_ids: [upcomingMeetingId]
  },
  scheduled_at: tomorrow.toISOString()
});
```

### Invitación a líderes específicos
```javascript
const leaders = [
  { type: "email", value: "leader1@example.com", name: "Juan" },
  { type: "phone", value: "+573001234567", name: "María" }
];

await createCampaign({
  title: "Reunión de Coordinación",
  message: "Los invitamos este viernes a las 6PM",
  channel: "both",
  filter_json: {
    target: "custom_list",
    custom_recipients: leaders
  }
});
```

### Resumen mensual a participantes activos
```javascript
const meetingsThisMonth = await getMeetings({ month: 10 });
const meetingIds = meetingsThisMonth.map(m => m.id);

await createCampaign({
  title: "Resumen Octubre 2025",
  message: "Gracias por participar en nuestras reuniones este mes...",
  channel: "email",
  filter_json: {
    target: "meeting_attendees",
    meeting_ids: meetingIds
  }
});
```

---

## 📚 Documentación Completa

Ver `CAMPAIGNS_API_EXAMPLES.md` para:
- 12+ ejemplos detallados
- Validaciones completas
- Componentes React/Vue
- Mejores prácticas
- Todos los endpoints relacionados

---

## 🔗 Endpoints Relacionados

```
GET    /api/v1/campaigns              # Listar campañas
GET    /api/v1/campaigns/{id}         # Ver campaña
POST   /api/v1/campaigns              # Crear campaña
PUT    /api/v1/campaigns/{id}         # Actualizar (solo pending)
DELETE /api/v1/campaigns/{id}         # Eliminar
POST   /api/v1/campaigns/{id}/send    # Forzar envío
POST   /api/v1/campaigns/{id}/cancel  # Cancelar
GET    /api/v1/campaigns/{id}/recipients  # Ver destinatarios
```

---

## ⚡ Quick Reference

| Necesito enviar a... | filter_json |
|---------------------|-------------|
| Una reunión | `{ "target": "meeting_attendees", "meeting_ids": [15] }` |
| Varias reuniones | `{ "target": "meeting_attendees", "meeting_ids": [15, 18, 20] }` |
| Contactos específicos | `{ "target": "custom_list", "custom_recipients": [...] }` |
| Todos los usuarios | `{ "target": "all_users" }` o sin `filter_json` |
