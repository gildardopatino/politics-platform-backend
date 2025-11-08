# API de Verificación de Documento - PISAMI + LEADS

Este endpoint permite verificar un documento (cédula) consumiendo primero la API externa de PISAMI. Si no encuentra información, busca en la tabla local de leads como respaldo.

---

## 📌 ENDPOINT

```
GET /api/v1/verify-document
```

**Este es un endpoint PÚBLICO** - No requiere autenticación.

---

## 🔄 FLUJO DE BÚSQUEDA

El endpoint implementa un sistema de búsqueda en cascada:

1. **PISAMI (API Externa)**: Primero intenta obtener datos de la API de PISAMI
2. **LEADS (Base de datos local)**: Si no encuentra en PISAMI, busca en la tabla `leads`
3. **No encontrado**: Si no existe en ninguna fuente, retorna error 404

---

## 📥 REQUEST

### Query Parameters

| Parámetro | Tipo   | Requerido | Descripción                    |
|-----------|--------|-----------|--------------------------------|
| cedula    | string | ✅ Sí     | Número de cédula a verificar   |

### Ejemplo de Request

```http
GET /api/v1/verify-document?cedula=14398676
```

---

## 📤 RESPONSE

### Response 200 OK - Documento Encontrado en PISAMI

```json
{
  "success": true,
  "source": "pisami",
  "data": {
    "nombres": "GILDARDO",
    "apellidos": "PATIÑO TRILLOS",
    "direccion": "Cra 61c N 23b 114 Sector El Triunfo Con Samoa",
    "telefono": "3116677099",
    "email": "gildardo.patino.trillos@gmail.com"
  }
}
```

### Response 200 OK - Documento Encontrado en LEADS

```json
{
  "success": true,
  "source": "leads",
  "data": {
    "cedula": "123456789",
    "nombres": "Juan Carlos",
    "apellidos": "Pérez López",
    "nombre_completo": "Juan Carlos Pérez López",
    "fecha_nacimiento": "1990-05-15",
    "telefono": "3001234567",
    "email": "juan@example.com",
    "direccion": "Calle 123 #45-67",
    "barrio": "Centro",
    "departamento_votacion": "Tolima",
    "municipio_votacion": "Ibague",
    "puesto_votacion": "Puesto 001",
    "zona_votacion": "Zona 1",
    "mesa_votacion": "001",
    "direccion_votacion": "Colegio XYZ",
    "locality_name": "Ibague",
    "latitud": "4.4389",
    "longitud": "-75.2322"
  }
}
```

### Response 404 Not Found - No se encontró información

```json
{
  "success": false,
  "message": "No se encontró información para la cédula proporcionada en PISAMI ni en la base de datos local"
}
```

### Response 422 Validation Error - Cédula no proporcionada

```json
{
  "success": false,
  "errors": {
    "cedula": [
      "El campo cedula es requerido"
    ]
  }
}
```

---

## 🔍 CAMPOS DE RESPUESTA

### Cuando `source = "pisami"`

| Campo     | Tipo   | Nullable | Descripción                                         |
|-----------|--------|----------|-----------------------------------------------------|
| nombres   | string | ✅ Sí    | Primer y segundo nombre combinados                  |
| apellidos | string | ✅ Sí    | Primer y segundo apellido combinados                |
| direccion | string | ✅ Sí    | Dirección de notificación                           |
| telefono  | string | ✅ Sí    | Teléfono móvil                                      |
| email     | string | ✅ Sí    | Correo electrónico                                  |

### Cuando `source = "leads"`

| Campo                  | Tipo    | Nullable | Descripción                                    |
|------------------------|---------|----------|------------------------------------------------|
| cedula                 | string  | ✅ Sí    | Número de cédula                               |
| nombres                | string  | ✅ Sí    | Nombres (nombre1 + nombre2)                    |
| apellidos              | string  | ✅ Sí    | Apellidos (apellido1 + apellido2)              |
| nombre_completo        | string  | ✅ Sí    | Nombre completo                                |
| fecha_nacimiento       | date    | ✅ Sí    | Fecha de nacimiento (formato: YYYY-MM-DD)      |
| telefono               | string  | ✅ Sí    | Teléfono de contacto                           |
| email                  | string  | ✅ Sí    | Correo electrónico                             |
| direccion              | string  | ✅ Sí    | Dirección de residencia                        |
| barrio                 | string  | ✅ Sí    | Nombre del barrio                              |
| departamento_votacion  | string  | ✅ Sí    | Departamento donde vota                        |
| municipio_votacion     | string  | ✅ Sí    | Municipio donde vota                           |
| puesto_votacion        | string  | ✅ Sí    | Nombre del puesto de votación                  |
| zona_votacion          | string  | ✅ Sí    | Zona electoral                                 |
| mesa_votacion          | string  | ✅ Sí    | Número de mesa de votación                     |
| direccion_votacion     | string  | ✅ Sí    | Dirección del puesto de votación               |
| locality_name          | string  | ✅ Sí    | Nombre de la localidad                         |
| latitud                | decimal | ✅ Sí    | Coordenada de latitud                          |
| longitud               | decimal | ✅ Sí    | Coordenada de longitud                         |
| email     | string | ✅ Sí    | Correo electrónico                                  |

**Nota:** Todos los campos pueden ser `null` si la información no está disponible en la fuente.

---

## 📝 NOTAS TÉCNICAS

### Búsqueda en Cascada

El endpoint implementa un sistema de búsqueda secuencial:

1. **Primera Fuente - PISAMI (API Externa)**
   - URL: `https://pisami.ibague.gov.co/app/PISAMI/modulos/administrativa/gestiondocumental/maestros/radicacion_pqr_publica/verifica_documento.php?doc={cedula}`
   - Si encuentra datos → Retorna con `source: "pisami"`
   - Si no encuentra → Continúa a la segunda fuente

2. **Segunda Fuente - LEADS (Base de datos local)**
   - Busca en la tabla `leads` por campo `cedula`
   - Si encuentra → Retorna con `source: "leads"`
   - Si no encuentra → Retorna error 404

### Formato de Respuesta de PISAMI

La API externa devuelve un script JavaScript con la siguiente estructura:

```javascript
<script languaje="javascript">
    parent.document.f_pqr.PRIMER_NOMBRE.value="GILDARDO"; 
    parent.document.f_pqr.SEGUNDO_NOMBRE.value=""; 
    parent.document.f_pqr.PRIMER_APELLIDO.value="PATIÑO"; 
    parent.document.f_pqr.SEGUNDO_APELLIDO.value="TRILLOS";  
    parent.document.f_pqr.DIRECCION_NOTIFICACION.value="Cra 61c N 23b 114 Sector El Triunfo Con Samoa"; 
    parent.document.f_pqr.TEL_MOVIL_NOTIFICACION.value="3116677099"; 
    parent.document.f_pqr.EMAIL.value="gildardo.patino.trillos@gmail.com";
</script>
```

### Procesamiento

El servicio `PisamiService` realiza lo siguiente:

1. **Request HTTP GET** a la API externa con la cédula
2. **Parsing del JavaScript** usando expresiones regulares
3. **Extracción de campos:**
   - `PRIMER_NOMBRE` + `SEGUNDO_NOMBRE` → `nombres`
   - `PRIMER_APELLIDO` + `SEGUNDO_APELLIDO` → `apellidos`
   - `DIRECCION_NOTIFICACION` → `direccion`
   - `TEL_MOVIL_NOTIFICACION` → `telefono`
   - `EMAIL` → `email`
4. **Normalización:** Los espacios extras se eliminan, valores vacíos se convierten a `null`

Para datos de **LEADS**, el controlador formatea los campos del modelo Lead para mantener consistencia con la estructura de PISAMI.

### Campo `source`

El campo `source` en la respuesta indica la fuente de los datos:
- `"pisami"`: Datos obtenidos de la API externa de PISAMI
- `"leads"`: Datos obtenidos de la tabla local `leads`

Esto permite al frontend:
- Identificar la procedencia de los datos
- Aplicar lógica diferencial según la fuente
- Mostrar indicadores visuales al usuario

### Timeout

El request a la API externa tiene un timeout de **30 segundos**.

---

## 💡 CASOS DE USO

### 1. Formulario de Registro de Votante

```javascript
// React Example
const verificarCedula = async (cedula) => {
  try {
    const response = await fetch(
      `https://api.plataforma.com/api/v1/verify-document?cedula=${cedula}`
    );
    const result = await response.json();
    
    if (result.success) {
      // Identificar fuente de datos
      const esDePisami = result.source === 'pisami';
      const esDeLeads = result.source === 'leads';
      
      // Prellenar formulario con los datos obtenidos
      setFormData({
        cedula: result.data.cedula || cedula,
        nombres: result.data.nombres || '',
        apellidos: result.data.apellidos || '',
        direccion: result.data.direccion || '',
        telefono: result.data.telefono || '',
        email: result.data.email || '',
        // Campos adicionales si viene de leads
        ...(esDeLeads && {
          fecha_nacimiento: result.data.fecha_nacimiento,
          municipio_votacion: result.data.municipio_votacion,
          mesa_votacion: result.data.mesa_votacion,
          puesto_votacion: result.data.puesto_votacion,
        })
      });
      
      // Mostrar indicador de fuente
      if (esDePisami) {
        showNotification('Datos obtenidos de Registraduría (PISAMI)', 'success');
      } else if (esDeLeads) {
        showNotification('Datos obtenidos de base de datos local', 'info');
      }
    } else {
      alert('No se encontró información para esta cédula');
    }
  } catch (error) {
    console.error('Error al verificar cédula:', error);
  }
};
```

### 2. Validación de Datos

```javascript
// Validar si los datos del votante coinciden con la registraduría
const validarDatos = async (cedula, datosActuales) => {
  const response = await fetch(
    `https://api.plataforma.com/api/v1/verify-document?cedula=${cedula}`
  );
  const result = await response.json();
  
  if (result.success) {
    const coincide = 
      result.data.nombres === datosActuales.nombres &&
      result.data.apellidos === datosActuales.apellidos;
    
    if (!coincide) {
      console.warn('Los datos no coinciden con la registraduría');
    }
  }
};
```

### 3. Autocompletado en Tiempo Real

```javascript
// Vue.js Example
export default {
  data() {
    return {
      cedula: '',
      votante: {
        nombres: '',
        apellidos: '',
        direccion: '',
        telefono: '',
        email: ''
      },
      loading: false
    }
  },
  watch: {
    cedula: _.debounce(async function(nuevaCedula) {
      if (nuevaCedula.length >= 6) {
        this.loading = true;
        try {
          const response = await axios.get('/api/v1/verify-document', {
            params: { cedula: nuevaCedula }
          });
          
          if (response.data.success) {
            this.votante = response.data.data;
          }
        } finally {
          this.loading = false;
        }
      }
    }, 500)
  }
}
```

---

## ⚠️ CONSIDERACIONES

### Disponibilidad de la API Externa

- La API de PISAMI puede estar **temporalmente no disponible**
- El servicio podría tener **mantenimientos programados**
- Implementar **retry logic** en caso de timeouts

### Privacidad de Datos

- Este endpoint es **público** pero solo retorna información básica
- Los datos provienen de una fuente gubernamental pública
- No se almacenan logs con información personal

### Performance

- El tiempo de respuesta depende de la API externa (típicamente 1-3 segundos)
- Considerar implementar **caché** para consultas frecuentes
- Mostrar indicador de carga al usuario

### Datos Incompletos

- No todos los ciudadanos tienen **todos los campos completos**
- Validar que los campos críticos (nombres, apellidos) no sean `null`
- Permitir edición manual de campos después de la verificación

---

## 🔧 DEBUGGING

### Logs

Los errores de conexión con la API externa se registran en:
```
storage/logs/laravel.log
```

Buscar por:
- `"PISAMI API request failed"` - Error en el request
- `"Error calling PISAMI API"` - Excepción general

### Testing Manual

```bash
# Probar con cURL
curl "http://localhost:8000/api/v1/verify-document?cedula=14398676"

# Con verbose para debugging
curl -v "http://localhost:8000/api/v1/verify-document?cedula=14398676"
```

### Testing con Postman

1. Método: `GET`
2. URL: `{{base_url}}/api/v1/verify-document`
3. Params: `cedula` = `14398676`
4. No requiere Headers de autenticación

---

## 📊 CÓDIGOS HTTP

| Código | Descripción                                    |
|--------|------------------------------------------------|
| 200    | Documento verificado exitosamente              |
| 404    | No se encontró información para la cédula      |
| 422    | Error de validación (cédula no proporcionada)  |
| 500    | Error interno del servidor                     |
| 504    | Timeout de la API externa (más de 30 segundos) |

---

## 🚀 PRÓXIMAS MEJORAS

- [ ] Implementar caché Redis para consultas frecuentes
- [ ] Agregar estadísticas de uso del endpoint
- [ ] Rate limiting para prevenir abuso
- [ ] Soporte para otros tipos de documento (pasaporte, etc.)
- [ ] Webhook para notificar cuando datos cambian en la registraduría
