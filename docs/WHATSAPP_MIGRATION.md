# Cambio de SMS a WhatsApp en Campañas

## ✅ Cambios Realizados

### 1. **Validaciones (Requests)**
- `StoreCampaignRequest.php`: `channel` acepta `whatsapp`, `email`, `both`
- `UpdateCampaignRequest.php`: `channel` acepta `whatsapp`, `email`, `both`

### 2. **Lógica de Negocio (CampaignService.php)**
- Cambio de `'sms'` a `'whatsapp'` en todos los métodos
- `extractRecipientsFromUsers()`: `recipient_type` = `'whatsapp'`
- `extractRecipientsFromAttendees()`: `recipient_type` = `'whatsapp'`
- `extractCustomRecipients()`: `recipient_type` = `'whatsapp'`
- `sendToRecipient()`: Ahora busca `WhatsAppInterface` en lugar de `SMSInterface`

### 3. **Base de Datos (Migración)**
- `create_campaigns_table.php`: Enum `channel` = `['whatsapp', 'email', 'both']`

### 4. **Documentación**
- `CAMPAIGNS_API_EXAMPLES.md`: 
  - Todas las referencias de "SMS" cambiadas a "WhatsApp"
  - Ejemplos actualizados con `channel: "whatsapp"`
  - Límite de caracteres actualizado (160 → 4096)
  - Emojis actualizados (📱 → 💬)
  - Mención de soporte para formato Markdown

- `CAMPAIGNS_QUICK_GUIDE.md`:
  - Canal actualizado de SMS a WhatsApp
  - Ejemplos actualizados

---

## 📋 Valores Actualizados

### Antes (SMS)
```json
{
  "channel": "sms"  // ❌ Ya no válido
}
```

### Ahora (WhatsApp)
```json
{
  "channel": "whatsapp"  // ✅ Correcto
}
```

---

## 🎯 Canales Válidos

| Canal | Descripción |
|-------|-------------|
| `"email"` | Solo envía emails |
| `"whatsapp"` | Solo envía WhatsApp |
| `"both"` | Envía por ambos canales |

---

## 💡 Características de WhatsApp

### Límite de Caracteres
- **SMS:** 160 caracteres
- **WhatsApp:** 4096 caracteres ✅

### Formato de Mensaje
WhatsApp soporta formato Markdown:
- `*texto*` → **negrita**
- `_texto_` → _cursiva_
- `~texto~` → ~tachado~
- ` ```código``` ` → bloque de código

### Ejemplo de Mensaje Formateado
```json
{
  "title": "Invitación Especial",
  "message": "*Estimado líder*\n\nTe invitamos a nuestra reunión:\n\n_Fecha:_ Sábado 2 de noviembre\n_Hora:_ 10:00 AM\n_Lugar:_ Casa Comunal\n\n*¡No faltes!*",
  "channel": "whatsapp"
}
```

---

## 🔧 Tareas Pendientes

### Backend
- [ ] Implementar `App\Services\WhatsApp\WhatsAppInterface`
- [ ] Crear servicio concreto para proveedor de WhatsApp (Twilio, Meta Business API, etc.)
- [ ] Configurar credenciales en `.env`

### Base de Datos
Si ya tienes datos existentes con `channel='sms'`, ejecutar migración:

```sql
UPDATE campaigns SET channel = 'whatsapp' WHERE channel = 'sms';
UPDATE campaign_recipients SET recipient_type = 'whatsapp' WHERE recipient_type = 'sms';
```

---

## 📱 Integración con Proveedores

### Opción 1: Twilio WhatsApp API
```php
// .env
TWILIO_SID=your_account_sid
TWILIO_TOKEN=your_auth_token
TWILIO_WHATSAPP_FROM=whatsapp:+14155238886

// WhatsAppService.php
use Twilio\Rest\Client;

class TwilioWhatsAppService implements WhatsAppInterface
{
    public function send(string $to, string $message): bool
    {
        $client = new Client(config('twilio.sid'), config('twilio.token'));
        
        $client->messages->create(
            "whatsapp:$to",
            [
                'from' => config('twilio.whatsapp_from'),
                'body' => $message
            ]
        );
        
        return true;
    }
}
```

### Opción 2: Meta Business API (WhatsApp Cloud API)
```php
// .env
WHATSAPP_TOKEN=your_access_token
WHATSAPP_PHONE_NUMBER_ID=your_phone_number_id

// MetaWhatsAppService.php
use GuzzleHttp\Client;

class MetaWhatsAppService implements WhatsAppInterface
{
    public function send(string $to, string $message): bool
    {
        $client = new Client();
        
        $response = $client->post("https://graph.facebook.com/v18.0/" . config('whatsapp.phone_number_id') . "/messages", [
            'headers' => [
                'Authorization' => 'Bearer ' . config('whatsapp.token'),
                'Content-Type' => 'application/json',
            ],
            'json' => [
                'messaging_product' => 'whatsapp',
                'to' => $to,
                'type' => 'text',
                'text' => ['body' => $message]
            ]
        ]);
        
        return $response->getStatusCode() === 200;
    }
}
```

---

## 🧪 Testing

### Ejemplo de Request Actualizado
```bash
curl -X POST http://localhost:8000/api/v1/campaigns \
  -H "Authorization: Bearer YOUR_TOKEN" \
  -H "Content-Type: application/json" \
  -d '{
    "title": "Prueba WhatsApp",
    "message": "*Hola!* Este es un mensaje de prueba vía WhatsApp",
    "channel": "whatsapp",
    "filter_json": {
      "target": "custom_list",
      "custom_recipients": [
        {
          "type": "phone",
          "value": "+573001234567",
          "name": "Usuario Prueba"
        }
      ]
    }
  }'
```

---

## 📊 Impacto en Frontend

### Actualizar Selectores
```javascript
// Antes
<option value="sms">📱 SMS</option>

// Ahora
<option value="whatsapp">💬 WhatsApp</option>
```

### Actualizar Validaciones
```javascript
// Antes
if (data.channel === 'sms' && data.message.length > 160) {
  errors.message = 'SMS debe ser menor a 160 caracteres';
}

// Ahora
if (data.channel === 'whatsapp' && data.message.length > 4096) {
  errors.message = 'WhatsApp debe ser menor a 4096 caracteres';
}
```

### Editor de Mensaje con Formato
```javascript
// Agregar botones para formato Markdown
<button onClick={() => wrapText('*')}>Negrita</button>
<button onClick={() => wrapText('_')}>Cursiva</button>
<button onClick={() => wrapText('~')}>Tachado</button>

const wrapText = (char) => {
  const textarea = document.getElementById('message');
  const start = textarea.selectionStart;
  const end = textarea.selectionEnd;
  const text = textarea.value;
  const selectedText = text.substring(start, end);
  
  textarea.value = text.substring(0, start) + 
                   char + selectedText + char + 
                   text.substring(end);
};
```

---

## ✅ Checklist de Migración

- [x] Actualizar validaciones en Requests
- [x] Actualizar CampaignService
- [x] Actualizar migración de campaigns
- [x] Actualizar documentación API
- [x] Actualizar guía rápida
- [ ] Implementar WhatsAppInterface
- [ ] Configurar proveedor de WhatsApp
- [ ] Actualizar frontend (selectores, validaciones)
- [ ] Migrar datos existentes (si aplica)
- [ ] Probar envíos reales
- [ ] Documentar configuración de proveedor

---

## 🚀 Próximos Pasos

1. **Implementar servicio de WhatsApp** con proveedor elegido (Twilio o Meta)
2. **Configurar credenciales** en archivo `.env`
3. **Actualizar frontend** para usar "whatsapp" en lugar de "sms"
4. **Probar campañas** con números reales
5. **Monitorear** tasas de entrega y errores
