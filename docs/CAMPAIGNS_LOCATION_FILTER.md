# Guía de Implementación - Filtro de Campañas por Ubicación Geográfica

## 📋 Resumen

Se ha agregado una nueva opción de audiencia para campañas: **"Por Ubicación"**

Esta opción permite enviar campañas a asistentes de reuniones filtrados por:
- Departamento
- Municipio  
- Comuna
- Barrio

**Regla importante:** Siempre se toma la selección más específica. Si seleccionas Departamento → Municipio → Comuna, se filtrará por Comuna.

---

## 🎯 Opciones de Audiencia Actualizadas

```json
{
  "target": "all_users" | "meeting_attendees" | "custom_list" | "by_location"
}
```

### Opciones:
1. **all_users** - Todos los usuarios del tenant
2. **meeting_attendees** - Asistentes de reuniones específicas
3. **custom_list** - Lista personalizada de emails/teléfonos
4. **by_location** - ⭐ NUEVO: Asistentes filtrados por ubicación geográfica

---

## 🗺️ Filtro por Ubicación Geográfica

### UI Recomendada

```
┌─────────────────────────────────────────────────┐
│  Audiencia                                      │
├─────────────────────────────────────────────────┤
│  ○ Todos los usuarios                           │
│  ○ Asistentes de reuniones                      │
│  ○ Lista personalizada                          │
│  ● Por ubicación                                │
│                                                 │
│  ┌───────────────────────────────────────────┐ │
│  │ Departamento: [▼ Seleccionar]             │ │
│  │ Municipio:    [▼ Seleccionar]             │ │
│  │ Comuna:       [▼ Seleccionar] (opcional)  │ │
│  │ Barrio:       [▼ Seleccionar] (opcional)  │ │
│  └───────────────────────────────────────────┘ │
└─────────────────────────────────────────────────┘
```

### Cascada de Selects

1. **Departamento** → Carga municipios de ese departamento
2. **Municipio** → Carga comunas de ese municipio (si existen)
3. **Comuna** → Carga barrios de esa comuna
4. **Barrio** → Selección final más específica

**Importante:** No es obligatorio seleccionar todos los niveles. Puedes detenerte en cualquier nivel.

---

## 📡 API - Crear Campaña con Filtro de Ubicación

### Endpoint
```
POST /api/v1/campaigns
```

### Ejemplos de Request

#### Ejemplo 1: Filtrar por Departamento (más general)
```json
{
  "title": "Campaña Departamental",
  "message": "Mensaje para todo el departamento",
  "channel": "whatsapp",
  "filter_json": {
    "target": "by_location",
    "department_id": 5
  }
}
```
**Resultado:** Todos los asistentes de reuniones que viven en el departamento #5

---

#### Ejemplo 2: Filtrar por Municipio
```json
{
  "title": "Campaña Municipal",
  "message": "Mensaje para el municipio",
  "channel": "both",
  "filter_json": {
    "target": "by_location",
    "department_id": 5,
    "municipality_id": 23
  }
}
```
**Resultado:** Todos los asistentes que viven en el municipio #23 (ignora department_id)

---

#### Ejemplo 3: Filtrar por Comuna
```json
{
  "title": "Campaña Comunal",
  "message": "Mensaje para la comuna",
  "channel": "email",
  "filter_json": {
    "target": "by_location",
    "department_id": 5,
    "municipality_id": 23,
    "commune_id": 102
  }
}
```
**Resultado:** Todos los asistentes que viven en la comuna #102 (ignora department y municipality)

---

#### Ejemplo 4: Filtrar por Barrio (más específico)
```json
{
  "title": "Campaña Barrial",
  "message": "Mensaje para el barrio",
  "channel": "whatsapp",
  "filter_json": {
    "target": "by_location",
    "department_id": 5,
    "municipality_id": 23,
    "commune_id": 102,
    "barrio_id": 1550
  }
}
```
**Resultado:** Todos los asistentes que viven en el barrio #1550 (ignora los demás niveles)

---

## ⚙️ Lógica de Prioridad

El backend aplica esta lógica:

```
if (barrio_id existe) {
  ✅ Filtrar por barrio_id
}
else if (commune_id existe) {
  ✅ Filtrar por commune_id (todos los barrios de esa comuna)
}
else if (municipality_id existe) {
  ✅ Filtrar por municipality_id (todos los barrios/comunas de ese municipio)
}
else if (department_id existe) {
  ✅ Filtrar por department_id (todos los municipios de ese departamento)
}
```

**Siempre se toma el nivel más específico proporcionado.**

---

## 🔍 Endpoints para Cargar Datos Geográficos

### 1. Obtener Departamentos
```
GET /api/v1/departments
```

**Response:**
```json
{
  "data": [
    {
      "id": 5,
      "nombre": "Antioquia",
      "codigo": "05"
    }
  ]
}
```

---

### 2. Obtener Municipios de un Departamento
```
GET /api/v1/departments/{department_id}/municipalities
```

**Response:**
```json
{
  "data": [
    {
      "id": 23,
      "nombre": "Medellín",
      "codigo": "05001",
      "department_id": 5
    }
  ]
}
```

---

### 3. Obtener Comunas de un Municipio
```
GET /api/v1/municipalities/{municipality_id}/communes
```

**Response:**
```json
{
  "data": [
    {
      "id": 102,
      "nombre": "Comuna 1 - Popular",
      "numero": "1",
      "municipality_id": 23
    }
  ]
}
```

---

### 4. Obtener Barrios de una Comuna
```
GET /api/v1/communes/{commune_id}/barrios
```

**Response:**
```json
{
  "data": [
    {
      "id": 1550,
      "nombre": "Santo Domingo Savio No.1",
      "commune_id": 102,
      "municipality_id": 23
    }
  ]
}
```

---

### 5. Obtener Barrios directos de un Municipio (sin comuna)
```
GET /api/v1/municipalities/{municipality_id}/barrios
```

**Response:**
```json
{
  "data": [
    {
      "id": 2000,
      "nombre": "Corregimiento San Antonio de Prado",
      "commune_id": null,
      "municipality_id": 23
    }
  ]
}
```

---

## 💡 Ejemplo de Implementación Frontend

```javascript
// Estado del formulario
const [filters, setFilters] = useState({
  target: 'by_location',
  department_id: null,
  municipality_id: null,
  commune_id: null,
  barrio_id: null,
});

// Cargar departamentos al inicio
useEffect(() => {
  fetch('/api/v1/departments')
    .then(res => res.json())
    .then(data => setDepartments(data.data));
}, []);

// Cuando selecciona departamento → Cargar municipios
const handleDepartmentChange = (departmentId) => {
  setFilters({
    ...filters,
    department_id: departmentId,
    municipality_id: null,
    commune_id: null,
    barrio_id: null,
  });
  
  fetch(`/api/v1/departments/${departmentId}/municipalities`)
    .then(res => res.json())
    .then(data => setMunicipalities(data.data));
};

// Cuando selecciona municipio → Cargar comunas
const handleMunicipalityChange = (municipalityId) => {
  setFilters({
    ...filters,
    municipality_id: municipalityId,
    commune_id: null,
    barrio_id: null,
  });
  
  fetch(`/api/v1/municipalities/${municipalityId}/communes`)
    .then(res => res.json())
    .then(data => setCommunes(data.data));
};

// Cuando selecciona comuna → Cargar barrios
const handleCommuneChange = (communeId) => {
  setFilters({
    ...filters,
    commune_id: communeId,
    barrio_id: null,
  });
  
  fetch(`/api/v1/communes/${communeId}/barrios`)
    .then(res => res.json())
    .then(data => setBarrios(data.data));
};

// Enviar campaña
const createCampaign = () => {
  // Enviar solo los campos que tienen valor
  const filter_json = {
    target: 'by_location',
  };
  
  if (filters.barrio_id) filter_json.barrio_id = filters.barrio_id;
  else if (filters.commune_id) filter_json.commune_id = filters.commune_id;
  else if (filters.municipality_id) filter_json.municipality_id = filters.municipality_id;
  else if (filters.department_id) filter_json.department_id = filters.department_id;
  
  fetch('/api/v1/campaigns', {
    method: 'POST',
    headers: {
      'Content-Type': 'application/json',
      'Authorization': `Bearer ${token}`,
    },
    body: JSON.stringify({
      title: campaignTitle,
      message: campaignMessage,
      channel: 'whatsapp',
      filter_json,
    }),
  });
};
```

---

## 🧪 Testing

### Caso 1: Campaña por Departamento
```bash
POST /api/v1/campaigns
{
  "title": "Test Departamento",
  "message": "Hola desde el departamento",
  "channel": "whatsapp",
  "filter_json": {
    "target": "by_location",
    "department_id": 5
  }
}
```

**Verificar:**
- Se debe crear la campaña
- El campo `total_recipients` debe tener el número de asistentes en ese departamento
- GET `/api/v1/campaigns/{id}/recipients` debe mostrar todos los asistentes del departamento

---

### Caso 2: Campaña por Barrio
```bash
POST /api/v1/campaigns
{
  "title": "Test Barrio",
  "message": "Hola desde el barrio",
  "channel": "email",
  "filter_json": {
    "target": "by_location",
    "department_id": 5,
    "municipality_id": 23,
    "commune_id": 102,
    "barrio_id": 1550
  }
}
```

**Verificar:**
- Solo se envía a asistentes del barrio #1550
- Se ignoran los filtros de department, municipality y commune

---

## 📝 Validación de Request

El backend valida:
- ✅ `target` debe ser uno de: `all_users`, `meeting_attendees`, `custom_list`, `by_location`
- ✅ `department_id` debe existir en tabla `departments`
- ✅ `municipality_id` debe existir en tabla `municipalities`
- ✅ `commune_id` debe existir en tabla `communes`
- ✅ `barrio_id` debe existir en tabla `barrios`

**No es obligatorio enviar todos los niveles.** Puedes enviar solo `department_id` o solo `municipality_id`, etc.

---

## 🚨 Consideraciones

1. **Solo se filtran asistentes de reuniones**, no usuarios del sistema
2. **Se requiere que los asistentes tengan `barrio_id` asignado** en su registro
3. **Se eliminan duplicados** automáticamente (mismo email/teléfono)
4. **Los logs registran** el nivel de filtro aplicado (Departamento, Municipio, Comuna o Barrio)

---

## 📊 Logs

Cuando se crea una campaña por ubicación, se registra en logs:

```
Campaign filter by location: Barrio {"barrio_id": 1550}
Campaign filter by location: Comuna {"commune_id": 102}
Campaign filter by location: Municipality {"municipality_id": 23}
Campaign filter by location: Department {"department_id": 5}
```

---

## ✅ Checklist de Implementación Frontend

- [ ] Agregar opción "Por Ubicación" en selector de audiencia
- [ ] Implementar select cascada: Departamento → Municipio → Comuna → Barrio
- [ ] Cargar departamentos al inicio
- [ ] Cargar municipios cuando se selecciona departamento
- [ ] Cargar comunas cuando se selecciona municipio
- [ ] Cargar barrios cuando se selecciona comuna
- [ ] Limpiar selecciones inferiores cuando se cambia una superior
- [ ] Enviar solo el campo más específico en `filter_json`
- [ ] Mostrar mensaje indicando que se usará el filtro más específico
- [ ] Testing con diferentes combinaciones de filtros

---

## 🔗 Endpoints Relacionados

- `POST /api/v1/campaigns` - Crear campaña con filtro de ubicación
- `GET /api/v1/departments` - Listar departamentos
- `GET /api/v1/departments/{id}/municipalities` - Municipios de un departamento
- `GET /api/v1/municipalities/{id}/communes` - Comunas de un municipio
- `GET /api/v1/communes/{id}/barrios` - Barrios de una comuna
- `GET /api/v1/municipalities/{id}/barrios` - Barrios directos de un municipio
- `GET /api/v1/campaigns/{id}/recipients` - Ver destinatarios de una campaña
