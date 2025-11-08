# API de Asignación de Recursos y Logística

## Descripción General

Este módulo permite gestionar la asignación y seguimiento de recursos logísticos para campañas políticas. Permite asignar tres tipos de recursos: efectivo (cash), materiales (material) y servicios (service) a diferentes líderes y reuniones, con seguimiento completo de estados y responsables.

---

## 📌 ENDPOINTS

### Base URL
```
/api/v1/resource-allocations
```

**Autenticación:** Todos los endpoints requieren token JWT válido.

---

## 📋 TIPOS DE RECURSOS

| Tipo | Valor | Descripción | Ejemplos |
|------|-------|-------------|----------|
| **Efectivo** | `cash` | Dinero en efectivo asignado | Viáticos, fondos para eventos, anticipos |
| **Material** | `material` | Recursos físicos o materiales | Pancartas, volantes, camisetas, transporte |
| **Servicio** | `service` | Servicios contratados | Sonido, catering, transporte, publicidad |

## 📊 ESTADOS DE ASIGNACIÓN

| Estado | Valor | Descripción |
|--------|-------|-------------|
| **Pendiente** | `pending` | Recurso asignado pero no entregado |
| **Entregado** | `delivered` | Recurso entregado al responsable |
| **Devuelto** | `returned` | Recurso retornado (sobrante o no utilizado) |
| **Cancelado** | `cancelled` | Asignación cancelada |

---

## 🔍 ENDPOINTS DISPONIBLES

### 1. Listar Asignaciones de Recursos

```http
GET /api/v1/resource-allocations
```

**Descripción:** Obtiene un listado paginado de todas las asignaciones de recursos con opciones de filtrado y ordenamiento.

#### Query Parameters

| Parámetro | Tipo | Requerido | Descripción |
|-----------|------|-----------|-------------|
| `per_page` | integer | No | Registros por página (default: 15) |
| `page` | integer | No | Número de página |
| `filter[type]` | string | No | Filtrar por tipo: `cash`, `material`, `service` |
| `filter[meeting_id]` | integer | No | Filtrar por reunión específica |
| `filter[leader_user_id]` | integer | No | Filtrar por líder asignado |
| `sort` | string | No | Ordenar por campo: `allocation_date`, `created_at`, `amount` (usar `-` para descendente) |
| `include` | string | No | Incluir relaciones: `meeting`, `allocatedBy`, `leader` |

#### Ejemplo de Request

```http
GET /api/v1/resource-allocations?filter[type]=cash&sort=-allocation_date&include=meeting,leader&per_page=20
Authorization: Bearer {token}
```

#### Respuesta Exitosa (200 OK)

```json
{
  "data": [
    {
      "id": 1,
      "tenant_id": 1,
      "type": "cash",
      "amount": "500000.00",
      "details": {
        "descripcion": "Viáticos para reunión comunitaria",
        "notas_adicionales": "Incluye transporte y refrigerios"
      },
      "allocation_date": "2025-11-10",
      "notes": "Entrega antes del evento",
      "status": "pending",
      "assigned_to_user_id": 5,
      "assigned_to": {
        "id": 5,
        "name": "María González",
        "email": "maria@example.com"
      },
      "assigned_by_user_id": 2,
      "assigned_by": {
        "id": 2,
        "name": "Carlos Admin",
        "email": "carlos@example.com"
      },
      "leader_user_id": 5,
      "leader": {
        "id": 5,
        "name": "María González",
        "email": "maria@example.com"
      },
      "created_at": "2025-11-07T10:30:00.000000Z",
      "updated_at": "2025-11-07T10:30:00.000000Z",
      "deleted_at": null
    }
  ],
  "meta": {
    "total": 45,
    "current_page": 1,
    "last_page": 3,
    "per_page": 20
  }
}
```

---

### 2. Crear Asignación de Recurso

```http
POST /api/v1/resource-allocations
```

**Descripción:** Crea una nueva asignación de recurso. El usuario autenticado se registra automáticamente como quien realiza la asignación.

#### Request Body

```json
{
  "meeting_id": 15,
  "leader_user_id": 5,
  "type": "cash",
  "descripcion": "Viáticos para reunión comunitaria en Barrio Centro",
  "amount": 500000,
  "fecha_asignacion": "2025-11-10"
}
```

#### Campos de Entrada

| Campo | Tipo | Requerido | Validación | Descripción |
|-------|------|-----------|------------|-------------|
| `meeting_id` | integer | ✅ Sí | exists:meetings | ID de la reunión asociada |
| `leader_user_id` | integer | ✅ Sí | exists:users | ID del líder responsable |
| `type` | string | ✅ Sí | in:cash,material,service | Tipo de recurso |
| `descripcion` | string | ✅ Sí | string | Descripción del recurso asignado |
| `amount` | number | ✅ Sí | numeric, min:0 | Monto o cantidad del recurso |
| `fecha_asignacion` | date | ✅ Sí | date (YYYY-MM-DD) | Fecha de asignación |

#### Respuesta Exitosa (201 Created)

```json
{
  "data": {
    "id": 46,
    "tenant_id": 1,
    "type": "cash",
    "amount": "500000.00",
    "details": {
      "descripcion": "Viáticos para reunión comunitaria en Barrio Centro"
    },
    "allocation_date": "2025-11-10",
    "notes": null,
    "status": "pending",
    "assigned_to_user_id": 5,
    "assigned_by_user_id": 2,
    "leader_user_id": 5,
    "meeting": {
      "id": 15,
      "title": "Reunión Barrio Centro",
      "starts_at": "2025-11-10T14:00:00.000000Z"
    },
    "assigned_by": {
      "id": 2,
      "name": "Carlos Admin"
    },
    "leader": {
      "id": 5,
      "name": "María González"
    },
    "created_at": "2025-11-07T10:30:00.000000Z",
    "updated_at": "2025-11-07T10:30:00.000000Z",
    "deleted_at": null
  },
  "message": "Resource allocation created successfully"
}
```

#### Errores Comunes

**422 Validation Error**
```json
{
  "message": "The given data was invalid.",
  "errors": {
    "meeting_id": ["El campo meeting_id es obligatorio."],
    "type": ["El tipo debe ser: cash, material o service."],
    "amount": ["El monto debe ser mayor o igual a 0."]
  }
}
```

---

### 3. Obtener Detalle de Asignación

```http
GET /api/v1/resource-allocations/{id}
```

**Descripción:** Obtiene el detalle completo de una asignación específica.

#### Parámetros de URL

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `id` | integer | ID de la asignación |

#### Ejemplo de Request

```http
GET /api/v1/resource-allocations/46
Authorization: Bearer {token}
```

#### Respuesta Exitosa (200 OK)

```json
{
  "data": {
    "id": 46,
    "tenant_id": 1,
    "type": "material",
    "amount": "200.00",
    "details": {
      "descripcion": "Pancartas y volantes",
      "cantidad_pancartas": 50,
      "cantidad_volantes": 1000
    },
    "allocation_date": "2025-11-08",
    "notes": "Entregar 2 días antes del evento",
    "status": "delivered",
    "assigned_to_user_id": 5,
    "assigned_to": {
      "id": 5,
      "name": "María González",
      "email": "maria@example.com",
      "phone": "3001234567"
    },
    "assigned_by_user_id": 2,
    "assigned_by": {
      "id": 2,
      "name": "Carlos Admin",
      "email": "carlos@example.com"
    },
    "leader_user_id": 5,
    "leader": {
      "id": 5,
      "name": "María González",
      "email": "maria@example.com"
    },
    "created_at": "2025-11-05T09:00:00.000000Z",
    "updated_at": "2025-11-06T14:30:00.000000Z",
    "deleted_at": null
  }
}
```

---

### 4. Actualizar Asignación

```http
PUT /api/v1/resource-allocations/{id}
PATCH /api/v1/resource-allocations/{id}
```

**Descripción:** Actualiza una asignación existente. Todos los campos son opcionales.

#### Request Body

```json
{
  "status": "delivered",
  "notes": "Recurso entregado y confirmado",
  "amount": 450000
}
```

#### Campos de Entrada

| Campo | Tipo | Requerido | Validación | Descripción |
|-------|------|-----------|------------|-------------|
| `meeting_id` | integer | No | exists:meetings | ID de la reunión asociada |
| `leader_user_id` | integer | No | exists:users | ID del líder responsable |
| `type` | string | No | in:cash,material,service | Tipo de recurso |
| `descripcion` | string | No | string | Descripción del recurso |
| `amount` | number | No | numeric, min:0 | Monto o cantidad |
| `fecha_asignacion` | date | No | date | Fecha de asignación |

#### Respuesta Exitosa (200 OK)

```json
{
  "data": {
    "id": 46,
    "status": "delivered",
    "notes": "Recurso entregado y confirmado",
    "amount": "450000.00",
    "updated_at": "2025-11-07T11:00:00.000000Z"
  },
  "message": "Resource allocation updated successfully"
}
```

---

### 5. Eliminar Asignación

```http
DELETE /api/v1/resource-allocations/{id}
```

**Descripción:** Elimina (soft delete) una asignación de recurso.

#### Respuesta Exitosa (200 OK)

```json
{
  "message": "Resource allocation deleted successfully"
}
```

---

### 6. Obtener Recursos por Reunión

```http
GET /api/v1/resource-allocations/by-meeting/{meeting_id}
```

**Descripción:** Obtiene todas las asignaciones de recursos asociadas a una reunión específica, con totales por tipo de recurso.

#### Parámetros de URL

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `meeting_id` | integer | ID de la reunión |

#### Ejemplo de Request

```http
GET /api/v1/resource-allocations/by-meeting/15
Authorization: Bearer {token}
```

#### Respuesta Exitosa (200 OK)

```json
{
  "data": [
    {
      "id": 1,
      "type": "cash",
      "amount": "500000.00",
      "allocation_date": "2025-11-10",
      "status": "pending",
      "leader": {
        "id": 5,
        "name": "María González"
      },
      "assigned_by": {
        "id": 2,
        "name": "Carlos Admin"
      }
    },
    {
      "id": 2,
      "type": "material",
      "amount": "200.00",
      "allocation_date": "2025-11-10",
      "status": "delivered",
      "leader": {
        "id": 5,
        "name": "María González"
      },
      "assigned_by": {
        "id": 2,
        "name": "Carlos Admin"
      }
    },
    {
      "id": 3,
      "type": "service",
      "amount": "300000.00",
      "allocation_date": "2025-11-10",
      "status": "pending",
      "leader": {
        "id": 6,
        "name": "Juan Pérez"
      },
      "assigned_by": {
        "id": 2,
        "name": "Carlos Admin"
      }
    }
  ],
  "total_cash": "500000.00",
  "total_material": "200.00",
  "total_service": "300000.00"
}
```

**Caso de Uso:** Dashboard de reunión mostrando presupuesto total asignado por categoría.

---

### 7. Obtener Recursos por Líder

```http
GET /api/v1/resource-allocations/by-leader/{user_id}
```

**Descripción:** Obtiene todas las asignaciones de recursos de un líder específico, con resumen de totales.

#### Parámetros de URL

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `user_id` | integer | ID del usuario líder |

#### Query Parameters

| Parámetro | Tipo | Descripción |
|-----------|------|-------------|
| `per_page` | integer | Registros por página (default: 15) |

#### Ejemplo de Request

```http
GET /api/v1/resource-allocations/by-leader/5?per_page=10
Authorization: Bearer {token}
```

#### Respuesta Exitosa (200 OK)

```json
{
  "data": [
    {
      "id": 1,
      "type": "cash",
      "amount": "500000.00",
      "allocation_date": "2025-11-10",
      "status": "pending",
      "meeting": {
        "id": 15,
        "title": "Reunión Barrio Centro",
        "starts_at": "2025-11-10T14:00:00.000000Z"
      },
      "assigned_by": {
        "id": 2,
        "name": "Carlos Admin"
      }
    },
    {
      "id": 2,
      "type": "material",
      "amount": "200.00",
      "allocation_date": "2025-11-08",
      "status": "delivered",
      "meeting": {
        "id": 12,
        "title": "Evento Comunitario",
        "starts_at": "2025-11-08T10:00:00.000000Z"
      },
      "assigned_by": {
        "id": 2,
        "name": "Carlos Admin"
      }
    }
  ],
  "meta": {
    "total": 8,
    "current_page": 1,
    "last_page": 1
  },
  "summary": {
    "total_cash": "500000.00",
    "total_material": "200.00",
    "total_service": "0.00"
  }
}
```

**Caso de Uso:** Panel del líder mostrando todos sus recursos asignados y presupuesto total.

---

## 📦 ESTRUCTURA DE DATOS

### ResourceAllocation (Modelo)

```typescript
interface ResourceAllocation {
  id: number;
  tenant_id: number;
  type: 'cash' | 'material' | 'service';
  amount: string; // Decimal con 2 decimales
  details: {
    descripcion: string;
    [key: string]: any; // Campos adicionales personalizados
  } | null;
  allocation_date: string; // Formato: YYYY-MM-DD
  notes: string | null;
  status: 'pending' | 'delivered' | 'returned' | 'cancelled';
  
  // Relaciones
  assigned_to_user_id: number;
  assigned_to?: User;
  
  assigned_by_user_id: number;
  assigned_by?: User;
  
  leader_user_id: number;
  leader?: User;
  
  // Timestamps
  created_at: string; // ISO 8601
  updated_at: string; // ISO 8601
  deleted_at: string | null; // ISO 8601
}

interface User {
  id: number;
  name: string;
  email: string;
  phone?: string;
}
```

---

## 💡 CASOS DE USO

### 1. Asignar Viáticos para Reunión

```javascript
// React/TypeScript Example
const asignarViaticos = async (meetingId, leaderId, amount) => {
  try {
    const response = await fetch('/api/v1/resource-allocations', {
      method: 'POST',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json',
        'Accept': 'application/json'
      },
      body: JSON.stringify({
        meeting_id: meetingId,
        leader_user_id: leaderId,
        type: 'cash',
        descripcion: 'Viáticos para reunión comunitaria',
        amount: amount,
        fecha_asignacion: '2025-11-15'
      })
    });
    
    const result = await response.json();
    
    if (response.ok) {
      console.log('Recurso asignado:', result.data);
      showNotification('Viáticos asignados exitosamente', 'success');
      return result.data;
    } else {
      console.error('Error:', result.errors);
      showNotification('Error al asignar recursos', 'error');
    }
  } catch (error) {
    console.error('Error de red:', error);
  }
};
```

### 2. Dashboard de Reunión con Presupuesto

```javascript
const cargarPresupuestoReunion = async (meetingId) => {
  try {
    const response = await fetch(
      `/api/v1/resource-allocations/by-meeting/${meetingId}`,
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      }
    );
    
    const result = await response.json();
    
    // Mostrar totales por categoría
    const totales = {
      efectivo: parseFloat(result.total_cash),
      materiales: parseFloat(result.total_material),
      servicios: parseFloat(result.total_service),
      total: parseFloat(result.total_cash) + 
             parseFloat(result.total_material) + 
             parseFloat(result.total_service)
    };
    
    setPresupuesto(totales);
    setAsignaciones(result.data);
    
    return { totales, asignaciones: result.data };
  } catch (error) {
    console.error('Error al cargar presupuesto:', error);
  }
};
```

### 3. Panel de Control del Líder

```javascript
const cargarRecursosLider = async (userId) => {
  try {
    const response = await fetch(
      `/api/v1/resource-allocations/by-leader/${userId}?per_page=50`,
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      }
    );
    
    const result = await response.json();
    
    // Mostrar resumen
    console.log('Recursos totales asignados:');
    console.log('- Efectivo:', result.summary.total_cash);
    console.log('- Materiales:', result.summary.total_material);
    console.log('- Servicios:', result.summary.total_service);
    
    // Filtrar por estado
    const pendientes = result.data.filter(r => r.status === 'pending');
    const entregados = result.data.filter(r => r.status === 'delivered');
    
    setRecursosPendientes(pendientes);
    setRecursosEntregados(entregados);
    
    return result;
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### 4. Actualizar Estado de Recurso

```javascript
const marcarComoEntregado = async (allocationId) => {
  try {
    const response = await fetch(
      `/api/v1/resource-allocations/${allocationId}`,
      {
        method: 'PATCH',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json',
          'Accept': 'application/json'
        },
        body: JSON.stringify({
          status: 'delivered',
          notes: 'Recurso entregado y confirmado por el líder'
        })
      }
    );
    
    const result = await response.json();
    
    if (response.ok) {
      showNotification('Estado actualizado correctamente', 'success');
      return result.data;
    }
  } catch (error) {
    console.error('Error:', error);
  }
};
```

### 5. Filtrar y Buscar Recursos

```javascript
const buscarRecursos = async (filtros) => {
  // Construir query string
  const params = new URLSearchParams();
  
  if (filtros.tipo) params.append('filter[type]', filtros.tipo);
  if (filtros.meetingId) params.append('filter[meeting_id]', filtros.meetingId);
  if (filtros.liderId) params.append('filter[leader_user_id]', filtros.liderId);
  if (filtros.ordenar) params.append('sort', filtros.ordenar);
  
  params.append('include', 'meeting,leader,allocatedBy');
  params.append('per_page', '20');
  
  try {
    const response = await fetch(
      `/api/v1/resource-allocations?${params.toString()}`,
      {
        headers: {
          'Authorization': `Bearer ${token}`,
          'Accept': 'application/json'
        }
      }
    );
    
    const result = await response.json();
    return result;
  } catch (error) {
    console.error('Error:', error);
  }
};

// Uso
const recursos = await buscarRecursos({
  tipo: 'cash',
  ordenar: '-allocation_date' // Más recientes primero
});
```

---

## 📊 EJEMPLOS DE REPORTES

### Reporte de Gastos por Tipo

```javascript
const generarReporteGastos = async (fechaInicio, fechaFin) => {
  const params = new URLSearchParams({
    'filter[allocation_date_from]': fechaInicio,
    'filter[allocation_date_to]': fechaFin,
    'include': 'leader,meeting',
    'per_page': '1000'
  });
  
  const response = await fetch(
    `/api/v1/resource-allocations?${params}`,
    {
      headers: {
        'Authorization': `Bearer ${token}`,
        'Accept': 'application/json'
      }
    }
  );
  
  const result = await response.json();
  
  // Agrupar por tipo
  const gastosPorTipo = result.data.reduce((acc, item) => {
    acc[item.type] = (acc[item.type] || 0) + parseFloat(item.amount);
    return acc;
  }, {});
  
  return {
    efectivo: gastosPorTipo.cash || 0,
    materiales: gastosPorTipo.material || 0,
    servicios: gastosPorTipo.service || 0,
    total: Object.values(gastosPorTipo).reduce((a, b) => a + b, 0)
  };
};
```

---

## ⚠️ NOTAS IMPORTANTES

### 1. Campos Automáticos

- **tenant_id**: Se asigna automáticamente según el tenant del usuario autenticado
- **assigned_by_user_id**: Se asigna automáticamente con el ID del usuario que crea la asignación
- **status**: Por defecto es `pending` al crear

### 2. Soft Deletes

Las asignaciones eliminadas no se borran permanentemente, solo se marcan con `deleted_at`. Esto permite:
- Auditoría completa
- Recuperación de datos si es necesario
- Mantenimiento de integridad referencial

### 3. Campo Details

El campo `details` es un JSON flexible que permite almacenar información adicional personalizada:

```json
{
  "descripcion": "Pancartas y volantes",
  "cantidad_pancartas": 50,
  "cantidad_volantes": 1000,
  "proveedor": "Imprenta ABC",
  "numero_orden": "ORD-2025-001",
  "notas_especiales": "Diseño personalizado con logo de campaña"
}
```

### 4. Amount (Monto)

- Se almacena como DECIMAL(15,2)
- Siempre se retorna como string para evitar problemas de precisión en JavaScript
- Soporta valores hasta 999,999,999,999.99

### 5. Filtros con QueryBuilder

El endpoint usa Spatie Query Builder, que permite:
- **Filtros dinámicos**: `filter[campo]=valor`
- **Ordenamiento**: `sort=campo` o `sort=-campo` (descendente)
- **Inclusión de relaciones**: `include=meeting,leader`
- **Paginación**: `page=1&per_page=15`

---

## 🔒 PERMISOS Y SEGURIDAD

### Middleware Aplicado

- **auth:api**: Requiere autenticación JWT
- **tenant.scope**: Filtra datos por tenant automáticamente

### Recomendaciones

1. Validar que el usuario tenga permisos para asignar recursos
2. Implementar límites de montos según rol del usuario
3. Auditar todas las operaciones (ya incluido con LogsActivity)
4. Validar disponibilidad de presupuesto antes de crear asignaciones

---

## 📈 MÉTRICAS Y KPIs

### Indicadores Sugeridos

```javascript
// Total asignado por período
const totalAsignado = recursos.reduce((sum, r) => 
  sum + parseFloat(r.amount), 0
);

// Recursos por estado
const porEstado = recursos.reduce((acc, r) => {
  acc[r.status] = (acc[r.status] || 0) + 1;
  return acc;
}, {});

// Gasto promedio por reunión
const gastoPorReunion = totalAsignado / reunionesUnicas.length;

// Líder con más recursos asignados
const topLideres = Object.entries(
  recursos.reduce((acc, r) => {
    acc[r.leader_user_id] = (acc[r.leader_user_id] || 0) + parseFloat(r.amount);
    return acc;
  }, {})
).sort((a, b) => b[1] - a[1]);
```

---

## 🔄 CHANGELOG

- **2025-11-07**: Documentación inicial creada
- **2025-10-29**: Tabla y endpoints implementados

---

## 📞 SOPORTE

Para dudas o problemas con este endpoint, contactar al equipo de desarrollo.
