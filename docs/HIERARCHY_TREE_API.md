# API: Árbol Jerárquico de Reuniones

## Descripción

Este endpoint devuelve un árbol jerárquico que muestra cómo las personas (asistentes) solicitan nuevas reuniones en cascada. La estructura refleja la red organizacional y cómo se expande a través de solicitudes de reuniones.

## Endpoint

```
GET /api/v1/meetings/hierarchy/tree
```

**Autenticación:** Requerida (JWT Bearer Token)

## Parámetros de Consulta

| Parámetro | Tipo | Requerido | Por Defecto | Descripción |
|-----------|------|-----------|-------------|-------------|
| `include_attendees` | boolean | No | `false` | Si es `true`, incluye la lista completa de asistentes en cada reunión |

### Valores Aceptados para `include_attendees`

- `true`, `1`, `"true"`, `"1"` → Incluye asistentes
- `false`, `0`, `"false"`, `"0"`, o ausente → No incluye asistentes

## Estructura del Árbol

El árbol se construye de la siguiente manera:

1. **Reuniones Raíz**: Reuniones creadas por planners (sin `assigned_to_cedula`)
2. **Reuniones Hijas**: Reuniones solicitadas por asistentes de reuniones anteriores
3. **Relación**: El campo `assigned_to_cedula` de una reunión apunta a la cédula del asistente que la solicitó

### Ejemplo de Flujo

```
Planner crea Reunión A
  └─ María asiste a Reunión A
      └─ María solicita Reunión B (assigned_to_cedula = "14398737")
          └─ Jenny asiste a Reunión B
              └─ Jenny solicita Reunión C (assigned_to_cedula = "1032380898")
```

## Respuesta

### Sin `include_attendees` (Estructura Mínima)

```json
{
    "success": true,
    "data": [
        {
            "meeting": {
                "id": 32,
                "titulo": "Reunión Principal - Raíz",
                "descripcion": null,
                "starts_at": "2025-11-05T21:53:38.000000Z",
                "status": "scheduled",
                "lugar_nombre": "Sede Principal",
                "location": {
                    "barrio": null,
                    "comuna": null
                },
                "attendees_count": 1
            },
            "requester": {
                "cedula": null,
                "nombres": "Gildardo Patiño",
                "apellidos": "",
                "full_name": "Gildardo Patiño",
                "type": "planner"
            },
            "children": [
                {
                    "meeting": { ... },
                    "requester": {
                        "cedula": "14398737",
                        "nombres": "María",
                        "apellidos": "López",
                        "full_name": "María López",
                        "telefono": null,
                        "email": null
                    },
                    "children": [ ... ]
                }
            ]
        }
    ],
    "meta": {
        "total_meetings": 4,
        "root_meetings": 1,
        "include_attendees": false
    }
}
```

### Con `include_attendees=true` (Estructura Completa)

```json
{
    "success": true,
    "data": [
        {
            "meeting": {
                "id": 32,
                "titulo": "Reunión Principal - Raíz",
                "descripcion": null,
                "starts_at": "2025-11-05T21:53:38.000000Z",
                "status": "scheduled",
                "lugar_nombre": "Sede Principal",
                "location": {
                    "barrio": null,
                    "comuna": null
                },
                "attendees_count": 1,
                "attendees": [
                    {
                        "id": 10,
                        "cedula": "14398737",
                        "nombres": "María",
                        "apellidos": "López",
                        "full_name": "María López",
                        "telefono": null,
                        "email": null
                    }
                ]
            },
            "requester": {
                "cedula": null,
                "nombres": "Gildardo Patiño",
                "apellidos": "",
                "full_name": "Gildardo Patiño",
                "type": "planner"
            },
            "children": [
                {
                    "meeting": {
                        "id": 29,
                        "titulo": "Encuentro con vecinos La Paz",
                        "descripcion": "Encuentro con vecinos La Paz",
                        "starts_at": "2025-11-06T04:00:00.000000Z",
                        "status": "scheduled",
                        "lugar_nombre": "Salón Comunal La Paz",
                        "location": {
                            "barrio": "LA PAZ",
                            "comuna": "Comuna 2"
                        },
                        "attendees_count": 3,
                        "attendees": [
                            {
                                "id": 5,
                                "cedula": "1032380898",
                                "nombres": "Jenny",
                                "apellidos": "Jaramillo",
                                "full_name": "Jenny Jaramillo",
                                "telefono": "3148976991",
                                "email": "jenny@gmail.com"
                            },
                            {
                                "id": 6,
                                "cedula": "14398676",
                                "nombres": "Danilo",
                                "apellidos": "Uribe",
                                "full_name": "Danilo Uribe",
                                "telefono": "3125642345",
                                "email": "danilo@gmail.com"
                            },
                            {
                                "id": 9,
                                "cedula": "11223346",
                                "nombres": "test3",
                                "apellidos": "test3",
                                "full_name": "test3 test3",
                                "telefono": "11223346",
                                "email": "11223346@gmail.com"
                            }
                        ]
                    },
                    "requester": {
                        "cedula": "14398737",
                        "nombres": "María",
                        "apellidos": "López",
                        "full_name": "María López",
                        "telefono": null,
                        "email": null
                    },
                    "children": [ ... ]
                }
            ]
        }
    ],
    "meta": {
        "total_meetings": 4,
        "root_meetings": 1,
        "include_attendees": true
    }
}
```

## Campos de la Respuesta

### Objeto `meeting`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | integer | ID de la reunión |
| `titulo` | string | Título de la reunión |
| `descripcion` | string\|null | Descripción de la reunión |
| `starts_at` | datetime | Fecha y hora de inicio (ISO 8601) |
| `status` | string | Estado: `scheduled`, `completed`, `cancelled` |
| `lugar_nombre` | string | Nombre del lugar de la reunión |
| `location.barrio` | string\|null | Nombre del barrio |
| `location.comuna` | string\|null | Nombre de la comuna |
| `attendees_count` | integer | Cantidad total de asistentes |
| `attendees` | array | **Solo si `include_attendees=true`** - Lista de asistentes |

### Objeto `requester`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `cedula` | string\|null | Cédula del solicitante (null para planners) |
| `nombres` | string | Nombres del solicitante |
| `apellidos` | string | Apellidos del solicitante |
| `full_name` | string | Nombre completo |
| `telefono` | string\|null | Teléfono (solo en children, no en root) |
| `email` | string\|null | Email (solo en children, no en root) |
| `type` | string | **Solo en root** - Tipo: `planner` |

### Objeto `attendee` (cuando `include_attendees=true`)

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `id` | integer | ID del registro de asistencia |
| `cedula` | string | Cédula del asistente |
| `nombres` | string | Nombres del asistente |
| `apellidos` | string | Apellidos del asistente |
| `full_name` | string | Nombre completo |
| `telefono` | string\|null | Teléfono de contacto |
| `email` | string\|null | Email de contacto |

### Objeto `meta`

| Campo | Tipo | Descripción |
|-------|------|-------------|
| `total_meetings` | integer | Total de reuniones en el sistema |
| `root_meetings` | integer | Cantidad de reuniones raíz (iniciadas por planners) |
| `include_attendees` | boolean | Indica si se incluyeron los asistentes |

## Ejemplos de Uso

### cURL - Estructura Mínima

```bash
curl -X GET "http://localhost/api/v1/meetings/hierarchy/tree" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

### cURL - Con Asistentes

```bash
curl -X GET "http://localhost/api/v1/meetings/hierarchy/tree?include_attendees=true" \
  -H "Authorization: Bearer YOUR_JWT_TOKEN" \
  -H "Accept: application/json"
```

### JavaScript (Axios)

```javascript
// Sin asistentes
const response = await axios.get('/api/v1/meetings/hierarchy/tree', {
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
  }
});

// Con asistentes
const responseWithAttendees = await axios.get('/api/v1/meetings/hierarchy/tree', {
  params: { include_attendees: true },
  headers: {
    'Authorization': `Bearer ${token}`,
    'Accept': 'application/json'
  }
});
```

### React Example

```jsx
const HierarchyTree = () => {
  const [tree, setTree] = useState([]);
  const [includeAttendees, setIncludeAttendees] = useState(false);

  useEffect(() => {
    const fetchTree = async () => {
      const response = await api.get('/meetings/hierarchy/tree', {
        params: { include_attendees: includeAttendees }
      });
      setTree(response.data.data);
    };
    fetchTree();
  }, [includeAttendees]);

  return (
    <div>
      <label>
        <input 
          type="checkbox" 
          checked={includeAttendees}
          onChange={(e) => setIncludeAttendees(e.target.checked)}
        />
        Mostrar asistentes
      </label>
      <TreeView data={tree} />
    </div>
  );
};
```

## Casos de Uso

### 1. Visualización Simple del Árbol

**Sin asistentes** - Ideal para:
- Vista general de la estructura organizacional
- Gráficos de árbol jerárquico simples
- Navegación rápida entre niveles
- Reducir tamaño de payload

### 2. Vista Detallada con Información de Contacto

**Con asistentes** - Ideal para:
- Tarjetas de reunión con lista completa de participantes
- Exportación de datos completos
- Análisis detallado de participación
- Generar reportes con información de contacto

## Notas Técnicas

### Prevención de Recursión Infinita

El algoritmo implementa un límite de profundidad de **10 niveles** para prevenir recursión infinita en caso de referencias circulares.

### Rendimiento

- El endpoint carga todas las reuniones con eager loading
- Para sistemas con muchas reuniones (>1000), considere implementar paginación o filtros adicionales
- El flag `include_attendees=false` es más eficiente para cargas iniciales

### Base de Datos

La relación se establece mediante:
- `meetings.assigned_to_cedula` → Cédula del asistente que solicitó esta reunión
- `meeting_attendees.cedula` → Cédulas de todos los asistentes

## Mejoras Futuras Sugeridas

1. **Filtros adicionales**:
   - `?date_from=2025-01-01&date_to=2025-12-31` - Rango de fechas
   - `?status=scheduled` - Por estado
   - `?root_meeting_id=32` - Subárbol desde una reunión específica

2. **Búsqueda**:
   - `?cedula=14398737` - Árbol de reuniones de una persona específica
   - `?search=María` - Búsqueda por nombre

3. **Estadísticas**:
   - Profundidad máxima del árbol
   - Promedio de asistentes por nivel
   - Total de personas únicas en el árbol

4. **Optimización**:
   - Caché para árboles grandes
   - Paginación por niveles
   - Índices de base de datos en `assigned_to_cedula`
