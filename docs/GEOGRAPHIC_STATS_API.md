# API de Estadísticas Geográficas

## Endpoint
```
GET /api/v1/geographic-stats
```

## Descripción
Obtiene estadísticas de reuniones o compromisos agrupadas por ubicación geográfica (municipio, barrio, corregimiento o vereda). Este endpoint es útil para visualizar mapas con información de actividades políticas por región.

## Autenticación
Requiere token JWT válido en el header:
```
Authorization: Bearer {token}
```

## Parámetros de Consulta (Query Parameters)

| Parámetro | Tipo | Requerido | Valores Permitidos | Descripción |
|-----------|------|-----------|-------------------|-------------|
| `type` | string | Sí | `compromisos`, `reuniones` | Tipo de estadística a consultar |
| `geographic_type` | string | Sí | `municipio`, `barrio`, `corregimiento`, `vereda` | Nivel geográfico de agrupación |

## Ejemplos de Uso

### 1. Obtener Reuniones por Municipio
```http
GET /api/v1/geographic-stats?type=reuniones&geographic_type=municipio
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**Respuesta:**
```json
{
    "success": true,
    "data": [
        {
            "id": "id1",
            "name": "Ibague",
            "path": "M 100,50 L 150,100 L 100,150 L 50,100 Z",
            "value": 5,
            "color": "#13db2dff",
            "meetings": [
                {
                    "id": 1,
                    "title": "Reunión con líderes comunitarios",
                    "description": "Discusión de proyectos comunitarios",
                    "status": "completada",
                    "starts_at": "2025-11-01T10:00:00.000000Z",
                    "ends_at": "2025-11-01T12:00:00.000000Z",
                    "lugar_nombre": "Casa comunal",
                    "planner": {
                        "id": 2,
                        "name": "María González"
                    },
                    "attendees_count": 15
                }
            ]
        },
        {
            "id": "id2",
            "name": "Falan",
            "path": "M 200,50 L 250,100 L 200,150 L 150,100 Z",
            "value": 0,
            "color": "#F54927",
            "meetings": []
        }
    ],
    "meta": {
        "type": "reuniones",
        "geographic_type": "municipio",
        "total_locations": 2,
        "total_count": 5
    }
}
```

### 2. Obtener Compromisos por Municipio
```http
GET /api/v1/geographic-stats?type=compromisos&geographic_type=municipio
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

**Respuesta:**
```json
{
    "success": true,
    "data": [
        {
            "id": "id1",
            "name": "Ibague",
            "path": "M 100,50 L 150,100 L 100,150 L 50,100 Z",
            "value": 12,
            "color": "#13db2dff",
            "commitments": [
                {
                    "id": 45,
                    "description": "Mejorar vías de acceso",
                    "status": "pendiente",
                    "due_date": "2025-11-15",
                    "assigned_user": {
                        "id": 3,
                        "name": "Carlos Ruiz"
                    },
                    "priority": {
                        "id": 1,
                        "name": "Alta"
                    },
                    "meeting_id": 1,
                    "meeting_title": "Reunión con líderes comunitarios"
                }
            ]
        }
    ],
    "meta": {
        "type": "compromisos",
        "geographic_type": "municipio",
        "total_locations": 1,
        "total_count": 12
    }
}
```

### 3. Obtener Reuniones por Barrio
```http
GET /api/v1/geographic-stats?type=reuniones&geographic_type=barrio
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### 4. Obtener Compromisos por Corregimiento
```http
GET /api/v1/geographic-stats?type=compromisos&geographic_type=corregimiento
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

### 5. Obtener Reuniones por Vereda
```http
GET /api/v1/geographic-stats?type=reuniones&geographic_type=vereda
Authorization: Bearer eyJ0eXAiOiJKV1QiLCJhbGc...
```

## Estructura de Respuesta

### Respuesta Exitosa (200 OK)

```json
{
    "success": true,
    "data": [
        {
            "id": "id{location_id}",
            "name": "Nombre de la ubicación",
            "path": "SVG path string (puede ser null)",
            "value": "Cantidad de reuniones o compromisos",
            "color": "#13db2dff o #F54927",
            "meetings": [...],  // Solo para type=reuniones
            "commitments": [...] // Solo para type=compromisos
        }
    ],
    "meta": {
        "type": "reuniones o compromisos",
        "geographic_type": "municipio, barrio, corregimiento o vereda",
        "total_locations": "Número de ubicaciones",
        "total_count": "Total de reuniones o compromisos"
    }
}
```

### Colores

- **Verde (`#13db2dff`)**: La ubicación tiene reuniones/compromisos
- **Rojo (`#F54927`)**: La ubicación NO tiene reuniones/compromisos

### Campos de Meeting (cuando type=reuniones)

```json
{
    "id": 1,
    "title": "Título de la reunión",
    "description": "Descripción",
    "status": "planificada | en_progreso | completada | cancelada",
    "starts_at": "Fecha y hora de inicio (ISO 8601)",
    "ends_at": "Fecha y hora de fin (ISO 8601)",
    "lugar_nombre": "Nombre del lugar",
    "planner": {
        "id": 2,
        "name": "Nombre del organizador"
    },
    "attendees_count": "Número de asistentes"
}
```

### Campos de Commitment (cuando type=compromisos)

```json
{
    "id": 45,
    "description": "Descripción del compromiso",
    "status": "pendiente | en_progreso | completado | cancelado",
    "due_date": "Fecha límite (YYYY-MM-DD)",
    "assigned_user": {
        "id": 3,
        "name": "Nombre del responsable"
    },
    "priority": {
        "id": 1,
        "name": "Alta | Media | Baja"
    },
    "meeting_id": 1,
    "meeting_title": "Título de la reunión asociada"
}
```

## Errores Comunes

### 422 Validation Error - Parámetro `type` faltante
```json
{
    "message": "El tipo de estadística es obligatorio.",
    "errors": {
        "type": ["El tipo de estadística es obligatorio."]
    }
}
```

### 422 Validation Error - Parámetro `type` inválido
```json
{
    "message": "El tipo debe ser: compromisos o reuniones.",
    "errors": {
        "type": ["El tipo debe ser: compromisos o reuniones."]
    }
}
```

### 422 Validation Error - Parámetro `geographic_type` faltante
```json
{
    "message": "El tipo geográfico es obligatorio.",
    "errors": {
        "geographic_type": ["El tipo geográfico es obligatorio."]
    }
}
```

### 422 Validation Error - Parámetro `geographic_type` inválido
```json
{
    "message": "El tipo geográfico debe ser: municipio, barrio, corregimiento o vereda.",
    "errors": {
        "geographic_type": ["El tipo geográfico debe ser: municipio, barrio, corregimiento o vereda."]
    }
}
```

### 401 Unauthorized
```json
{
    "message": "Unauthenticated."
}
```

## Notas Importantes

1. **Filtro por Path**: El endpoint solo retorna ubicaciones que tienen el campo `path` configurado (no null). Esto es para optimizar el renderizado de mapas SVG.

2. **Tenant Scoping**: Las reuniones y compromisos están automáticamente filtrados por el tenant del usuario autenticado.

3. **Soft Deletes**: Solo se incluyen reuniones y compromisos que no han sido eliminados (`deleted_at IS NULL`).

4. **Relaciones**: El endpoint carga eficientemente las relaciones necesarias usando Eager Loading para optimizar el rendimiento.

5. **IDs en el Mapa**: Los IDs de las ubicaciones en el array `data` tienen el formato `"id{location_id}"` para evitar conflictos en el frontend.

## Casos de Uso

### Visualización de Mapa de Calor
El frontend puede usar este endpoint para crear mapas de calor que muestren:
- Municipios con mayor actividad de reuniones
- Barrios con más compromisos pendientes
- Distribución geográfica de actividades políticas

### Dashboard Geográfico
Útil para:
- Ver estadísticas por región
- Identificar zonas con poca actividad
- Planificar estrategias de cobertura territorial

### Filtrado por Nivel Geográfico
Permite al usuario navegar desde un nivel macro (municipio) hasta niveles más específicos (barrio/vereda):
```
Departamento → Municipio → Barrio/Corregimiento/Vereda
```

## Ejemplo de Integración Frontend

```javascript
// Obtener reuniones por municipio
async function getMunicipalityMeetings() {
    const response = await fetch(
        '/api/v1/geographic-stats?type=reuniones&geographic_type=municipio',
        {
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json'
            }
        }
    );
    
    const data = await response.json();
    
    // Renderizar mapa SVG
    data.data.forEach(municipality => {
        renderSVGPath(municipality.path, municipality.color);
        
        // Mostrar tooltip con información
        if (municipality.meetings.length > 0) {
            showTooltip(municipality.name, municipality.meetings);
        }
    });
}

// Obtener compromisos por barrio
async function getBarrioCommitments() {
    const response = await fetch(
        '/api/v1/geographic-stats?type=compromisos&geographic_type=barrio',
        {
            headers: {
                'Authorization': `Bearer ${token}`,
                'Accept': 'application/json'
            }
        }
    );
    
    const data = await response.json();
    
    // Crear gráficos o listados
    data.data.forEach(barrio => {
        if (barrio.value > 0) {
            createCommitmentChart(barrio.name, barrio.commitments);
        }
    });
}
```

## Rendimiento

- **Eager Loading**: El endpoint utiliza `with()` para cargar relaciones de manera eficiente
- **Filtro de Path**: Solo consulta ubicaciones con path configurado, reduciendo datos innecesarios
- **Selectores Específicos**: Solo carga los campos necesarios de las relaciones (`:id,name`)

## Versionado

- **Versión Actual**: v1
- **Endpoint**: `/api/v1/geographic-stats`
- **Cambios Recientes**: 
  - 2025-11-07: Agregado soporte para `geographic_type=municipio`
  - 2025-10-XX: Versión inicial con barrio, corregimiento, vereda
