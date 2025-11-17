# API de Puestos de Votación

## 📍 Endpoints Públicos (Sin Autenticación)

---

## 1. Generar Imagen del Puesto de Votación

Genera una imagen personalizada con la información del puesto de votación del ciudadano. **La información se obtiene automáticamente de la base de datos usando la cédula.**

### Endpoint
```
POST /api/v1/voting-place/generate-image
```

### Headers
```
Content-Type: application/json
```

### Body (JSON)
```json
{
  "cedula": "14398737"
}
```

### Parámetros

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `cedula` | string | Sí | Número de cédula del ciudadano (máx. 20 caracteres) |

### Respuesta Exitosa (201 Created)

```json
{
  "success": true,
  "message": "Voting place image generated successfully",
  "data": {
    "cedula": "14398737",
    "nombres": "JUAN CARLOS",
    "apellidos": "PEREZ RODRIGUEZ",
    "image_url": "http://localhost:8000/images/votaciones/14398737.jpg",
    "voting_data": {
      "departamento": "TOLIMA",
      "ciudad": "IBAGUE",
      "puesto": "IE JOSE CELESTINO MUTIS SEDE 2",
      "mesa": "9"
    }
  }
}
```

### Respuesta de Error (404 Not Found)

```json
{
  "success": false,
  "message": "Voter not found",
  "error": "No se encontró un votante con la cédula proporcionada"
}
```

### Respuesta de Error (422 Unprocessable Entity)

**Validación:**
```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "cedula": ["The cedula field is required."]
  }
}
```

**Datos incompletos:**
```json
{
  "success": false,
  "message": "Incomplete voting data",
  "error": "El votante no tiene información completa del puesto de votación"
}
```

### Respuesta de Error (500 Internal Server Error)

```json
{
  "success": false,
  "message": "Failed to generate voting place image",
  "error": "Template image not found: ..."
}
```

### Ejemplo con cURL

```bash
curl -X POST "http://localhost:8000/api/v1/voting-place/generate-image" \
  -H "Content-Type: application/json" \
  -d '{
    "cedula": "14398737"
  }'
```

### Ejemplo con JavaScript (Fetch)

```javascript
const response = await fetch('http://localhost:8000/api/v1/voting-place/generate-image', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    cedula: '14398737'
  })
});

const data = await response.json();
if (data.success) {
  console.log(`Imagen generada: ${data.data.image_url}`);
  console.log(`Nombre: ${data.data.nombres} ${data.data.apellidos}`);
}
```

### Ejemplo con Postman

1. Método: `POST`
2. URL: `http://localhost:8000/api/v1/voting-place/generate-image`
3. Headers:
   - `Content-Type: application/json`
4. Body (raw JSON):
```json
{
  "cedula": "14398737"
}
```

---

## 2. Generar y Enviar Imagen por WhatsApp

Genera la imagen del puesto de votación y la envía automáticamente al número de WhatsApp especificado. **La información del puesto se obtiene automáticamente de la base de datos usando la cédula.**

### Endpoint
```
POST /api/v1/voting-place/send-whatsapp
```

### Headers
```
Content-Type: application/json
```

### Body (JSON)
```json
{
  "cedula": "14398737",
  "phone": "3116677099",
  "tenant_id": 1
}
```

**Nota:** El campo `phone` es **opcional**. Si no se proporciona, el sistema usará el teléfono registrado del votante en la base de datos.

### Parámetros

| Campo | Tipo | Requerido | Descripción |
|-------|------|-----------|-------------|
| `cedula` | string | Sí | Número de cédula del ciudadano |
| `phone` | string | No | Número de teléfono WhatsApp. Si no se proporciona, usa el del votante |
| `tenant_id` | integer | Sí | ID del tenant (debe existir en la tabla tenants) |

### Respuesta Exitosa (200 OK)

```json
{
  "success": true,
  "message": "Voting place image sent successfully via WhatsApp",
  "data": {
    "cedula": "14398737",
    "nombres": "JUAN CARLOS",
    "apellidos": "PEREZ RODRIGUEZ",
    "phone": "3116677099",
    "voting_data": {
      "departamento": "TOLIMA",
      "ciudad": "IBAGUE",
      "puesto": "IE JOSE CELESTINO MUTIS SEDE 2",
      "mesa": "9"
    }
  }
}
```

### Respuesta de Error (404 Not Found)

```json
{
  "success": false,
  "message": "Voter not found",
  "error": "No se encontró un votante con la cédula proporcionada"
}
```

### Respuesta de Error (422 Unprocessable Entity)

**Validación:**
```json
{
  "success": false,
  "message": "Validation error",
  "errors": {
    "cedula": ["The cedula field is required."],
    "tenant_id": ["The selected tenant id is invalid."]
  }
}
```

**Datos incompletos:**
```json
{
  "success": false,
  "message": "Incomplete voting data",
  "error": "El votante no tiene información completa del puesto de votación"
}
```

**Teléfono requerido:**
```json
{
  "success": false,
  "message": "Phone number required",
  "error": "No se proporcionó un número de teléfono y el votante no tiene teléfono registrado"
}
```

### Respuesta de Error (500 Internal Server Error)

```json
{
  "success": false,
  "message": "Failed to send voting place image via WhatsApp",
  "error": "No WhatsApp instances available for tenant"
}
```

### Ejemplo con cURL

```bash
curl -X POST "http://localhost:8000/api/v1/voting-place/send-whatsapp" \
  -H "Content-Type: application/json" \
  -d '{
    "cedula": "14398737",
    "phone": "3116677099",
    "tenant_id": 1
  }'
```

### Ejemplo con JavaScript (Fetch)

```javascript
const response = await fetch('http://localhost:8000/api/v1/voting-place/send-whatsapp', {
  method: 'POST',
  headers: {
    'Content-Type': 'application/json'
  },
  body: JSON.stringify({
    cedula: '14398737',
    phone: '3116677099', // Opcional
    tenant_id: 1
  })
});

const data = await response.json();
if (data.success) {
  console.log('Imagen enviada exitosamente!');
}
```

### Ejemplo con Python (requests)

```python
import requests

url = "http://localhost:8000/api/v1/voting-place/send-whatsapp"
payload = {
    "cedula": "14398737",
    "phone": "3116677099",  # Opcional
    "tenant_id": 1
}

response = requests.post(url, json=payload)
data = response.json()
print(data)
```

---

## 📝 Notas Importantes

### 1. Sin Autenticación
Ambos endpoints son **públicos** y **no requieren token de autenticación**. Esto permite su uso desde aplicaciones externas, landing pages, o sistemas de terceros.

### 2. Base de Datos como Fuente de Verdad
**Los datos del puesto de votación se obtienen automáticamente de la base de datos**:
- Solo necesitas enviar la **cédula** del votante
- El sistema busca el registro en la tabla `voters`
- Extrae: `departamento_votacion`, `municipio_votacion`, `puesto_votacion`, `mesa_votacion`
- Si el votante no existe o no tiene datos completos, devuelve un error apropiado

### 3. Formato de Texto
Los campos `departamento`, `ciudad` y `puesto` se convierten automáticamente a **MAYÚSCULAS** para mantener consistencia visual en la imagen.

### 4. Tamaño de Imagen
Las imágenes generadas son en formato **JPEG** con compresión al 85%, resultando en archivos de aproximadamente **400 KB**.

### 5. Ubicación de Imágenes
Las imágenes se guardan en:
```
public/images/votaciones/{cedula}.jpg
```

Y son accesibles vía:
```
{BASE_URL}/images/votaciones/{cedula}.jpg
```

### 6. Teléfono Opcional para WhatsApp
En el endpoint `send-whatsapp`:
- El campo `phone` es **opcional**
- Si no se proporciona, el sistema usa el `telefono` registrado del votante
- Si tampoco tiene teléfono registrado, devuelve error 422

### 7. Instancias de WhatsApp
Para el endpoint `send-whatsapp`:
- El `tenant_id` debe tener al menos una instancia de WhatsApp activa
- La instancia debe tener cuota disponible
- El sistema usa balanceo de carga automático entre instancias

### 8. Formato de Teléfono
El número de teléfono se normaliza automáticamente:
- `3116677099` → `573116677099` (agrega código de Colombia)
- `+573116677099` → `573116677099` (remueve +)
- Números ya con código 57 se mantienen igual

### 9. Búsqueda Sin Restricción de Tenant
La búsqueda del votante se realiza **sin restricción de tenant** (`withoutGlobalScope`), permitiendo encontrar votantes de cualquier organización en el sistema.

---

## 🧪 Testing

### Probar con Postman

**Colección de prueba:**

1. **Generar Imagen**
   - Name: Generate Voting Place Image
   - Method: POST
   - URL: `{{baseUrl}}/api/v1/voting-place/generate-image`
   - Body: Ver JSON arriba

2. **Enviar WhatsApp**
   - Name: Send Voting Place via WhatsApp
   - Method: POST
   - URL: `{{baseUrl}}/api/v1/voting-place/send-whatsapp`
   - Body: Ver JSON arriba

### Variables de Entorno Postman
```json
{
  "baseUrl": "http://localhost:8000",
  "testCedula": "14398737",
  "testPhone": "3116677099",
  "tenantId": 1
}
```

---

## 🔍 Casos de Uso

### Caso 1: Landing Page - Consulta de Puesto
```javascript
// Usuario ingresa solo su cédula
document.getElementById('btnConsultar').addEventListener('click', async () => {
  const cedula = document.getElementById('cedula').value;
  
  // Generar imagen (los datos se obtienen automáticamente de la BD)
  const response = await fetch('/api/v1/voting-place/generate-image', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ cedula })
  });
  
  const data = await response.json();
  
  if (data.success) {
    // Mostrar imagen y datos al usuario
    document.getElementById('imagenPuesto').src = data.data.image_url;
    document.getElementById('nombre').textContent = 
      `${data.data.nombres} ${data.data.apellidos}`;
    document.getElementById('departamento').textContent = 
      data.data.voting_data.departamento;
  } else {
    alert(data.message);
  }
});
```

### Caso 2: Envío Masivo por WhatsApp
```javascript
// Enviar a múltiples ciudadanos (solo necesitas cédulas)
const cedulas = ['14398737', '12345678', '98765432'];

for (const cedula of cedulas) {
  const response = await fetch('/api/v1/voting-place/send-whatsapp', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ 
      cedula,
      // phone: opcional, usa el del votante si no se envía
      tenant_id: 1 
    })
  });
  
  const data = await response.json();
  console.log(`${cedula}: ${data.success ? 'Enviado' : 'Error'}`);
  
  // Esperar 2 segundos entre envíos
  await new Promise(resolve => setTimeout(resolve, 2000));
}
```

### Caso 3: Integración con Sistema Externo
```php
// Desde otro sistema PHP - solo necesita cédula
$cedula = $ciudadano->documento;

$data = [
    'cedula' => $cedula,
    'phone' => $ciudadano->celular, // Opcional
    'tenant_id' => 1
];

$ch = curl_init('http://api.example.com/api/v1/voting-place/send-whatsapp');
curl_setopt($ch, CURLOPT_POST, true);
curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($data));
curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']);
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

$response = curl_exec($ch);
curl_close($ch);

$result = json_decode($response, true);
if ($result['success']) {
    echo "Enviado a: {$result['data']['nombres']} {$result['data']['apellidos']}";
}
```

---

## ⚠️ Limitaciones y Consideraciones

1. **Rate Limiting**: Considera implementar rate limiting para evitar abuso
2. **Cuotas de WhatsApp**: Cada envío consume 1 mensaje de la cuota diaria del tenant
3. **Tamaño de Archivos**: Las imágenes se almacenan en el servidor, considera limpieza periódica
4. **Validación de Datos**: El sistema valida que el votante tenga datos completos de votación
5. **Template Image**: Requiere que `public/images/boceto1.png` exista
6. **Búsqueda Global**: La cédula se busca en todos los tenants (sin scope)
7. **Datos Incompletos**: Si el votante no tiene `departamento_votacion`, `municipio_votacion`, `puesto_votacion` o `mesa_votacion`, retorna error 422

---

## 📦 Respuestas del Sistema

### Estados HTTP

| Código | Descripción |
|--------|-------------|
| 200 | WhatsApp enviado exitosamente |
| 201 | Imagen generada exitosamente |
| 404 | Votante no encontrado con la cédula proporcionada |
| 422 | Error de validación o datos incompletos del votante |
| 500 | Error interno del servidor |

### Estructura de Respuesta

Todas las respuestas siguen esta estructura:

```json
{
  "success": boolean,
  "message": string,
  "data": object | null,
  "errors": object | null
}
```

---

## 🚀 Despliegue

### Variables de Entorno Necesarias

Asegúrate de tener configurado:
- Instancias de WhatsApp en la base de datos (tabla `tenant_whatsapp_instances`)
- Evolution API funcionando correctamente
- Template `public/images/boceto1.png` disponible
- Permisos de escritura en `public/images/votaciones/`

### Verificar Configuración

```bash
# Limpiar cache
php artisan config:clear
php artisan route:clear
php artisan cache:clear

# Verificar permisos
chmod -R 755 public/images/
mkdir -p public/images/votaciones
chmod 755 public/images/votaciones
```

---

**Documentación generada para API de Puestos de Votación v1.0**  
**Última actualización**: 2025-11-17
