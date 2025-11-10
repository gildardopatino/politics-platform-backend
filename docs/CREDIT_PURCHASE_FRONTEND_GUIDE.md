# Guía de Implementación Frontend - Compra de Créditos

## 📋 Resumen

El sistema ahora soporta **2 métodos de compra de créditos**:

1. **Solicitud Manual** → Requiere aprobación del administrador (24-72 hrs)
2. **MercadoPago** → Pago instantáneo con tarjeta (automático)

---

## 🎨 UI Recomendada

El frontend debe mostrar **2 botones** en la pantalla de compra de créditos:

```
┌─────────────────────────────────────────────────┐
│  Comprar Créditos de WhatsApp / Email          │
├─────────────────────────────────────────────────┤
│                                                 │
│  Cantidad: [____] créditos                     │
│  Precio unitario: $50 COP                      │
│  Total: $5,000 COP                             │
│                                                 │
│  ┌──────────────────┐  ┌──────────────────┐   │
│  │  Solicitar       │  │  Pagar con       │   │
│  │  Recarga Manual  │  │  MercadoPago     │   │
│  │  ⏳ 24-72 hrs    │  │  ⚡ Instantáneo  │   │
│  └──────────────────┘  └──────────────────┘   │
│                                                 │
└─────────────────────────────────────────────────┘
```

---

## 🔄 Flujo de Implementación

### Paso 1: Obtener Opciones de Compra

Cuando el usuario ingresa la cantidad de créditos que quiere comprar:

**Endpoint:** `POST /api/v1/messaging/purchase-options`

**Request:**
```json
{
  "type": "whatsapp",
  "quantity": 100
}
```

**Response:**
```json
{
  "data": {
    "type": "whatsapp",
    "quantity": 100,
    "unit_price": 50,
    "total_amount": 5000,
    "currency": "COP",
    "options": {
      "manual_request": {
        "available": true,
        "endpoint": "/api/v1/messaging/request-recharge",
        "method": "POST",
        "description": "Solicitud manual de recarga",
        "warning": {
          "title": "⚠️ Tiempo de Espera",
          "message": "Esta solicitud requiere aprobación del administrador...",
          "type": "warning"
        },
        "steps": [
          "1. Se crea una solicitud de recarga",
          "2. Debes realizar el pago por transferencia bancaria...",
          "3. El administrador verificará el pago",
          "4. Los créditos serán agregados tras la aprobación"
        ]
      },
      "mercadopago": {
        "available": true,
        "endpoint": "/api/v1/mercadopago/create-preference",
        "method": "POST",
        "description": "Pago inmediato con MercadoPago",
        "benefits": [
          "✅ Procesamiento instantáneo",
          "✅ Créditos agregados automáticamente",
          "✅ Sin aprobación manual requerida",
          "✅ Pago seguro con tarjeta"
        ],
        "estimated_time": "Inmediato (menos de 5 minutos)"
      }
    },
    "recommendation": {
      "title": "💡 Recomendación",
      "message": "Para recibir tus créditos inmediatamente, te recomendamos usar MercadoPago..."
    }
  }
}
```

---

### Paso 2A: Si el usuario elige "Solicitud Manual"

**Antes de hacer el POST, mostrar SweetAlert2:**

```javascript
Swal.fire({
  title: '⚠️ Tiempo de Espera',
  html: `
    <p>Esta solicitud requiere aprobación del administrador.</p>
    <p>El tiempo de procesamiento puede variar entre <strong>24-72 horas</strong>.</p>
    <br>
    <h4>Pasos a seguir:</h4>
    <ol style="text-align: left;">
      <li>Se crea una solicitud de recarga</li>
      <li>Debes realizar el pago por transferencia bancaria</li>
      <li>El administrador verificará el pago</li>
      <li>Los créditos serán agregados tras la aprobación</li>
    </ol>
  `,
  icon: 'warning',
  showCancelButton: true,
  confirmButtonText: 'Continuar',
  cancelButtonText: 'Cancelar',
  confirmButtonColor: '#3085d6',
  cancelButtonColor: '#d33',
}).then((result) => {
  if (result.isConfirmed) {
    // Usuario confirmó, hacer el POST
    createManualRequest();
  }
});
```

**Endpoint:** `POST /api/v1/messaging/request-recharge`

**Request:**
```json
{
  "type": "whatsapp",
  "quantity": 100,
  "notes": "Pago realizado por transferencia bancaria"
}
```

**Response:**
```json
{
  "data": {
    "id": 45,
    "tenant_id": 1,
    "type": "whatsapp",
    "transaction_type": "purchase",
    "quantity": 100,
    "unit_price": 50,
    "total_cost": 5000,
    "status": "pending",
    "created_at": "2025-11-10T10:00:00Z"
  },
  "message": "Solicitud de recarga creada exitosamente.",
  "alert": {
    "type": "info",
    "title": "Solicitud Creada",
    "message": "Tu solicitud ha sido registrada. Un administrador la revisará en las próximas 24-72 horas."
  },
  "next_steps": {
    "step_1": {
      "title": "📝 Número de Solicitud",
      "content": "Solicitud #45",
      "instruction": "Guarda este número como referencia"
    },
    "step_2": {
      "title": "💳 Realizar Pago",
      "content": "Total a pagar: $5.000,00 COP",
      "instruction": "Contacta al administrador para obtener instrucciones de pago"
    },
    "step_3": {
      "title": "⏳ Esperar Aprobación",
      "content": "El administrador verificará tu pago",
      "instruction": "Recibirás una notificación cuando los créditos sean agregados"
    }
  },
  "payment_info": {
    "amount": 5000,
    "currency": "COP",
    "reference": "SOLICITUD-45",
    "note": "Usa esta referencia al realizar el pago"
  }
}
```

**Mostrar segundo SweetAlert con los pasos:**

```javascript
Swal.fire({
  title: '✅ Solicitud Creada',
  html: `
    <div style="text-align: left;">
      <h3>📝 Número de Solicitud</h3>
      <p><strong>Solicitud #${response.data.id}</strong></p>
      <p><em>Guarda este número como referencia</em></p>
      
      <h3>💳 Realizar Pago</h3>
      <p><strong>Total a pagar: $${response.payment_info.amount.toLocaleString('es-CO')} COP</strong></p>
      <p><em>Referencia: ${response.payment_info.reference}</em></p>
      <p>Contacta al administrador para obtener instrucciones de pago</p>
      
      <h3>⏳ Esperar Aprobación</h3>
      <p>El administrador verificará tu pago</p>
      <p><em>Recibirás una notificación cuando los créditos sean agregados</em></p>
    </div>
  `,
  icon: 'info',
  confirmButtonText: 'Entendido',
  confirmButtonColor: '#3085d6',
});
```

---

### Paso 2B: Si el usuario elige "Pagar con MercadoPago"

**Endpoint:** `POST /api/v1/mercadopago/create-preference`

**Request:**
```json
{
  "type": "whatsapp",
  "quantity": 100
}
```

**Response:**
```json
{
  "data": {
    "order_id": 12,
    "preference_id": "2977406120-...",
    "init_point": "https://www.mercadopago.com.co/checkout/v1/redirect?pref_id=...",
    "sandbox_init_point": "https://sandbox.mercadopago.com.co/checkout/v1/redirect?pref_id=...",
    "total_amount": 5000,
    "currency": "COP",
    "expires_at": "2025-11-10T15:00:00Z"
  },
  "message": "Orden creada exitosamente. Redirige al usuario a MercadoPago."
}
```

**Implementación:**

```javascript
// Producción
window.location.href = response.data.init_point;

// O en sandbox/desarrollo
window.location.href = response.data.sandbox_init_point;
```

**Página de Éxito (Success Page):**

Después que el usuario paga, MercadoPago lo redirige de vuelta a tu sitio.
En la página de éxito, hacer polling para verificar el estado de la orden:

```javascript
// Polling cada 3 segundos hasta que status sea 'completed'
const checkOrderStatus = async (orderId) => {
  const response = await fetch(`/api/v1/mercadopago/orders/${orderId}/status`);
  const data = await response.json();
  
  if (data.data.status === 'completed') {
    // Mostrar éxito y créditos agregados
    Swal.fire({
      title: '✅ Pago Exitoso',
      html: `
        <p>Tu pago ha sido procesado exitosamente.</p>
        <p><strong>${data.data.quantity} créditos de ${data.data.type}</strong> han sido agregados a tu cuenta.</p>
      `,
      icon: 'success',
      confirmButtonText: 'Ver mis créditos',
    }).then(() => {
      // Redirigir a la página de créditos
      window.location.href = '/credits';
    });
  } else if (data.data.status === 'failed') {
    // Mostrar error
    Swal.fire({
      title: '❌ Pago Fallido',
      text: 'Hubo un problema al procesar tu pago. Por favor, intenta nuevamente.',
      icon: 'error',
    });
  } else {
    // Seguir esperando (status = 'pending')
    setTimeout(() => checkOrderStatus(orderId), 3000);
  }
};
```

---

## 📊 Endpoints Disponibles

### 1. Obtener opciones de compra
```
POST /api/v1/messaging/purchase-options
Body: { "type": "whatsapp", "quantity": 100 }
```

### 2. Crear solicitud manual
```
POST /api/v1/messaging/request-recharge
Body: { "type": "whatsapp", "quantity": 100, "notes": "..." }
```

### 3. Crear orden de MercadoPago
```
POST /api/v1/mercadopago/create-preference
Body: { "type": "whatsapp", "quantity": 100 }
```

### 4. Verificar estado de orden
```
GET /api/v1/mercadopago/orders/{orderId}/status
```

### 5. Ver historial de pagos
```
GET /api/v1/mercadopago/payment-history?per_page=20
```

### 6. Ver créditos actuales
```
GET /api/v1/messaging/credits
```

### 7. Ver transacciones
```
GET /api/v1/messaging/transactions?per_page=20
```

---

## 🎯 Recomendaciones de UX

1. **Botón recomendado:** Destacar el botón de MercadoPago con color verde/azul brillante
2. **Warning clara:** El botón de solicitud manual debe tener un tooltip o badge indicando "24-72 hrs"
3. **Tooltips:** Agregar tooltips explicando cada método
4. **Historial:** Mostrar en la UI principal las solicitudes/órdenes pendientes
5. **Notificaciones:** Implementar notificaciones cuando los créditos sean agregados

---

## 🧪 Testing

### Tarjetas de Prueba MercadoPago (Sandbox)

```
Mastercard Aprobado:
Número: 5031 7557 3453 0604
CVV: 123
Vencimiento: 11/25
Titular: APRO
```

### Flujo de Testing

1. Crear orden con 100 créditos de WhatsApp
2. Pagar con tarjeta de prueba
3. Verificar que los créditos se agreguen automáticamente
4. Verificar que se cree `MessagingCreditTransaction` con tipo `purchase`

---

## 📝 Notas Adicionales

- **Solicitudes manuales** crean `MessagingCreditTransaction` con `status='pending'`
- **MercadoPago** crea `MessagingCreditOrder` con `status='pending'` → `completed`
- Ambos métodos agregan créditos a `tenant_messaging_credits`
- Las transacciones se registran en `messaging_credit_transactions` para ambos métodos
- El superadmin puede ver todas las solicitudes pendientes en `/superadmin/messaging/pending-requests`

---

## 🔐 Autenticación

Todos los endpoints requieren autenticación JWT:

```javascript
headers: {
  'Authorization': `Bearer ${token}`,
  'Content-Type': 'application/json',
}
```

---

## 📞 Soporte

Si tienes dudas sobre la implementación, revisa:
- `docs/MERCADOPAGO_INTEGRATION.md` - Documentación de MercadoPago
- `docs/MESSAGING_CREDITS_API.md` - API de créditos de mensajería
