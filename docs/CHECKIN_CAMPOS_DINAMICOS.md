# Check-In con Campos Dinámicos

## Flujo Completo de Check-In

### 1️⃣ Frontend Obtiene Información del Meeting

**Endpoint:** `GET /api/v1/meetings/public/{qr_code}`

> **Corregido por la Spec 0021.** Antes este documento mostraba `titulo` y
> `descripcion` con valor, pero el controller los leía del modelo con esos
> nombres —donde en realidad son `title` y `description`— así que **siempre
> llegaban `null`** (hallazgo F3 de la caracterización 0010). Ya se mapean bien.
>
> El payload es ahora el de `PublicMeetingResource`, compartido con
> `GET /meetings/check-in/{qr_code}`. Al ser rutas **sin autenticación** no
> incluye `tenant_id`, `metadata`, `qr_code` ni el correo o teléfono de quien
> organiza: del planner solo va el nombre (hallazgo F6). Los campos `objetivo`,
> `lugar_tipo` y `lugar_url` se retiraron porque no existen en el modelo.

**Respuesta del Backend:**

```json
{
    "success": true,
    "data": {
        "id": 36,
        "titulo": "Reunión Centenario",
        "descripcion": "Descripción de la reunión",
        "starts_at": "2025-11-15T10:00:00-05:00",
        "ends_at": null,
        "status": "scheduled",
        "lugar_nombre": "Salón comunal",
        "lugar_direccion": "Calle 50 #45-30",
        "planner": {
            "name": "Juan Organizador"
        },
        "location": {
            "department": "Antioquia",
            "municipality": "Medellín",
            "commune": "Comuna 10",
            "barrio": "Centenario"
        },
        "template": {
            "id": 7,
            "nombre": "Caracterizaicon socioeconomica",
            "descripcion": "Caracterizaicon socioeconomica",
            "fields": [
                {
                    "label": "Seleccione su estrato socio-económico",
                    "type": "radio",
                    "required": true,
                    "options": [
                        "Estrato 1",
                        "Estrato 2",
                        "Estrato 3",
                        "Estrato 4",
                        "Estrato 5",
                        "Estrato 6"
                    ]
                },
                {
                    "label": "Registre su fecha de nacimiento",
                    "type": "date",
                    "required": true
                }
            ]
        },
        "attendees_count": 15,
        "checked_in_count": 10
    }
}
```

---

### 2️⃣ Usuario Llena el Formulario

El frontend debe renderizar dinámicamente:

1. **Campos básicos** (siempre presentes):
   - Cédula ✅ (requerido)
   - Nombres ✅ (requerido)
   - Apellidos ✅ (requerido)
   - Teléfono (opcional)
   - Email (opcional)
   - Barrio (opcional)

2. **Campos dinámicos del template** (según `template.fields`):
   - Radio buttons para "Seleccione su estrato socio-económico"
   - Input date para "Registre su fecha de nacimiento"

---

### 3️⃣ Frontend Envía el Check-In

**Endpoint:** `POST /api/v1/meetings/check-in/{qr_code}`

**Ejemplo de JSON a Enviar:**

```json
{
    "cedula": "1234567890",
    "nombres": "Juan",
    "apellidos": "Pérez García",
    "telefono": "3001234567",
    "email": "juan.perez@example.com",
    "barrio_id": 123,
    "extra_fields": {
        "Seleccione su estrato socio-económico": "Estrato 3",
        "Registre su fecha de nacimiento": "1990-05-15"
    }
}
```

**Notas Importantes:**

- ✅ `cedula`, `nombres`, `apellidos` son **requeridos**
- ✅ `telefono`, `email`, `barrio_id` son **opcionales**
- ✅ `extra_fields` es un **objeto/diccionario** donde:
  - **Key**: El `label` exacto del campo del template
  - **Value**: El valor que el usuario seleccionó/escribió

---

### 4️⃣ Backend Almacena la Información

Desde la **Spec 0022** el check-in no guarda literalmente lo que llega:
`App\Services\AttendanceService` normaliza la cédula (sin puntos ni espacios),
busca o crea el **Votante** del tenant de la reunión, liga al asistente por
`voter_id`, completa los campos que el formulario dejó en blanco y **deduplica**
—segundo check-in de la misma cédula en la misma reunión actualiza la fila que ya
existe—. Los `extra_fields` no cambian: se guardan tal cual.

**Lo que se guarda en la tabla `meeting_attendees`:**

```json
{
    "id": 456,
    "meeting_id": 36,
    "tenant_id": 1,
    "voter_id": 78,
    "cedula": "1234567890",
    "nombres": "Juan",
    "apellidos": "Pérez García",
    "telefono": "3001234567",
    "email": "juan.perez@example.com",
    "barrio_id": 123,
    "direccion": null,
    "checked_in": true,
    "checked_in_at": "2025-11-06T15:30:00.000000Z",
    "extra_fields": {
        "Seleccione su estrato socio-económico": "Estrato 3",
        "Registre su fecha de nacimiento": "1990-05-15"
    },
    "created_by": null,
    "created_at": "2025-11-06T15:30:00.000000Z",
    "updated_at": "2025-11-06T15:30:00.000000Z"
}
```

**Respuesta del Backend al Frontend:**

```json
{
    "success": true,
    "data": {
        "id": 456,
        "meeting_id": 36,
        "voter_id": 78,
        "cedula": "1234567890",
        "nombres": "Juan",
        "apellidos": "Pérez García",
        "full_name": "Juan Pérez García",
        "telefono": "3001234567",
        "email": "juan.perez@example.com",
        "barrio_id": 123,
        "checked_in": true,
        "checked_in_at": "2025-11-06T15:30:00.000000Z",
        "extra_fields": {
            "Seleccione su estrato socio-económico": "Estrato 3",
            "Registre su fecha de nacimiento": "1990-05-15"
        },
        "created_at": "2025-11-06T15:30:00.000000Z"
    },
    "message": "Check-in successful"
}
```

---

## 📊 Ejemplos de Diferentes Tipos de Campos

### Ejemplo 1: Template con Campo de Texto

**Template:**
```json
{
    "fields": [
        {
            "label": "¿Cuál es su ocupación?",
            "type": "text",
            "required": true
        }
    ]
}
```

**JSON de Check-In:**
```json
{
    "cedula": "1234567890",
    "nombres": "María",
    "apellidos": "González",
    "extra_fields": {
        "¿Cuál es su ocupación?": "Profesora"
    }
}
```

### Ejemplo 2: Template con Múltiples Campos

**Template:**
```json
{
    "fields": [
        {
            "label": "Nivel educativo",
            "type": "select",
            "required": true,
            "options": ["Primaria", "Bachillerato", "Técnico", "Profesional", "Posgrado"]
        },
        {
            "label": "¿Tiene hijos?",
            "type": "radio",
            "required": true,
            "options": ["Sí", "No"]
        },
        {
            "label": "Número de hijos",
            "type": "number",
            "required": false
        },
        {
            "label": "Observaciones",
            "type": "textarea",
            "required": false
        }
    ]
}
```

**JSON de Check-In:**
```json
{
    "cedula": "9876543210",
    "nombres": "Carlos",
    "apellidos": "Ramírez",
    "telefono": "3109876543",
    "extra_fields": {
        "Nivel educativo": "Profesional",
        "¿Tiene hijos?": "Sí",
        "Número de hijos": "2",
        "Observaciones": "Interesado en programas de educación infantil"
    }
}
```

### Ejemplo 3: Template Vacío (Sin Campos Dinámicos)

**Template:**
```json
{
    "id": 5,
    "nombre": "Asistencia Simple",
    "fields": []
}
```

**JSON de Check-In:**
```json
{
    "cedula": "5555555555",
    "nombres": "Ana",
    "apellidos": "Martínez",
    "telefono": "3205555555"
}
```
**Nota:** No se envía `extra_fields` o se envía como objeto vacío `{}`

---

## 🔍 Validaciones

### Validaciones Actuales (CheckInRequest)

```php
[
    'cedula' => 'required|string|max:20',
    'nombres' => 'required|string|max:255',
    'apellidos' => 'required|string|max:255',
    'barrio_id' => 'nullable|exists:barrios,id',
    'telefono' => 'nullable|string|max:20',
    'email' => 'nullable|email',
    'extra_fields' => 'nullable|array',
]
```

### Validaciones Futuras Sugeridas

Para validar que los campos dinámicos cumplan con el template:

```php
// Validar que los campos requeridos del template estén presentes
// Validar que los valores de radio/select sean de las opciones permitidas
// Validar tipos de datos (date, number, email, etc.)
```

---

## 📝 Casos de Uso Comunes

### 1. Reunión de Caracterización Socioeconómica
- Template: Campos de estrato, fecha de nacimiento, nivel educativo
- Extra fields: Captura datos demográficos y sociales

### 2. Reunión Política con Compromisos
- Template: Campos de temas de interés, prioridades del barrio
- Extra fields: Captura preferencias y necesidades

### 3. Reunión de Recursos
- Template: Campos de tipo de recurso, cantidad solicitada
- Extra fields: Captura solicitudes específicas

### 4. Reunión Simple
- Template: Sin campos adicionales
- Extra fields: Vacío o no enviado

---

## 🎯 Recomendaciones para el Frontend

1. **Renderizado Dinámico**: 
   - Parsear `template.fields` y crear componentes según `type`
   - Tipos: `text`, `textarea`, `number`, `email`, `date`, `radio`, `select`, `checkbox`

2. **Estructura del JSON**:
   - Usar el `label` exacto como key en `extra_fields`
   - Mantener consistencia en el formato de valores

3. **Validaciones**:
   - Validar campos `required` antes de enviar
   - Validar que valores de `radio`/`select` estén en `options`
   - Validar tipos de datos (fechas, números, emails)

4. **UX**:
   - Mostrar asterisco (*) en campos requeridos
   - Validación en tiempo real
   - Mensajes de error claros

5. **Manejo de Errores**:
   - 422: Datos de validación incorrectos
   - 404: Meeting no encontrado
   - 500: Error del servidor

---

## ✅ Conclusión

El sistema ya está **completamente preparado** para recibir campos dinámicos:

- ✅ Columna `extra_fields` (JSON) en la tabla
- ✅ Validación de `extra_fields` como array
- ✅ Cast automático a array en el modelo
- ✅ Almacenamiento y recuperación funcional
- ✅ API pública retorna estructura completa del template

El frontend solo necesita:
1. Obtener el template del meeting
2. Renderizar los campos dinámicamente
3. Enviar los valores en `extra_fields` usando los labels como keys
