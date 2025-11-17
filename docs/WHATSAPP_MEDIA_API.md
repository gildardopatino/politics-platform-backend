# WhatsApp Media API - Guía de Uso

## 📸 Envío de Medios (Imágenes, Videos, Documentos)

El servicio de WhatsApp ahora soporta el envío de medios además de mensajes de texto.

---

## 🎯 Métodos Disponibles

### 1. sendMedia() - Método General

Método principal para enviar cualquier tipo de medio.

```php
public function sendMedia(
    string $phone,        // Número de teléfono (+573116677099)
    string $mediaType,    // 'image', 'video', o 'document'
    string $media,        // URL o base64 del medio
    int $tenantId,        // ID del tenant
    ?string $caption = null,    // Texto descriptivo opcional
    ?string $fileName = null,   // Nombre del archivo (recomendado para documentos)
    ?string $mimeType = null    // Tipo MIME (opcional, tiene defaults)
): bool
```

**Tipos de Media Soportados**:
- `image`: Imágenes (PNG, JPG, GIF, WebP)
- `video`: Videos (MP4, AVI, MOV)
- `document`: Documentos (PDF, DOC, XLS, etc.)

---

## 🖼️ Método 1: sendImage()

Envía imágenes de forma simplificada.

### Uso Básico

```php
use App\Services\WhatsAppNotificationService;

$whatsappService = app(WhatsAppNotificationService::class);

// Enviar imagen desde URL
$success = $whatsappService->sendImage(
    phone: '+573116677099',
    imageUrl: 'https://example.com/image.png',
    tenantId: 1,
    caption: 'Esta es una imagen de prueba'
);
```

### Parámetros

```php
public function sendImage(
    string $phone,              // Teléfono destino
    string $imageUrl,           // URL o base64 de la imagen
    int $tenantId,              // ID del tenant
    ?string $caption = null,    // Texto descriptivo (opcional)
    ?string $fileName = null,   // Nombre del archivo (opcional)
    ?string $mimeType = null    // Tipo MIME (default: image/png)
): bool
```

### Ejemplos

#### Imagen desde URL
```php
$whatsappService->sendImage(
    '+573116677099',
    'https://mi-servidor.com/fotos/reunion.jpg',
    1,
    '📸 Foto de la reunión del 15 de noviembre'
);
```

#### Imagen con formato específico
```php
$whatsappService->sendImage(
    '+573116677099',
    'https://mi-servidor.com/graficos/estadisticas.webp',
    1,
    '📊 Estadísticas de campaña',
    'estadisticas-nov.webp',
    'image/webp'
);
```

#### Imagen base64 (pequeñas)
```php
$imageBase64 = base64_encode(file_get_contents('/ruta/imagen.png'));

$whatsappService->sendImage(
    '+573116677099',
    $imageBase64,
    1,
    '🖼️ Imagen adjunta',
    'documento.png',
    'image/png'
);
```

### Formatos Soportados
- PNG: `image/png`
- JPEG: `image/jpeg`
- GIF: `image/gif`
- WebP: `image/webp`

---

## 🎥 Método 2: sendVideo()

Envía videos de forma simplificada.

### Uso Básico

```php
$success = $whatsappService->sendVideo(
    phone: '+573116677099',
    videoUrl: 'https://example.com/video.mp4',
    tenantId: 1,
    caption: 'Video de la reunión'
);
```

### Parámetros

```php
public function sendVideo(
    string $phone,              // Teléfono destino
    string $videoUrl,           // URL o base64 del video
    int $tenantId,              // ID del tenant
    ?string $caption = null,    // Texto descriptivo (opcional)
    ?string $fileName = null,   // Nombre del archivo (opcional)
    ?string $mimeType = null    // Tipo MIME (default: video/mp4)
): bool
```

### Ejemplos

#### Video desde URL
```php
$whatsappService->sendVideo(
    '+573116677099',
    'https://mi-servidor.com/videos/evento.mp4',
    1,
    '🎬 Video del evento político'
);
```

#### Video con formato específico
```php
$whatsappService->sendVideo(
    '+573116677099',
    'https://mi-servidor.com/videos/discurso.avi',
    1,
    '🎤 Discurso completo',
    'discurso-nov-2025.avi',
    'video/x-msvideo'
);
```

### Formatos Soportados
- MP4: `video/mp4`
- AVI: `video/x-msvideo`
- MOV: `video/quicktime`
- WebM: `video/webm`

---

## 📄 Método 3: sendDocument()

Envía documentos (PDF, Word, Excel, etc.).

### Uso Básico

```php
$success = $whatsappService->sendDocument(
    phone: '+573116677099',
    documentUrl: 'https://example.com/reporte.pdf',
    tenantId: 1,
    fileName: 'reporte-mensual.pdf',
    caption: 'Reporte mensual de noviembre'
);
```

### Parámetros

```php
public function sendDocument(
    string $phone,              // Teléfono destino
    string $documentUrl,        // URL o base64 del documento
    int $tenantId,              // ID del tenant
    string $fileName,           // Nombre del archivo (REQUERIDO)
    ?string $caption = null,    // Texto descriptivo (opcional)
    ?string $mimeType = null    // Tipo MIME (default: application/pdf)
): bool
```

### Ejemplos

#### PDF desde URL
```php
$whatsappService->sendDocument(
    '+573116677099',
    'https://mi-servidor.com/reportes/nov-2025.pdf',
    1,
    'reporte-noviembre-2025.pdf',
    '📊 Reporte de resultados electorales'
);
```

#### Excel desde URL
```php
$whatsappService->sendDocument(
    '+573116677099',
    'https://mi-servidor.com/datos/votantes.xlsx',
    1,
    'base-votantes.xlsx',
    '📑 Base de datos actualizada',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet'
);
```

#### Word desde URL
```php
$whatsappService->sendDocument(
    '+573116677099',
    'https://mi-servidor.com/propuestas/plan.docx',
    1,
    'plan-gobierno.docx',
    '📝 Plan de gobierno',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'
);
```

#### Documento base64
```php
$pdfContent = file_get_contents('/ruta/documento.pdf');
$pdfBase64 = base64_encode($pdfContent);

$whatsappService->sendDocument(
    '+573116677099',
    $pdfBase64,
    1,
    'contrato.pdf',
    '📄 Contrato adjunto',
    'application/pdf'
);
```

### Formatos Soportados
- PDF: `application/pdf`
- Word (DOCX): `application/vnd.openxmlformats-officedocument.wordprocessingml.document`
- Word (DOC): `application/msword`
- Excel (XLSX): `application/vnd.openxmlformats-officedocument.spreadsheetml.sheet`
- Excel (XLS): `application/vnd.ms-excel`
- PowerPoint (PPTX): `application/vnd.openxmlformats-officedocument.presentationml.presentation`
- Text: `text/plain`
- CSV: `text/csv`

---

## 🔧 Método 4: sendMedia() - Avanzado

Para casos especiales donde necesitas control total.

### Uso

```php
$success = $whatsappService->sendMedia(
    phone: '+573116677099',
    mediaType: 'image',
    media: 'https://example.com/banner.png',
    tenantId: 1,
    caption: 'Banner de campaña',
    fileName: 'banner-nov.png',
    mimeType: 'image/png'
);
```

### Ejemplos por Tipo

#### Imagen PNG
```php
$whatsappService->sendMedia(
    '+573116677099',
    'image',
    'https://mi-servidor.com/imagenes/flyer.png',
    1,
    '📢 Nuevo flyer de campaña',
    'flyer-2025.png',
    'image/png'
);
```

#### Video MP4
```php
$whatsappService->sendMedia(
    '+573116677099',
    'video',
    'https://mi-servidor.com/videos/spot.mp4',
    1,
    '🎬 Spot publicitario',
    'spot-tv.mp4',
    'video/mp4'
);
```

#### Documento PDF
```php
$whatsappService->sendMedia(
    '+573116677099',
    'document',
    'https://mi-servidor.com/docs/programa.pdf',
    1,
    '📄 Programa de gobierno',
    'programa-2025.pdf',
    'application/pdf'
);
```

---

## 💡 Casos de Uso

### 1. Enviar Reporte Diario con Gráfico

```php
// En un Job o Controller
public function sendDailyReport($userId, $tenantId)
{
    $user = User::find($userId);
    $whatsappService = app(WhatsAppNotificationService::class);
    
    // Generar gráfico (ejemplo con Chart.js o similar)
    $chartUrl = $this->generateChartUrl([
        'labels' => ['Lun', 'Mar', 'Mié', 'Jue', 'Vie'],
        'data' => [120, 150, 180, 220, 250]
    ]);
    
    // Enviar mensaje de texto
    $whatsappService->sendMessage(
        $user->phone,
        "📊 *Reporte Diario*\n\nResultados de la semana:",
        $tenantId
    );
    
    sleep(1);
    
    // Enviar gráfico
    $whatsappService->sendImage(
        $user->phone,
        $chartUrl,
        $tenantId,
        "Gráfico de avance semanal"
    );
}
```

### 2. Enviar Material de Campaña

```php
public function sendCampaignMaterial($campaignId)
{
    $campaign = Campaign::with('recipients')->find($campaignId);
    $whatsappService = app(WhatsAppNotificationService::class);
    
    foreach ($campaign->recipients as $recipient) {
        // Enviar flyer
        $whatsappService->sendImage(
            $recipient->phone,
            $campaign->flyer_url,
            $campaign->tenant_id,
            "📢 Material de campaña para el evento del {$campaign->event_date}"
        );
        
        sleep(2); // Esperar entre envíos
        
        // Enviar PDF con detalles
        if ($campaign->detailed_pdf_url) {
            $whatsappService->sendDocument(
                $recipient->phone,
                $campaign->detailed_pdf_url,
                $campaign->tenant_id,
                'detalles-evento.pdf',
                '📄 Información detallada del evento'
            );
        }
        
        sleep(2);
    }
}
```

### 3. Enviar Acta de Reunión

```php
public function sendMeetingMinutes($meetingId)
{
    $meeting = Meeting::with('participants')->find($meetingId);
    $whatsappService = app(WhatsAppNotificationService::class);
    
    // Generar PDF del acta
    $pdfPath = $this->generateMeetingMinutesPdf($meeting);
    $pdfUrl = Storage::url($pdfPath);
    
    foreach ($meeting->participants as $participant) {
        $message = "📋 *Acta de Reunión*\n\n";
        $message .= "Tema: {$meeting->title}\n";
        $message .= "Fecha: {$meeting->date->format('d/m/Y')}\n";
        $message .= "\nSe adjunta el acta completa.";
        
        // Enviar mensaje
        $whatsappService->sendMessage(
            $participant->phone,
            $message,
            $meeting->tenant_id
        );
        
        sleep(1);
        
        // Enviar acta en PDF
        $whatsappService->sendDocument(
            $participant->phone,
            $pdfUrl,
            $meeting->tenant_id,
            "acta-{$meeting->date->format('Y-m-d')}.pdf",
            'Acta de la reunión'
        );
        
        sleep(2);
    }
}
```

### 4. Enviar Video de Capacitación

```php
public function sendTrainingVideo($userId, $tenantId, $videoId)
{
    $user = User::find($userId);
    $video = TrainingVideo::find($videoId);
    $whatsappService = app(WhatsAppNotificationService::class);
    
    // Enviar mensaje introductorio
    $message = "🎓 *Nuevo Material de Capacitación*\n\n";
    $message .= "Título: {$video->title}\n";
    $message .= "Duración: {$video->duration} minutos\n";
    $message .= "Categoría: {$video->category}\n\n";
    $message .= "Se enviará el video a continuación.";
    
    $whatsappService->sendMessage(
        $user->phone,
        $message,
        $tenantId
    );
    
    sleep(2);
    
    // Enviar video
    $whatsappService->sendVideo(
        $user->phone,
        $video->url,
        $tenantId,
        "🎬 {$video->title}",
        $video->filename,
        'video/mp4'
    );
}
```

---

## 📊 Integración con Sistema Existente

### En MeetingController (agregar envío de imágenes)

```php
use App\Services\WhatsAppNotificationService;

public function shareAttachment(Request $request, $meetingId)
{
    $meeting = Meeting::findOrFail($meetingId);
    $whatsappService = app(WhatsAppNotificationService::class);
    
    $attachment = $request->file('attachment');
    $path = $attachment->store('meeting-attachments', 'public');
    $url = Storage::url($path);
    
    // Enviar a todos los participantes
    foreach ($meeting->participants as $participant) {
        $type = $attachment->getClientMimeType();
        
        if (str_starts_with($type, 'image/')) {
            $whatsappService->sendImage(
                $participant->phone,
                $url,
                $meeting->tenant_id,
                "📸 Adjunto de reunión: {$meeting->title}"
            );
        } elseif (str_starts_with($type, 'video/')) {
            $whatsappService->sendVideo(
                $participant->phone,
                $url,
                $meeting->tenant_id,
                "🎥 Video de reunión: {$meeting->title}"
            );
        } else {
            $whatsappService->sendDocument(
                $participant->phone,
                $url,
                $meeting->tenant_id,
                $attachment->getClientOriginalName(),
                "📄 Documento de reunión: {$meeting->title}"
            );
        }
    }
}
```

---

## ⚠️ Consideraciones Importantes

### 1. Tamaño de Archivos
- **URL**: Evolution API descargará el archivo (recomendado para archivos grandes)
- **Base64**: Limitado por memoria PHP y tamaño de request (máx ~16MB típicamente)
- **Recomendación**: Usar URLs para archivos > 1MB

### 2. Performance
```php
// Esperar entre envíos para evitar rate limiting
foreach ($recipients as $recipient) {
    $whatsappService->sendImage($recipient->phone, $imageUrl, $tenantId);
    sleep(2); // Esperar 2 segundos entre envíos
}
```

### 3. Validación de URLs
```php
// Validar que la URL sea accesible antes de enviar
if (!filter_var($imageUrl, FILTER_VALIDATE_URL)) {
    throw new \Exception('URL de imagen inválida');
}

// Verificar que el archivo existe (opcional)
$headers = get_headers($imageUrl);
if (strpos($headers[0], '200') === false) {
    throw new \Exception('Archivo no accesible');
}
```

### 4. Manejo de Errores
```php
try {
    $success = $whatsappService->sendImage(
        $phone,
        $imageUrl,
        $tenantId,
        $caption
    );
    
    if (!$success) {
        Log::warning('Failed to send image', [
            'phone' => $phone,
            'url' => $imageUrl
        ]);
        // Implementar fallback o reintento
    }
} catch (\Exception $e) {
    Log::error('Exception sending image: ' . $e->getMessage());
    // Manejo de excepción
}
```

### 5. Cuotas
- Cada envío de medio cuenta como **1 mensaje** en el límite diario
- Los medios se contabilizan igual que los mensajes de texto
- Monitorea las cuotas vía `/api/v1/tenants/{tenantId}/whatsapp-instances/{id}/statistics`

---

## 🧪 Testing

```bash
# Ejecutar script de prueba
php test-evolution-media.php
```

El script probará:
1. ✅ Envío de imagen desde URL
2. ✅ Envío de documento PDF
3. ✅ Envío de imagen base64 (opcional)
4. ✅ Verificación de contadores

---

## 📋 Resumen de Métodos

| Método | Uso Principal | Requerido |
|--------|---------------|-----------|
| `sendMedia()` | Envío genérico de cualquier tipo | Todos los parámetros explícitos |
| `sendImage()` | Envío de imágenes | phone, imageUrl, tenantId |
| `sendVideo()` | Envío de videos | phone, videoUrl, tenantId |
| `sendDocument()` | Envío de documentos | phone, documentUrl, tenantId, **fileName** |

---

## 🎯 Próximos Pasos

1. Ejecutar `php test-evolution-media.php` para probar la integración
2. Integrar envío de medios en tus controllers/jobs existentes
3. Monitorear logs para verificar envíos exitosos
4. Ajustar límites diarios si es necesario

**Sistema listo para enviar imágenes, videos y documentos** ✅
