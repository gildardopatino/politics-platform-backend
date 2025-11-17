# 🚀 WhatsApp Evolution API - Quick Start

## Migración Completada ✅

El sistema ha sido **completamente migrado de N8N a Evolution API** con soporte para múltiples instancias y balanceo de carga.

---

## ⚡ Inicio Rápido

### 1. Crear Primera Instancia

```bash
# Usando cURL
curl -X POST "https://tu-api.com/api/v1/tenants/1/whatsapp-instances" \
  -H "Authorization: Bearer TU_TOKEN_SUPERADMIN" \
  -H "Content-Type: application/json" \
  -d '{
    "phone_number": "+573116677099",
    "instance_name": "whatsapp-primary",
    "evolution_api_key": "TU_API_KEY",
    "evolution_api_url": "https://tu-evolution-api.com",
    "daily_message_limit": 1000,
    "is_active": true
  }'
```

### 2. Verificar Instancia

```bash
curl -X GET "https://tu-api.com/api/v1/tenants/1/whatsapp-instances" \
  -H "Authorization: Bearer TU_TOKEN_SUPERADMIN"
```

### 3. Probar Envío

```bash
cd /ruta/a/tu/proyecto
php test-evolution-api.php
```

---

## 📚 Documentación Completa

| Documento | Descripción |
|-----------|-------------|
| [WHATSAPP_MIGRATION_SUMMARY.md](./WHATSAPP_MIGRATION_SUMMARY.md) | ⭐ **Resumen ejecutivo** de la migración |
| [WHATSAPP_EVOLUTION_API_MIGRATION.md](./WHATSAPP_EVOLUTION_API_MIGRATION.md) | Guía técnica detallada |
| [WHATSAPP_INSTANCES_API.md](./WHATSAPP_INSTANCES_API.md) | Referencia completa de APIs |
| [WHATSAPP_INSTANCES_JSON_EXAMPLES.md](./WHATSAPP_INSTANCES_JSON_EXAMPLES.md) | Ejemplos JSON |
| [WHATSAPP_MEDIA_API.md](./WHATSAPP_MEDIA_API.md) | ⭐ **Guía de envío de medios** (imágenes, videos, documentos) |

---

## 🔧 Cambios en tu Código

### ✅ Automático (Sin cambios necesarios)

**Todos estos flujos ya funcionan automáticamente**:
- ✅ Notificaciones de reuniones (`MeetingController`)
- ✅ Asignación de recursos (`ResourceAllocationController`)
- ✅ Recordatorios de compromisos (`SendCommitmentReminderJob`)
- ✅ Campañas masivas (`CampaignService`)

### ℹ️ Cambio en el Servicio

```php
// ANTES
$whatsappService->sendMessage($phone, $message, $userToken);

// AHORA
$whatsappService->sendMessage($phone, $message, $tenantId);
```

**Solo necesitas actualizar código personalizado que llame directamente al servicio**.

---

## 🎯 Características Nuevas

### 1. Múltiples Instancias
Cada tenant puede tener varios números de WhatsApp:
- `whatsapp-primary` (1000 msg/día)
- `whatsapp-secondary` (1000 msg/día)
- `whatsapp-backup` (500 msg/día)

### 2. Balanceo Automático
El sistema selecciona automáticamente la mejor instancia:
- Instancias activas
- Con cuota disponible
- Menos usadas primero
- Round-robin entre disponibles

### 3. Límites Diarios
- Configurable por instancia
- Reset automático a medianoche
- Reset manual vía API
- Tracking en tiempo real

### 4. Gestión Super Admin
- CRUD completo de instancias
- Activar/Desactivar
- Resetear contadores
- Ver estadísticas

### 5. Envío de Medios ⭐ NUEVO
- Imágenes (PNG, JPG, GIF, WebP)
- Videos (MP4, AVI, MOV)
- Documentos (PDF, DOCX, XLSX, etc.)
- Soporta URLs y Base64
- Métodos especializados por tipo

---

## 📊 Monitoreo

### Ver Estadísticas

```bash
# Instancia específica
GET /api/v1/tenants/1/whatsapp-instances/1/statistics

# Todas las instancias del tenant
GET /api/v1/tenants/1/whatsapp-instances
```

### Logs en Tiempo Real

```bash
# Envíos exitosos
tail -f storage/logs/laravel.log | grep "WhatsApp message sent successfully"

# Errores
tail -f storage/logs/laravel.log | grep "Failed to send WhatsApp"

# Sin instancias
tail -f storage/logs/laravel.log | grep "No WhatsApp instances available"
```

---

## ⚠️ Troubleshooting Rápido

### No se envían mensajes
```bash
# 1. Verificar que existan instancias
GET /api/v1/tenants/1/whatsapp-instances

# 2. Verificar que estén activas
POST /api/v1/tenants/1/whatsapp-instances/1/toggle-active

# 3. Verificar cuota disponible
GET /api/v1/tenants/1/whatsapp-instances/1/statistics

# 4. Resetear contador si está lleno
POST /api/v1/tenants/1/whatsapp-instances/1/reset-counter
```

### Evolution API retorna error
```bash
# Verificar conectividad
curl -X GET "https://tu-evolution-api.com/instance/status/tu-instancia" \
  -H "apikey: TU_API_KEY"

# Actualizar API key si cambió
PUT /api/v1/tenants/1/whatsapp-instances/1
{
  "evolution_api_key": "NUEVA_KEY"
}
```

---

## 🧪 Testing

```bash
# Ejecutar test de mensajes de texto
php test-evolution-api.php

# Ejecutar test de medios (imágenes, videos, documentos)
php test-evolution-media.php

# Incluye:
# ✓ Verificación de instancias
# ✓ Verificación de cuotas
# ✓ Envío de mensaje real
# ✓ Verificación de contador
# ✓ Estadísticas
# ✓ Balanceo de carga (opcional)
```

---

## 💡 Ejemplos de Uso

### Enviar Mensaje de Texto
```php
use App\Services\WhatsAppNotificationService;

$whatsappService = app(WhatsAppNotificationService::class);
$whatsappService->sendMessage(
    '+573116677099',
    'Hola! Este es un mensaje de prueba',
    1 // tenantId
);
```

### Enviar Imagen
```php
$whatsappService->sendImage(
    '+573116677099',
    'https://example.com/image.png',
    1, // tenantId
    '📸 Imagen de la reunión'
);
```

### Enviar Video
```php
$whatsappService->sendVideo(
    '+573116677099',
    'https://example.com/video.mp4',
    1, // tenantId
    '🎬 Video del evento'
);
```

### Enviar Documento
```php
$whatsappService->sendDocument(
    '+573116677099',
    'https://example.com/reporte.pdf',
    1, // tenantId
    'reporte-noviembre.pdf',
    '📄 Reporte mensual'
);
```

---

## 📦 Archivos Importantes

### Nuevos
- `app/Models/TenantWhatsAppInstance.php` - Modelo de instancias
- `app/Http/Controllers/Api/V1/TenantWhatsAppInstanceController.php` - API CRUD
- `app/Http/Requests/StoreWhatsAppInstanceRequest.php` - Validación crear
- `app/Http/Requests/UpdateWhatsAppInstanceRequest.php` - Validación actualizar
- `test-evolution-api.php` - Script de prueba

### Modificados
- `app/Services/WhatsAppNotificationService.php` - Integración Evolution API
- `app/Http/Controllers/Api/V1/MeetingController.php` - Usa tenantId
- `app/Http/Controllers/Api/V1/ResourceAllocationController.php` - Usa tenantId
- `app/Jobs/SendCommitmentReminderJob.php` - Usa tenantId
- `app/Services/CampaignService.php` - Usa tenantId

---

## 🎉 Listo para Producción

✅ Sin errores de compilación  
✅ Backward compatible  
✅ Documentación completa  
✅ Script de prueba incluido  
✅ Balanceo de carga implementado  
✅ Gestión de cuotas funcional  

**Siguiente paso**: Crear instancias vía API y empezar a enviar mensajes!

---

## 🆘 Ayuda

1. Lee el [Resumen de Migración](./WHATSAPP_MIGRATION_SUMMARY.md)
2. Revisa [Guía Técnica](./WHATSAPP_EVOLUTION_API_MIGRATION.md)
3. Consulta [API Reference](./WHATSAPP_INSTANCES_API.md)
4. Ejecuta `php test-evolution-api.php`
