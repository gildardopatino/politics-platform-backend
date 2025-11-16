# Sistema de Asignación de Recursos - Guía Frontend

## Índice
1. [Descripción General](#descripción-general)
2. [Estados de una Asignación](#estados-de-una-asignación)
3. [Flujo Completo](#flujo-completo)
4. [Endpoints Disponibles](#endpoints-disponibles)
5. [Casos de Uso Prácticos](#casos-de-uso-prácticos)
6. [Ejemplos de UI](#ejemplos-de-ui)

---

## Descripción General

El sistema de asignación de recursos permite:
- Asignar recursos (materiales, servicios, etc.) a reuniones o usuarios
- Hacer seguimiento del estado de las asignaciones (pending, delivered, returned, cancelled)
- Gestionar un catálogo de recursos con **control automático de inventario**
- Ver resumen de recursos por reunión o por líder

### Conceptos Clave

**ResourceItem (Catálogo):**
- Es el "producto" o recurso disponible en el inventario
- Ejemplo: "Silla plástica", "Micrófono", "Banner", etc.
- Tiene precio unitario y cantidad en stock
- **Control de inventario:**
  - `stock_quantity`: Total en almacén
  - `reserved_quantity`: Cantidad reservada (asignada pero no entregada)
  - `available_quantity`: Disponible para asignar (stock - reserved)

**ResourceAllocation (Asignación):**
- Es la acción de asignar recursos a alguien para algo
- Puede estar asociada a una reunión (meeting_id) o ser independiente
- Tiene un estado que indica su progreso
- **El backend controla el inventario automáticamente** según el estado

**ResourceAllocationItem (Item de Asignación):**
- Es la línea de detalle de una asignación
- Conecta una asignación con un recurso específico del catálogo
- Indica cantidad y subtotal

---

## 🚨 IMPORTANTE: Control de Inventario

### El Backend Maneja TODO el Inventario Automáticamente

**El frontend NUNCA debe:**
- ❌ Calcular stock disponible
- ❌ Validar si hay suficiente stock (solo visualmente)
- ❌ Modificar cantidades de stock
- ❌ Decidir cuándo reservar o liberar

**El frontend SOLO debe:**
- ✅ Mostrar la información que el backend envía
- ✅ Deshabilitar botones si `available_quantity === 0`
- ✅ Manejar errores 422 del backend
- ✅ Confiar en las validaciones del backend

### Cómo Funciona el Inventario

```
CREAR ASIGNACIÓN (Estado: pending)
→ Backend RESERVA el stock automáticamente
→ stock_quantity: sin cambios
→ reserved_quantity: aumenta
→ available_quantity: disminuye

MARCAR COMO ENTREGADO (Estado: delivered)
→ Backend DESCUENTA del stock
→ Backend LIBERA la reserva
→ stock_quantity: disminuye
→ reserved_quantity: disminuye
→ available_quantity: sin cambios

DEVOLVER (Estado: returned)
→ Backend DEVUELVE al stock
→ stock_quantity: aumenta

CANCELAR (Estado: cancelled)
→ Backend LIBERA la reserva
→ reserved_quantity: disminuye
→ available_quantity: aumenta
```

---

## Estados de una Asignación

| Estado | Descripción | ¿Qué significa? |
|--------|-------------|-----------------|
| `pending` | Pendiente | Asignación creada, recursos reservados pero no entregados |
| `delivered` | Entregado | Recursos entregados al usuario asignado |
| `returned` | Devuelto | Recursos devueltos al inventario |
| `cancelled` | Cancelado | Asignación cancelada, recursos liberados |

### Flujo de Estados

```
pending → delivered → returned
   ↓
cancelled
```

**Importante:** 
- Cuando creas una asignación, SIEMPRE empieza en `pending`
- Para marcarla como entregada, debes actualizar el estado a `delivered`
- Para devolverla, actualizar a `returned`

---

## Flujo Completo

### 1. Ver Catálogo de Recursos Disponibles

**Endpoint:** `GET /api/v1/resource-items`

**Query params opcionales:**
```
?per_page=20
&filter[category]=material
&filter[available]=true
&sort=-stock_quantity
```

**Request:**
```bash
GET /api/v1/resource-items?per_page=20
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "data": [
    {
      "id": 1,
      "name": "Silla plástica",
      "description": "Silla plástica blanca para eventos",
      "category": "furniture",
      "unit": "unidad",
      "unit_cost": 5000.00,
      "stock_quantity": 200,
      "reserved_quantity": 50,
      "available_quantity": 150,
      "min_stock_level": 50,
      "is_available": true,
      "is_low_stock": false,
      "image_url": null,
      "metadata": null,
      "created_at": "2025-11-12T15:30:00-05:00",
      "updated_at": "2025-11-12T15:30:00-05:00"
    },
    {
      "id": 2,
      "name": "Banner 2x1 metros",
      "description": "Banner impreso full color",
      "category": "marketing",
      "unit": "unidad",
      "unit_cost": 45000.00,
      "stock_quantity": 15,
      "reserved_quantity": 5,
      "available_quantity": 10,
      "min_stock_level": 5,
      "is_available": true,
      "is_low_stock": false,
      "image_url": "https://...",
      "metadata": {"material": "lona", "weight": "500g"},
      "created_at": "2025-11-12T15:30:00-05:00",
      "updated_at": "2025-11-12T15:30:00-05:00"
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

**Interpretación de los campos de inventario:**

| Campo | Qué es | Ejemplo | ¿Qué mostrar? |
|-------|--------|---------|---------------|
| `stock_quantity` | Total en almacén | 200 | "En almacén: 200" |
| `reserved_quantity` | Reservado (asignaciones pending) | 50 | "Reservado: 50" |
| `available_quantity` | **Disponible para asignar** | 150 | **"Disponible: 150"** ⭐ |
| `is_low_stock` | Si está por debajo del mínimo | false | Badge "⚠️ Stock bajo" |

**⭐ IMPORTANTE:** Usa `available_quantity` para:
- Mostrar cuántos se pueden asignar
- Deshabilitar botón de agregar si es 0
- Validar visualmente antes de enviar (opcional)

---

### 2. Crear Asignación de Recursos

**Endpoint:** `POST /api/v1/resource-allocations`

**Request Body:**
```json
{
  "leader_user_id": 5,
  "assigned_to_user_id": 5,
  "meeting_id": 42,
  "title": "Recursos para reunión en Parque Principal",
  "allocation_date": "2025-11-15",
  "notes": "Entregar un día antes del evento",
  "items": [
    {
      "resource_item_id": 1,
      "quantity": 50,
      "notes": "Verificar estado antes de entregar"
    },
    {
      "resource_item_id": 2,
      "quantity": 2,
      "notes": "Uno de respaldo"
    }
  ]
}
```

**Campos explicados:**
- `leader_user_id`: Usuario líder responsable (required)
- `assigned_to_user_id`: Usuario a quien se asigna (optional, si no se envía = leader)
- `meeting_id`: Reunión asociada (optional, puede ser null si no es para una reunión específica)
- `title`: Título descriptivo (optional)
- `allocation_date`: Fecha programada de entrega (optional)
- `notes`: Observaciones generales (optional)
- `items`: Array de recursos a asignar (required)
  - `resource_item_id`: ID del recurso del catálogo
  - `quantity`: Cantidad a asignar
  - `notes`: Observaciones del item específico (optional)

**🔥 ¿Qué hace el backend automáticamente?**

1. ✅ **Valida que existe stock disponible** para cada recurso
2. ✅ **RESERVA el stock** (aumenta `reserved_quantity`)
3. ✅ **Disminuye `available_quantity`** (stock - reserved)
4. ✅ **Crea la asignación en estado `pending`**

**Response Exitosa (201):**
```json
{
  "data": {
    "id": 15,
    "tenant_id": 1,
    "assigned_by_user_id": 2,
    "leader_user_id": 5,
    "assigned_to_user_id": 5,
    "meeting_id": 42,
    "title": "Recursos para reunión en Parque Principal",
    "allocation_date": "2025-11-15",
    "notes": "Entregar un día antes del evento",
    "status": "pending",
    "total_cost": 340000.00,
    "created_at": "2025-11-12T20:35:00-05:00",
    "updated_at": "2025-11-12T20:35:00-05:00",
    
    "assigned_by": {
      "id": 2,
      "name": "Admin Usuario",
      "email": "admin@example.com"
    },
    
    "leader": {
      "id": 5,
      "name": "Carlos Pérez",
      "email": "carlos@example.com"
    },
    
    "meeting": {
      "id": 42,
      "title": "Reunión Parque Principal",
      "starts_at": "2025-11-15T18:00:00-05:00"
    },
    
    "items": [
      {
        "id": 20,
        "resource_allocation_id": 15,
        "resource_item_id": 1,
        "quantity": 50,
        "unit_cost": 5000.00,
        "subtotal": 250000.00,
        "notes": "Verificar estado antes de entregar",
        "status": "pending",
        
        "resource_item": {
          "id": 1,
          "name": "Silla plástica",
          "category": "furniture",
          "unit": "unidad",
          "stock_quantity": 200,
          "reserved_quantity": 50,
          "available_quantity": 150
        }
      },
      {
        "id": 21,
        "resource_allocation_id": 15,
        "resource_item_id": 2,
        "quantity": 2,
        "unit_cost": 45000.00,
        "subtotal": 90000.00,
        "notes": "Uno de respaldo",
        "status": "pending",
        
        "resource_item": {
          "id": 2,
          "name": "Banner 2x1 metros",
          "category": "marketing",
          "unit": "unidad",
          "stock_quantity": 15,
          "reserved_quantity": 7,
          "available_quantity": 8
        }
      }
    ]
  },
  "message": "Asignación de recursos creada exitosamente"
}
```

**❌ Error: Stock Insuficiente (422):**
```json
{
  "message": "Stock insuficiente para 'Silla plástica'",
  "resource": "Silla plástica",
  "requested": 50,
  "available": 15,
  "in_stock": 200,
  "reserved": 185
}
```

**¿Cómo manejarlo en el frontend?**
```typescript
try {
  const response = await fetch('/api/v1/resource-allocations', {
    method: 'POST',
    headers: {
      'Authorization': `Bearer ${token}`,
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(allocationData)
  });

  if (response.status === 422) {
    const error = await response.json();
    
    // Mostrar mensaje específico del backend
    toast.error(
      `${error.message}\n\n` +
      `Solicitado: ${error.requested}\n` +
      `Disponible: ${error.available}\n` +
      `(En stock: ${error.in_stock}, Reservado: ${error.reserved})`
    );
    return;
  }

  if (response.ok) {
    const result = await response.json();
    toast.success('Recursos asignados y reservados exitosamente');
    onSuccess(result.data);
  }
} catch (error) {
  toast.error('Error de conexión');
}
```

---

### 3. Ver Lista de Reuniones (con indicador de recursos)

**Endpoint:** `GET /api/v1/meetings`

**Request:**
```bash
GET /api/v1/meetings?per_page=15
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "data": [
    {
      "id": 42,
      "title": "Reunión Parque Principal",
      "description": "Presentación de propuestas",
      "starts_at": "2025-11-15T18:00:00-05:00",
      "ends_at": "2025-11-15T20:00:00-05:00",
      "status": "scheduled",
      "lugar_nombre": "Parque Principal",
      
      "attendees_count": 35,
      "commitments_count": 5,
      "resource_allocations_count": 1,
      "has_resources": true,
      
      "planner": {
        "id": 2,
        "name": "Admin Usuario"
      },
      
      "municipality": {
        "id": 1,
        "nombre": "Barranquilla"
      }
    },
    {
      "id": 43,
      "title": "Reunión Barrio Norte",
      "starts_at": "2025-11-16T10:00:00-05:00",
      "status": "scheduled",
      
      "attendees_count": 12,
      "commitments_count": 2,
      "resource_allocations_count": 0,
      "has_resources": false
    }
  ],
  "meta": {
    "total": 25,
    "current_page": 1,
    "last_page": 2,
    "per_page": 15
  }
}
```

**¿Cómo mostrar el indicador?**

En tu lista de reuniones, puedes mostrar:
```tsx
{meeting.has_resources && (
  <Badge color="blue" icon={BoxIcon}>
    {meeting.resource_allocations_count} recursos
  </Badge>
)}
```

---

### 4. Ver Recursos de una Reunión Específica

**Endpoint:** `GET /api/v1/resource-allocations/by-meeting/{meeting_id}`

**Request:**
```bash
GET /api/v1/resource-allocations/by-meeting/42
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "data": [
    {
      "id": 15,
      "tenant_id": 1,
      "assigned_by_user_id": 2,
      "leader_user_id": 5,
      "assigned_to_user_id": 5,
      "meeting_id": 42,
      "title": "Recursos para reunión en Parque Principal",
      "allocation_date": "2025-11-15",
      "status": "pending",
      "total_cost": 340000.00,
      
      "assigned_by": {
        "id": 2,
        "name": "Admin Usuario"
      },
      
      "leader": {
        "id": 5,
        "name": "Carlos Pérez"
      },
      
      "items": [
        {
          "id": 20,
          "quantity": 50,
          "unit_cost": 5000.00,
          "subtotal": 250000.00,
          "status": "pending",
          
          "resource_item": {
            "id": 1,
            "name": "Silla plástica",
            "unit": "unidad"
          }
        },
        {
          "id": 21,
          "quantity": 2,
          "unit_cost": 45000.00,
          "subtotal": 90000.00,
          "status": "pending",
          
          "resource_item": {
            "id": 2,
            "name": "Banner 2x1 metros",
            "unit": "unidad"
          }
        }
      ]
    }
  ],
  "summary": {
    "total_cash": 0,
    "total_material": 0,
    "total_service": 0,
    "total_cost": 340000.00,
    "grand_total": 340000.00
  }
}
```

---

### 5. Ver Detalle de una Reunión (con recursos incluidos)

**Endpoint:** `GET /api/v1/meetings/{id}?include=resourceAllocations`

**Request:**
```bash
GET /api/v1/meetings/42?include=resourceAllocations
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "data": {
    "id": 42,
    "title": "Reunión Parque Principal",
    "description": "Presentación de propuestas",
    "starts_at": "2025-11-15T18:00:00-05:00",
    "ends_at": "2025-11-15T20:00:00-05:00",
    "status": "scheduled",
    
    "attendees_count": 35,
    "commitments_count": 5,
    "resource_allocations_count": 1,
    "has_resources": true,
    
    "planner": {
      "id": 2,
      "name": "Admin Usuario"
    },
    
    "resource_allocations": [
      {
        "id": 15,
        "title": "Recursos para reunión en Parque Principal",
        "status": "pending",
        "total_cost": 340000.00,
        "allocation_date": "2025-11-15",
        
        "items": [
          {
            "id": 20,
            "quantity": 50,
            "subtotal": 250000.00,
            "resource_item": {
              "id": 1,
              "name": "Silla plástica",
              "unit": "unidad",
              "stock_quantity": 200,
              "reserved_quantity": 50,
              "available_quantity": 150
            }
          },
          {
            "id": 21,
            "quantity": 2,
            "subtotal": 90000.00,
            "resource_item": {
              "id": 2,
              "name": "Banner 2x1 metros",
              "unit": "unidad",
              "stock_quantity": 15,
              "reserved_quantity": 7,
              "available_quantity": 8
            }
          }
        ]
      }
    ]
  }
}
```

---

### 6. Actualizar Estado de una Asignación

**Endpoint:** `PATCH /api/v1/resource-allocations/{id}`

**🔥 IMPORTANTE: El backend actualiza el inventario automáticamente según el cambio de estado**

#### Marcar como Entregada (pending → delivered)

**Request:**
```json
{
  "status": "delivered",
  "notes": "Entregado el 14/11/2025 a las 15:00"
}
```

**¿Qué hace el backend automáticamente?**
1. ✅ **Libera la reserva** (`reserved_quantity` disminuye)
2. ✅ **Descuenta del stock real** (`stock_quantity` disminuye)
3. ✅ **Actualiza estado a `delivered`**

**Antes del cambio:**
```
stock_quantity: 200
reserved_quantity: 50
available_quantity: 150
```

**Después del cambio:**
```
stock_quantity: 150 (descontado)
reserved_quantity: 0 (liberado)
available_quantity: 150 (sin cambios)
```

**Response (200):**
```json
{
  "data": {
    "id": 15,
    "status": "delivered",
    "notes": "Entregado el 14/11/2025 a las 15:00",
    "updated_at": "2025-11-14T15:05:00-05:00",
    "items": [
      {
        "resource_item": {
          "stock_quantity": 150,
          "reserved_quantity": 0,
          "available_quantity": 150
        }
      }
    ]
  },
  "message": "Resource allocation updated successfully"
}
```

#### Cancelar Asignación (pending → cancelled)

**Request:**
```json
{
  "status": "cancelled"
}
```

**¿Qué hace el backend automáticamente?**
1. ✅ **Libera la reserva** (`reserved_quantity` disminuye)
2. ✅ **NO toca el stock** (no se había descontado)
3. ✅ **Aumenta disponibilidad** (`available_quantity` aumenta)

**Response (200):**
```json
{
  "data": {
    "id": 15,
    "status": "cancelled"
  },
  "message": "Resource allocation updated successfully"
}
```

#### Devolver Recursos (delivered → returned)

**Request:**
```json
{
  "status": "returned"
}
```

**¿Qué hace el backend automáticamente?**
1. ✅ **Devuelve al stock** (`stock_quantity` aumenta)
2. ✅ **Actualiza estado a `returned`**

**Response (200):**
```json
{
  "data": {
    "id": 15,
    "status": "returned"
  },
  "message": "Resource allocation updated successfully"
}
```

#### ❌ Error: Transición No Permitida (422)

**Request:**
```json
{
  "status": "pending"  // Desde "delivered"
}
```

**Response (422):**
```json
{
  "message": "Cambio de estado no permitido: delivered -> pending",
  "allowed_transitions": [
    "pending -> delivered",
    "pending -> cancelled",
    "delivered -> returned"
  ]
}
```

**Transiciones válidas:**
```
pending ──→ delivered ──→ returned
   │
   └──────→ cancelled
```

**¿Cómo implementarlo en el frontend?**
```typescript
async function updateAllocationStatus(allocationId: number, newStatus: string) {
  try {
    const response = await fetch(`/api/v1/resource-allocations/${allocationId}`, {
      method: 'PATCH',
      headers: {
        'Authorization': `Bearer ${token}`,
        'Content-Type': 'application/json'
      },
      body: JSON.stringify({ status: newStatus })
    });

    if (response.status === 422) {
      const error = await response.json();
      
      // Mostrar transiciones permitidas
      toast.error(
        `${error.message}\n\n` +
        `Transiciones permitidas:\n` +
        error.allowed_transitions.join('\n')
      );
      return;
    }

    if (response.ok) {
      const result = await response.json();
      
      // Mensaje específico según el cambio
      if (newStatus === 'delivered') {
        toast.success('✓ Recursos entregados. Stock actualizado automáticamente.');
      } else if (newStatus === 'returned') {
        toast.success('↩ Recursos devueltos. Stock restaurado automáticamente.');
      } else if (newStatus === 'cancelled') {
        toast.success('✗ Asignación cancelada. Reserva liberada.');
      }
      
      // Refrescar catálogo de recursos para mostrar stock actualizado
      refreshResourceCatalog();
      refreshAllocation();
    }
  } catch (error) {
    toast.error('Error al actualizar estado');
  }
}
```

---

### 7. Actualizar Estado de un Item Individual

**Endpoint:** `PATCH /api/v1/resource-allocation-items/{item_id}/status`

**Request:**
```bash
PATCH /api/v1/resource-allocation-items/20/status
Authorization: Bearer {token}
Content-Type: application/json

{
  "status": "delivered"
}
```

**Response (200):**
```json
{
  "data": {
    "id": 20,
    "resource_allocation_id": 15,
    "quantity": 50,
    "status": "delivered",
    "updated_at": "2025-11-14T15:10:00-05:00"
  },
  "message": "Item status updated successfully"
}
```

---

### 8. Ver Recursos Asignados a un Líder

**Endpoint:** `GET /api/v1/resource-allocations/by-leader/{user_id}`

**Request:**
```bash
GET /api/v1/resource-allocations/by-leader/5?per_page=20
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "data": [
    {
      "id": 15,
      "title": "Recursos para reunión en Parque Principal",
      "status": "pending",
      "total_cost": 340000.00,
      "allocation_date": "2025-11-15",
      
      "meeting": {
        "id": 42,
        "title": "Reunión Parque Principal",
        "starts_at": "2025-11-15T18:00:00-05:00"
      }
    },
    {
      "id": 16,
      "title": "Mobiliario para evento",
      "status": "delivered",
      "total_cost": 850000.00,
      "allocation_date": "2025-11-10"
    }
  ],
  "meta": {
    "total": 2,
    "current_page": 1,
    "last_page": 1
  },
  "summary": {
    "total_cash": 0,
    "total_material": 0,
    "total_service": 0,
    "total_cost": 1190000.00,
    "grand_total": 1190000.00
  }
}
```

---

### 9. Eliminar una Asignación

**Endpoint:** `DELETE /api/v1/resource-allocations/{id}`

**Request:**
```bash
DELETE /api/v1/resource-allocations/15
Authorization: Bearer {token}
```

**Response (200):**
```json
{
  "message": "Resource allocation deleted successfully"
}
```

---

## Casos de Uso Prácticos

### Caso 1: Lista de Reuniones con Indicador de Recursos

**¿Qué mostrar?**
- Nombre de la reunión
- Fecha/hora
- Ubicación
- Badge/indicador si tiene recursos asignados
- Cantidad de recursos asignados

**Request:**
```bash
GET /api/v1/meetings?per_page=15
```

**UI sugerida:**
```tsx
function MeetingListItem({ meeting }) {
  return (
    <div className="meeting-card">
      <h3>{meeting.title}</h3>
      <p>{formatDate(meeting.starts_at)}</p>
      
      <div className="badges">
        {meeting.attendees_count > 0 && (
          <Badge>{meeting.attendees_count} asistentes</Badge>
        )}
        
        {meeting.has_resources && (
          <Badge color="blue" icon={BoxIcon}>
            {meeting.resource_allocations_count} recursos asignados
          </Badge>
        )}
      </div>
      
      <button onClick={() => viewMeeting(meeting.id)}>
        Ver detalle
      </button>
    </div>
  );
}
```

---

### Caso 2: Detalle de Reunión - Tab de Recursos

**¿Qué mostrar?**
- Lista de asignaciones de recursos para esta reunión
- Cada asignación muestra:
  - Título
  - Estado (pending/delivered/returned/cancelled)
  - Líder asignado
  - Lista de items con cantidades
  - Costo total

**Request:**
```bash
GET /api/v1/resource-allocations/by-meeting/42
```

**UI sugerida:**
```tsx
function MeetingResourcesTab({ meetingId }) {
  const { data, summary } = useFetch(`/api/v1/resource-allocations/by-meeting/${meetingId}`);
  
  return (
    <div>
      <div className="summary">
        <h3>Total: ${formatCurrency(summary.grand_total)}</h3>
      </div>
      
      {data.map(allocation => (
        <div key={allocation.id} className="allocation-card">
          <div className="header">
            <h4>{allocation.title || 'Sin título'}</h4>
            <StatusBadge status={allocation.status} />
          </div>
          
          <p>Asignado a: {allocation.leader.name}</p>
          <p>Fecha entrega: {formatDate(allocation.allocation_date)}</p>
          
          <div className="items">
            <h5>Items asignados:</h5>
            {allocation.items.map(item => (
              <div key={item.id} className="item-row">
                <span>{item.resource_item.name}</span>
                <span>x{item.quantity}</span>
                <span>${formatCurrency(item.subtotal)}</span>
              </div>
            ))}
          </div>
          
          <div className="total">
            <strong>Total: ${formatCurrency(allocation.total_cost)}</strong>
          </div>
          
          {allocation.status === 'pending' && (
            <button onClick={() => markAsDelivered(allocation.id)}>
              Marcar como entregado
            </button>
          )}
        </div>
      ))}
    </div>
  );
}
```

---

### Caso 3: Crear Asignación de Recursos

**Flow:**
1. Usuario hace clic en "Asignar recursos" desde una reunión
2. Modal/página se abre con formulario
3. Selecciona recursos del catálogo
4. Indica cantidades
5. Guarda

**UI sugerida:**
```tsx
function CreateResourceAllocation({ meetingId, leaderId }) {
  const [items, setItems] = useState([]);
  const { data: catalog } = useFetch('/api/v1/resource-items');
  
  const addItem = (resource) => {
    // Validar que haya stock disponible
    if (resource.available_quantity === 0) {
      toast.error(`Sin stock disponible de "${resource.name}"`);
      return;
    }
    
    setItems([...items, {
      resource_item_id: resource.id,
      quantity: 1,
      max_available: resource.available_quantity,
      notes: ''
    }]);
  };
  
  const handleSubmit = async () => {
    try {
      const response = await fetch('/api/v1/resource-allocations', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          meeting_id: meetingId,
          leader_user_id: leaderId,
          title: `Recursos para ${meetingTitle}`,
          allocation_date: selectedDate,
          items: items.map(({ resource_item_id, quantity, notes }) => ({
            resource_item_id,
            quantity,
            notes
          }))
        })
      });
      
      if (response.status === 422) {
        // Error de stock insuficiente
        const error = await response.json();
        toast.error(
          `${error.message}\n\n` +
          `Solicitado: ${error.requested}\n` +
          `Disponible: ${error.available}`
        );
        
        // Refrescar catálogo (otro usuario pudo reservar)
        refreshCatalog();
        return;
      }
      
      if (response.ok) {
        toast.success('✓ Recursos asignados y reservados exitosamente');
        onClose();
        refreshMeeting();
        refreshCatalog(); // Actualizar stock disponible
      }
    } catch (error) {
      toast.error('Error de conexión');
    }
  };
  
  return (
    <Modal>
      <h2>Asignar Recursos</h2>
      
      <div className="catalog">
        <h3>Catálogo de Recursos</h3>
        {catalog.map(resource => (
          <div key={resource.id} className="resource-card">
            <span>{resource.name}</span>
            <span>Stock: {resource.stock_quantity}</span>
            <span>${resource.unit_cost}</span>
            <button onClick={() => addItem(resource.id)}>
              Agregar
            </button>
          </div>
        ))}
      </div>
      
      <div className="selected-items">
        <h3>Recursos Seleccionados</h3>
        {items.map((item, index) => (
          <div key={index} className="item-row">
            <span>{getResourceName(item.resource_item_id)}</span>
            <input
              type="number"
              value={item.quantity}
              onChange={(e) => updateQuantity(index, e.target.value)}
            />
            <button onClick={() => removeItem(index)}>Quitar</button>
          </div>
        ))}
      </div>
      
      <button onClick={handleSubmit}>Guardar Asignación</button>
    </Modal>
  );
}
```

---

### Caso 4: Panel de Control de Recursos del Líder

**¿Qué mostrar?**
- Lista de recursos asignados al líder
- Filtro por estado (pending/delivered/returned)
- Resumen de totales

**Request:**
```bash
GET /api/v1/resource-allocations/by-leader/5?filter[status]=pending
```

**UI sugerida:**
```tsx
function LeaderResourcesPanel({ userId }) {
  const [statusFilter, setStatusFilter] = useState('all');
  const { data, summary } = useFetch(
    `/api/v1/resource-allocations/by-leader/${userId}${statusFilter !== 'all' ? `?filter[status]=${statusFilter}` : ''}`
  );
  
  return (
    <div>
      <h2>Mis Recursos Asignados</h2>
      
      <div className="filters">
        <button onClick={() => setStatusFilter('all')}>Todos</button>
        <button onClick={() => setStatusFilter('pending')}>Pendientes</button>
        <button onClick={() => setStatusFilter('delivered')}>Entregados</button>
      </div>
      
      <div className="summary">
        <div className="summary-card">
          <h3>Total Asignado</h3>
          <p>${formatCurrency(summary.grand_total)}</p>
        </div>
      </div>
      
      <div className="allocations-list">
        {data.map(allocation => (
          <AllocationCard key={allocation.id} allocation={allocation} />
        ))}
      </div>
    </div>
  );
}
```

---

## Ejemplos de UI

### Badge de Estado

```tsx
function StatusBadge({ status }) {
  const config = {
    pending: { color: 'yellow', text: 'Pendiente', icon: '⏳' },
    delivered: { color: 'blue', text: 'Entregado', icon: '✓' },
    returned: { color: 'green', text: 'Devuelto', icon: '↩' },
    cancelled: { color: 'red', text: 'Cancelado', icon: '✗' }
  };
  
  const { color, text, icon } = config[status];
  
  return (
    <span className={`badge badge-${color}`}>
      {icon} {text}
    </span>
  );
}
```

### Resumen de Costos

```tsx
function AllocationSummary({ allocation }) {
  return (
    <div className="allocation-summary">
      <div className="items-list">
        {allocation.items.map(item => (
          <div key={item.id} className="item-line">
            <span className="name">{item.resource_item.name}</span>
            <span className="quantity">x {item.quantity}</span>
            <span className="price">${formatCurrency(item.subtotal)}</span>
          </div>
        ))}
      </div>
      
      <div className="total-line">
        <strong>TOTAL</strong>
        <strong>${formatCurrency(allocation.total_cost)}</strong>
      </div>
    </div>
  );
}
```

---

## Resumen de Endpoints

| Método | Endpoint | Descripción |
|--------|----------|-------------|
| GET | `/api/v1/resource-items` | Listar catálogo de recursos |
| POST | `/api/v1/resource-allocations` | Crear asignación |
| GET | `/api/v1/resource-allocations` | Listar todas las asignaciones |
| GET | `/api/v1/resource-allocations/{id}` | Ver detalle de asignación |
| PATCH | `/api/v1/resource-allocations/{id}` | Actualizar asignación (cambiar estado) |
| DELETE | `/api/v1/resource-allocations/{id}` | Eliminar asignación |
| GET | `/api/v1/resource-allocations/by-meeting/{meeting_id}` | Recursos de una reunión |
| GET | `/api/v1/resource-allocations/by-leader/{user_id}` | Recursos de un líder |
| PATCH | `/api/v1/resource-allocation-items/{id}/status` | Cambiar estado de un item |
| GET | `/api/v1/meetings?include=resourceAllocations` | Reuniones con recursos |

---

## Notas Importantes

1. **Estado inicial:** Todas las asignaciones se crean en `pending` automáticamente

2. **Contador en reuniones:** Para mostrar si una reunión tiene recursos, usa `resource_allocations_count` y `has_resources`

3. **Include en reuniones:** Usa `?include=resourceAllocations` para cargar los recursos con la reunión

4. **Filtros disponibles:**
   - Por tipo: `?filter[type]=material`
   - Por reunión: `?filter[meeting_id]=42`
   - Por líder: `?filter[leader_user_id]=5`
   - Por estado: `?filter[status]=pending`

5. **Total cost:** Se calcula automáticamente sumando todos los items

6. **Inventario:** ⭐ **El backend controla TODO el inventario automáticamente** - Ver sección siguiente

---

## 📦 Control de Inventario (MUY IMPORTANTE)

### ¿Cómo funciona el stock?

El backend maneja **3 cantidades diferentes** para cada recurso:

| Campo | Qué es | Cuándo cambia |
|-------|--------|---------------|
| `stock_quantity` | Total físico en almacén | Al entregar o devolver |
| `reserved_quantity` | Reservado (asignaciones pending) | Al crear, cancelar o entregar |
| `available_quantity` | **Disponible para asignar** | Calculado: stock - reserved |

**Fórmula:** `available_quantity = stock_quantity - reserved_quantity`

### Ejemplo Práctico

Tienes 100 sillas en el almacén:

```
1. ESTADO INICIAL
   stock_quantity: 100
   reserved_quantity: 0
   available_quantity: 100  ← Puedes asignar 100

2. CREAR ASIGNACIÓN (50 sillas, estado: pending)
   Backend RESERVA automáticamente:
   stock_quantity: 100      (sin cambios - aún están en el almacén)
   reserved_quantity: 50    (reservadas)
   available_quantity: 50   ← Ahora solo puedes asignar 50

3. MARCAR COMO ENTREGADO
   Backend DESCUENTA y LIBERA:
   stock_quantity: 50       (descontadas - salieron del almacén)
   reserved_quantity: 0     (liberadas)
   available_quantity: 50   ← Disponibles las que quedaron

4. DEVOLVER RECURSOS
   Backend DEVUELVE:
   stock_quantity: 100      (devueltas al almacén)
   reserved_quantity: 0
   available_quantity: 100  ← Vuelven a estar disponibles
```

### ¿Qué debe hacer el frontend?

#### ✅ LO QUE SÍ DEBE HACER:

1. **Mostrar `available_quantity` en el catálogo**
   ```tsx
   <p>Disponible: {resource.available_quantity}</p>
   ```

2. **Deshabilitar botón si no hay disponibles**
   ```tsx
   <button disabled={resource.available_quantity === 0}>
     {resource.available_quantity === 0 ? 'Sin stock' : 'Agregar'}
   </button>
   ```

3. **Mostrar información completa del stock**
   ```tsx
   <div className="stock-info">
     <div>En almacén: {resource.stock_quantity}</div>
     <div>Reservado: {resource.reserved_quantity}</div>
     <div><strong>Disponible: {resource.available_quantity}</strong></div>
   </div>
   ```

4. **Validar visualmente antes de enviar (opcional)**
   ```typescript
   const selectedQuantity = items.reduce((sum, item) => sum + item.quantity, 0);
   if (selectedQuantity > resource.available_quantity) {
     toast.warning(`Solo hay ${resource.available_quantity} disponibles`);
     return;
   }
   ```

5. **Manejar errores 422 del backend**
   ```typescript
   if (response.status === 422) {
     const error = await response.json();
     toast.error(`${error.message}\nDisponible: ${error.available}`);
   }
   ```

6. **Refrescar catálogo después de cambios de estado**
   ```typescript
   // Después de marcar como entregado/devuelto/cancelado
   await updateStatus(allocationId, 'delivered');
   await refreshResourceCatalog(); // ← Recargar para ver stock actualizado
   ```

#### ❌ LO QUE NO DEBE HACER:

1. ❌ **NO calcular el stock disponible**
   ```typescript
   // MAL ❌
   const available = resource.stock_quantity - resource.reserved_quantity;
   
   // BIEN ✅
   const available = resource.available_quantity; // Ya viene calculado
   ```

2. ❌ **NO intentar modificar las cantidades localmente**
   ```typescript
   // MAL ❌
   resource.stock_quantity -= quantity;
   resource.reserved_quantity += quantity;
   
   // BIEN ✅
   // El backend lo hace automáticamente, solo refresca:
   await refreshResourceCatalog();
   ```

3. ❌ **NO asumir que tienes stock solo porque `stock_quantity > 0`**
   ```typescript
   // MAL ❌
   if (resource.stock_quantity > 0) {
     // Puede estar todo reservado
   }
   
   // BIEN ✅
   if (resource.available_quantity > 0) {
     // Realmente disponible
   }
   ```

4. ❌ **NO validar solo en el frontend**
   ```typescript
   // MAL ❌
   if (quantity <= available) {
     // Solo enviar
   }
   
   // BIEN ✅
   // Validar visualmente pero SIEMPRE manejar error 422 del backend
   try {
     await createAllocation(...);
   } catch (error) {
     if (error.status === 422) {
       // El backend es la fuente de verdad
     }
   }
   ```

### Ejemplo Completo de Componente

```tsx
function ResourceCatalog() {
  const [resources, setResources] = useState([]);
  const [selectedItems, setSelectedItems] = useState([]);

  const loadResources = async () => {
    const response = await fetch('/api/v1/resource-items', {
      headers: { 'Authorization': `Bearer ${token}` }
    });
    const data = await response.json();
    setResources(data.data);
  };

  const addItem = (resource) => {
    if (resource.available_quantity === 0) {
      toast.error('No hay stock disponible');
      return;
    }
    
    setSelectedItems([...selectedItems, {
      resource_item_id: resource.id,
      quantity: 1,
      max_available: resource.available_quantity
    }]);
  };

  const createAllocation = async () => {
    try {
      const response = await fetch('/api/v1/resource-allocations', {
        method: 'POST',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          leader_user_id: currentUserId,
          meeting_id: meetingId,
          items: selectedItems
        })
      });

      if (response.status === 422) {
        const error = await response.json();
        toast.error(
          `${error.message}\n\n` +
          `Solicitado: ${error.requested}\n` +
          `Disponible: ${error.available}`
        );
        
        // Refrescar catálogo por si otro usuario reservó mientras tanto
        await loadResources();
        return;
      }

      if (response.ok) {
        toast.success('Recursos asignados y reservados exitosamente');
        setSelectedItems([]);
        
        // Refrescar para ver el stock actualizado
        await loadResources();
        onSuccess();
      }
    } catch (error) {
      toast.error('Error de conexión');
    }
  };

  return (
    <div>
      <h2>Catálogo de Recursos</h2>
      
      {resources.map(resource => (
        <div key={resource.id} className="resource-card">
          <h3>{resource.name}</h3>
          <p>${resource.unit_cost} / {resource.unit}</p>
          
          <div className="stock-info">
            <div className="stock-row">
              <span>En almacén:</span>
              <span>{resource.stock_quantity}</span>
            </div>
            <div className="stock-row text-warning">
              <span>Reservado:</span>
              <span>{resource.reserved_quantity}</span>
            </div>
            <div className="stock-row text-success">
              <span><strong>Disponible:</strong></span>
              <strong>{resource.available_quantity}</strong>
            </div>
          </div>
          
          {resource.is_low_stock && (
            <Badge color="red">⚠️ Stock bajo</Badge>
          )}
          
          <button
            onClick={() => addItem(resource)}
            disabled={resource.available_quantity === 0}
          >
            {resource.available_quantity === 0 
              ? 'Sin stock' 
              : `Agregar (${resource.available_quantity} disponibles)`
            }
          </button>
        </div>
      ))}
      
      {selectedItems.length > 0 && (
        <div className="selected-items">
          <h3>Recursos Seleccionados</h3>
          {/* Mostrar items seleccionados */}
          <button onClick={createAllocation}>
            Crear Asignación
          </button>
        </div>
      )}
    </div>
  );
}
```

### Escenario: Dos Usuarios Simultáneos

**Situación:** Quedan 10 sillas disponibles. Usuario A y Usuario B intentan asignar 10 al mismo tiempo.

**¿Qué pasa?**

1. Usuario A envía request primero
   - Backend RESERVA 10 sillas
   - `available_quantity` = 0
   - Response: ✅ Success

2. Usuario B envía request 1 segundo después
   - Backend valida: `available_quantity` = 0
   - Response: ❌ Error 422 "Stock insuficiente"
   
3. Usuario B recibe el error y ve:
   ```json
   {
     "message": "Stock insuficiente para 'Silla plástica'",
     "requested": 10,
     "available": 0,
     "in_stock": 100,
     "reserved": 100
   }
   ```

4. Usuario B debe refrescar el catálogo y seleccionar menos cantidad (o esperar a que se liberen reservas)

**✅ El backend usa transacciones de BD para evitar condiciones de carrera**

### Resumen para el Frontend

| Acción | Backend hace | Frontend debe |
|--------|--------------|---------------|
| Crear asignación | Reserva stock | Manejar error 422 si no hay stock |
| Marcar como entregado | Descuenta stock | Mostrar toast "Stock actualizado" |
| Devolver | Devuelve stock | Refrescar catálogo |
| Cancelar | Libera reserva | Refrescar catálogo |
| Eliminar pending | Libera reserva | Refrescar catálogo |

**Regla de oro:** 
- ✅ Usa `available_quantity` para saber cuánto se puede asignar
- ✅ Confía en el backend para todas las validaciones
- ✅ Refresca el catálogo después de cambios de estado
- ❌ NUNCA calcules o modifiques el stock localmente
