# Sistema de Control de Inventario - Recursos

## ¿Cómo Funciona?

El backend **SIEMPRE** controla el inventario automáticamente. El frontend **SOLO** debe mostrar la información y permitir las acciones, pero **NUNCA** calcular o modificar directamente el stock.

---

## Conceptos Clave

### Campos en `resource_items`

| Campo | Descripción | Ejemplo |
|-------|-------------|---------|
| `stock_quantity` | Cantidad total en el almacén | 100 |
| `reserved_quantity` | Cantidad reservada (asignada pero no entregada) | 20 |
| `available_quantity` | Cantidad disponible para asignar | 80 |
| `min_stock` | Nivel mínimo de alerta | 10 |

**Fórmula:**
```
available_quantity = stock_quantity - reserved_quantity
```

---

## Flujo Automático del Inventario

### 1. Al Crear una Asignación (Estado: `pending`)

**Request:**
```json
POST /api/v1/resource-allocations
{
  "leader_user_id": 5,
  "meeting_id": 42,
  "items": [
    {
      "resource_item_id": 1,
      "quantity": 50
    }
  ]
}
```

**¿Qué hace el backend?**

1. ✅ **Valida que hay stock disponible**
   ```php
   if (available_quantity < 50) {
     return error 422
   }
   ```

2. ✅ **Reserva el stock**
   ```
   reserved_quantity += 50
   available_quantity = stock_quantity - reserved_quantity
   ```

3. ✅ **Crea la asignación con status: 'pending'**

**Estado después:**
```
stock_quantity: 100      (sin cambios)
reserved_quantity: 20 + 50 = 70
available_quantity: 100 - 70 = 30
```

**Response exitosa (201):**
```json
{
  "data": {
    "id": 15,
    "status": "pending",
    "items": [
      {
        "resource_item": {
          "name": "Silla plástica",
          "stock_quantity": 100,
          "reserved_quantity": 70,
          "available_quantity": 30
        }
      }
    ]
  },
  "message": "Asignación de recursos creada exitosamente"
}
```

**Error si no hay stock (422):**
```json
{
  "message": "Stock insuficiente para 'Silla plástica'",
  "resource": "Silla plástica",
  "requested": 50,
  "available": 15,
  "in_stock": 100,
  "reserved": 85
}
```

---

### 2. Al Marcar como Entregado (Estado: `pending` → `delivered`)

**Request:**
```json
PATCH /api/v1/resource-allocations/15
{
  "status": "delivered"
}
```

**¿Qué hace el backend?**

1. ✅ **Libera la reserva**
   ```
   reserved_quantity -= 50
   ```

2. ✅ **Descuenta del stock real**
   ```
   stock_quantity -= 50
   ```

3. ✅ **Actualiza el estado a 'delivered'**

**Estado después:**
```
stock_quantity: 100 - 50 = 50
reserved_quantity: 70 - 50 = 20
available_quantity: 50 - 20 = 30
```

**Response (200):**
```json
{
  "data": {
    "id": 15,
    "status": "delivered"
  },
  "message": "Resource allocation updated successfully"
}
```

---

### 3. Al Devolver Recursos (Estado: `delivered` → `returned`)

**Request:**
```json
PATCH /api/v1/resource-allocations/15
{
  "status": "returned"
}
```

**¿Qué hace el backend?**

1. ✅ **Devuelve al stock**
   ```
   stock_quantity += 50
   ```

2. ✅ **Actualiza el estado a 'returned'**

**Estado después:**
```
stock_quantity: 50 + 50 = 100
reserved_quantity: 20 (sin cambios)
available_quantity: 100 - 20 = 80
```

---

### 4. Al Cancelar una Asignación (Estado: `pending` → `cancelled`)

**Request:**
```json
PATCH /api/v1/resource-allocations/15
{
  "status": "cancelled"
}
```

**¿Qué hace el backend?**

1. ✅ **Libera la reserva**
   ```
   reserved_quantity -= 50
   ```

2. ✅ **NO toca el stock** (no se había descontado)

3. ✅ **Actualiza el estado a 'cancelled'**

**Estado después:**
```
stock_quantity: 100 (sin cambios)
reserved_quantity: 70 - 50 = 20
available_quantity: 100 - 20 = 80
```

---

### 5. Al Eliminar una Asignación Pendiente

**Request:**
```json
DELETE /api/v1/resource-allocations/15
```

**¿Qué hace el backend?**

1. ✅ **Si está en 'pending', libera la reserva**
   ```
   reserved_quantity -= 50
   ```

2. ✅ **Elimina la asignación**

---

## Transiciones de Estado Permitidas

```
┌─────────┐
│ pending │ ────────────────────────────────┐
└────┬────┘                                 │
     │                                      │
     │ ✅ delivered                         │ ✅ cancelled
     ▼                                      ▼
┌───────────┐                          ┌───────────┐
│ delivered │                          │ cancelled │
└─────┬─────┘                          └───────────┘
      │
      │ ✅ returned
      ▼
┌──────────┐
│ returned │
└──────────┘
```

**Transiciones válidas:**
- ✅ `pending` → `delivered`
- ✅ `pending` → `cancelled`
- ✅ `delivered` → `returned`

**Transiciones NO permitidas:**
- ❌ `delivered` → `pending`
- ❌ `returned` → `delivered`
- ❌ `cancelled` → cualquier otro

**Error si intentas una transición no permitida (422):**
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

---

## Frontend: ¿Qué Mostrar?

### En el Catálogo de Recursos

```tsx
function ResourceCard({ resource }) {
  return (
    <div className="resource-card">
      <h3>{resource.name}</h3>
      <p>${resource.unit_cost} / {resource.unit}</p>
      
      <div className="stock-info">
        <div className="stock-row">
          <span>En almacén:</span>
          <strong>{resource.stock_quantity}</strong>
        </div>
        
        <div className="stock-row warning">
          <span>Reservado:</span>
          <strong>{resource.reserved_quantity}</strong>
        </div>
        
        <div className="stock-row success">
          <span>Disponible:</span>
          <strong>{resource.available_quantity}</strong>
        </div>
      </div>
      
      {resource.is_low_stock && (
        <Badge color="red">⚠️ Stock bajo</Badge>
      )}
      
      <button 
        disabled={resource.available_quantity === 0}
        onClick={() => addToAllocation(resource.id)}
      >
        {resource.available_quantity === 0 ? 'Sin stock' : 'Agregar'}
      </button>
    </div>
  );
}
```

### Al Crear una Asignación

```tsx
function CreateAllocation() {
  const [selectedItems, setSelectedItems] = useState([]);
  
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
          items: selectedItems
        })
      });
      
      if (response.status === 422) {
        const error = await response.json();
        
        // El backend te dice exactamente qué está mal
        toast.error(
          `${error.message}\n` +
          `Solicitado: ${error.requested}\n` +
          `Disponible: ${error.available}`
        );
        return;
      }
      
      if (response.ok) {
        toast.success('Recursos asignados exitosamente');
        onSuccess();
      }
    } catch (error) {
      toast.error('Error al crear asignación');
    }
  };
  
  return (
    <form onSubmit={handleSubmit}>
      {/* Tu formulario */}
    </form>
  );
}
```

### Al Cambiar Estado

```tsx
function AllocationActions({ allocation }) {
  const markAsDelivered = async () => {
    try {
      const response = await fetch(`/api/v1/resource-allocations/${allocation.id}`, {
        method: 'PATCH',
        headers: {
          'Authorization': `Bearer ${token}`,
          'Content-Type': 'application/json'
        },
        body: JSON.stringify({
          status: 'delivered'
        })
      });
      
      if (response.status === 422) {
        const error = await response.json();
        toast.error(error.message);
        return;
      }
      
      if (response.ok) {
        toast.success('Recursos marcados como entregados');
        toast.info('El stock se ha actualizado automáticamente');
        refresh();
      }
    } catch (error) {
      toast.error('Error al actualizar estado');
    }
  };
  
  return (
    <div>
      {allocation.status === 'pending' && (
        <>
          <button onClick={markAsDelivered}>
            ✓ Marcar como Entregado
          </button>
          <button onClick={cancel}>
            ✗ Cancelar
          </button>
        </>
      )}
      
      {allocation.status === 'delivered' && (
        <button onClick={markAsReturned}>
          ↩ Marcar como Devuelto
        </button>
      )}
    </div>
  );
}
```

---

## Validaciones del Backend

### Al Crear

1. ✅ Verifica que el recurso existe
2. ✅ Verifica que hay stock disponible (no reservado)
3. ✅ Reserva el stock automáticamente
4. ✅ Crea la asignación en estado `pending`

### Al Actualizar Estado

1. ✅ Verifica que la transición es válida
2. ✅ Actualiza el inventario según el cambio:
   - `pending` → `delivered`: Descuenta del stock
   - `delivered` → `returned`: Devuelve al stock
   - `pending` → `cancelled`: Libera reserva
3. ✅ Actualiza el estado de todos los items

### Al Eliminar

1. ✅ Si está en `pending`, libera las reservas
2. ✅ Elimina la asignación

---

## Respuesta del API con Stock Actualizado

**GET /api/v1/resource-items**

```json
{
  "data": [
    {
      "id": 1,
      "name": "Silla plástica",
      "unit": "unidad",
      "unit_cost": 5000.00,
      "stock_quantity": 100,
      "reserved_quantity": 20,
      "available_quantity": 80,
      "min_stock": 10,
      "is_low_stock": false
    }
  ]
}
```

**Interpretación:**
- Hay 100 sillas en total en el almacén
- 20 están reservadas para asignaciones pendientes
- Quedan 80 disponibles para nuevas asignaciones
- No está en nivel bajo (tiene más de 10)

---

## Preguntas Frecuentes

### ¿El frontend debe validar el stock antes de enviar?

**Recomendación:** Puedes hacer una validación visual (deshabilitar botón si available_quantity es 0), pero **SIEMPRE** el backend validará de nuevo. No confíes solo en la validación del frontend.

### ¿Qué pasa si dos usuarios intentan asignar el último recurso al mismo tiempo?

El backend usa **transacciones de base de datos** (`DB::beginTransaction()`). Solo uno lo obtendrá, el otro recibirá error 422 de stock insuficiente.

### ¿Puedo ver qué asignaciones tienen reservado un recurso?

Sí, puedes crear un endpoint adicional si lo necesitas, pero por ahora el campo `reserved_quantity` te dice el total reservado.

### ¿Qué pasa si borro una asignación que ya fue entregada?

Si está en `delivered`, NO se reversa el stock automáticamente al eliminar (porque ya salió del inventario). Primero debes marcarla como `returned` si quieres devolver el stock.

### ¿Cómo manejo devoluciones parciales?

Actualmente el sistema maneja devolución completa. Para parciales necesitarías crear una nueva asignación con la cantidad devuelta o extender el sistema para manejar cantidades parciales.

---

## Resumen

✅ **El backend controla todo el inventario automáticamente**  
✅ **El frontend solo muestra y permite acciones**  
✅ **Siempre usa `available_quantity` para saber cuánto se puede asignar**  
✅ **Los errores del backend son claros y específicos**  
✅ **Las transiciones de estado están controladas y son seguras**

**Regla de oro:** Si el backend dice que no hay stock, es porque no hay. No intentes forzarlo desde el frontend.
