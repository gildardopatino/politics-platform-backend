# MercadoPago Integration - Self-Service Credit Purchase

## Descripción General

Sistema completo de compra de créditos de mensajería (email/WhatsApp) mediante MercadoPago. Los tenants pueden comprar créditos directamente sin requerir aprobación del superadmin.

## Flujo de Compra

```
1. Tenant solicita compra (frontend)
   ↓
2. Backend crea preferencia de pago en MercadoPago
   ↓
3. Backend devuelve URL de checkout (init_point)
   ↓
4. Frontend redirige al usuario a MercadoPago
   ↓
5. Usuario completa el pago
   ↓
6. MercadoPago notifica al backend via webhook
   ↓
7. Backend verifica el pago y agrega créditos automáticamente
   ↓
8. Usuario retorna al frontend (success/failure/pending)
```

## Configuración

### 1. Variables de Entorno (.env)

```env
MERCADOPAGO_PUBLIC_KEY=APP_USR-5a2d07cd-16c7-432a-b53d-066f39b6a6ad
MERCADOPAGO_ACCESS_TOKEN=APP_USR-3329361615050445-110923-e386fd583e2735dd9c96a7feb5ac4e3f-1903334445
```

### 2. Config Service

Archivo: `config/services.php`

```php
'mercadopago' => [
    'public_key' => env('MERCADOPAGO_PUBLIC_KEY'),
    'access_token' => env('MERCADOPAGO_ACCESS_TOKEN'),
],
```

## Endpoints API

### 1. Crear Preferencia de Pago

**POST** `/api/v1/mercadopago/create-preference`

**Headers:**
```
Authorization: Bearer {jwt_token}
Content-Type: application/json
```

**Body:**
```json
{
  "type": "whatsapp",
  "quantity": 1000
}
```

**Validaciones:**
- `type`: required, in:email,whatsapp
- `quantity`: required, integer, min:1, max:100000
- Usuario debe ser tenant (no superadmin)

**Response Success (200):**
```json
{
  "success": true,
  "message": "Payment preference created successfully",
  "data": {
    "order_id": 123,
    "preference_id": "1903334445-abc123def456",
    "init_point": "https://www.mercadopago.com.co/checkout/v1/redirect?pref_id=1903334445-abc123def456",
    "sandbox_init_point": "https://sandbox.mercadopago.com.co/checkout/v1/redirect?pref_id=1903334445-abc123def456",
    "total_amount": "100000.00",
    "expires_at": "2025-11-10T22:57:52.000000Z"
  }
}
```

**Response Error (403):**
```json
{
  "success": false,
  "message": "Only tenant users can purchase credits"
}
```

**Uso en Frontend:**
```javascript
// 1. Llamar endpoint para crear preferencia
const response = await axios.post('/api/v1/mercadopago/create-preference', {
  type: 'whatsapp',
  quantity: 1000
});

// 2. Redirigir al checkout de MercadoPago
window.location.href = response.data.data.init_point;
// Para testing: usar sandbox_init_point

// 3. Usuario completa el pago en MercadoPago
// 4. MercadoPago redirige a back_urls configuradas
```

### 2. Webhook (Notificaciones Asíncronas)

**POST** `/api/v1/mercadopago/webhook`

**Nota:** Este endpoint es público (no requiere autenticación) y es llamado directamente por MercadoPago.

**Body (ejemplo de notificación de pago):**
```json
{
  "action": "payment.created",
  "api_version": "v1",
  "data": {
    "id": "123456789"
  },
  "date_created": "2025-11-09T22:30:00Z",
  "id": 987654321,
  "live_mode": false,
  "type": "payment",
  "user_id": "1903334445"
}
```

**Procesamiento:**
1. Filtra solo notificaciones tipo "payment"
2. Obtiene detalles del pago desde MercadoPago API
3. Busca la orden por `external_reference` (order_id)
4. Actualiza orden con información del pago
5. Si `status = "approved"`: agrega créditos automáticamente
6. Crea transacción en `messaging_credit_transactions`

**Response:**
```json
{
  "success": true,
  "message": "Webhook processed successfully"
}
```

**Configuración en MercadoPago:**
La URL del webhook se configura automáticamente al crear la preferencia:
```
https://tu-dominio.com/api/v1/mercadopago/webhook
```

**Importante:** Para testing local, necesitas exponer tu servidor con ngrok o similar:
```bash
ngrok http 8000
# Usar la URL de ngrok en lugar de localhost
```

### 3. Consultar Estado de Orden

**GET** `/api/v1/mercadopago/orders/{orderId}/status`

**Headers:**
```
Authorization: Bearer {jwt_token}
```

**Response Success (200):**
```json
{
  "success": true,
  "data": {
    "order_id": 123,
    "tenant_id": 5,
    "user_id": 42,
    "type": "whatsapp",
    "quantity": 1000,
    "unit_price": "100.00",
    "total_amount": "100000.00",
    "status": "completed",
    "payment_status": "approved",
    "payment_id": "123456789",
    "preference_id": "1903334445-abc123def456",
    "processed_at": "2025-11-09T22:45:00.000000Z",
    "expires_at": "2025-11-10T22:57:52.000000Z",
    "is_expired": false,
    "created_at": "2025-11-09T22:30:00.000000Z"
  }
}
```

**Uso en Frontend:**
```javascript
// Polling después de que el usuario retorne de MercadoPago
const orderId = localStorage.getItem('pending_order_id');

const checkStatus = async () => {
  const response = await axios.get(`/api/v1/mercadopago/orders/${orderId}/status`);
  
  if (response.data.data.status === 'completed') {
    // Pago aprobado, créditos agregados
    showSuccessMessage('Credits added successfully!');
    refreshCreditsBalance();
  } else if (response.data.data.status === 'pending') {
    // Aún procesando
    setTimeout(checkStatus, 3000); // Reintentar en 3 segundos
  } else {
    // Pago fallido o cancelado
    showErrorMessage('Payment failed');
  }
};
```

### 4. Historial de Pagos

**GET** `/api/v1/mercadopago/payment-history`

**Headers:**
```
Authorization: Bearer {jwt_token}
```

**Query Parameters:**
- `page`: Número de página (default: 1)
- `per_page`: Registros por página (default: 15)

**Response Success (200):**
```json
{
  "success": true,
  "data": {
    "current_page": 1,
    "data": [
      {
        "id": 123,
        "tenant_id": 5,
        "user_id": 42,
        "type": "whatsapp",
        "quantity": 1000,
        "unit_price": "100.00",
        "total_amount": "100000.00",
        "status": "completed",
        "payment_status": "approved",
        "payment_method": "credit_card",
        "payment_id": "123456789",
        "processed_at": "2025-11-09T22:45:00.000000Z",
        "created_at": "2025-11-09T22:30:00.000000Z",
        "user": {
          "id": 42,
          "name": "Juan Pérez",
          "email": "juan@example.com"
        }
      }
    ],
    "first_page_url": "http://localhost:8000/api/v1/mercadopago/payment-history?page=1",
    "last_page": 3,
    "per_page": 15,
    "total": 45
  }
}
```

## Base de Datos

### Tabla: messaging_credit_orders

```sql
CREATE TABLE messaging_credit_orders (
    id BIGSERIAL PRIMARY KEY,
    tenant_id BIGINT NOT NULL,
    user_id BIGINT NOT NULL,
    type VARCHAR(255) NOT NULL, -- 'email' o 'whatsapp'
    quantity INTEGER NOT NULL,
    unit_price DECIMAL(10, 2) NOT NULL,
    total_amount DECIMAL(10, 2) NOT NULL,
    currency VARCHAR(3) DEFAULT 'COP',
    payment_method VARCHAR(255) NULL,
    payment_provider VARCHAR(255) DEFAULT 'mercadopago',
    payment_id VARCHAR(255) NULL,
    preference_id VARCHAR(255) NULL,
    status VARCHAR(50) DEFAULT 'pending', -- pending, completed, failed, cancelled
    payment_status VARCHAR(50) NULL,
    payment_details JSONB NULL,
    processed_at TIMESTAMP NULL,
    expires_at TIMESTAMP NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (tenant_id) REFERENCES tenants(id) ON DELETE CASCADE,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

CREATE INDEX idx_messaging_credit_orders_tenant_id ON messaging_credit_orders(tenant_id);
CREATE INDEX idx_messaging_credit_orders_status ON messaging_credit_orders(status);
CREATE INDEX idx_messaging_credit_orders_payment_id ON messaging_credit_orders(payment_id);
```

## Testing

### 1. Testing con Postman

**Paso 1: Login como Tenant**
```
POST http://localhost:8000/api/v1/login
Body:
{
  "email": "tenant@example.com",
  "password": "password"
}
```
Guardar el token JWT de la respuesta.

**Paso 2: Crear Preferencia**
```
POST http://localhost:8000/api/v1/mercadopago/create-preference
Headers:
  Authorization: Bearer {token}
Body:
{
  "type": "whatsapp",
  "quantity": 100
}
```
Guardar el `init_point` de la respuesta.

**Paso 3: Simular Pago**
Abrir el `sandbox_init_point` en el navegador y completar el pago con datos de prueba de MercadoPago:
- Tarjeta: 5031 7557 3453 0604 (MASTERCARD)
- CVV: 123
- Fecha: 11/25
- Titular: APRO (para aprobación automática)

**Paso 4: Verificar Webhook**
El webhook debe ser llamado automáticamente por MercadoPago. Revisar logs:
```bash
tail -f storage/logs/laravel.log | grep MercadoPago
```

**Paso 5: Verificar Estado**
```
GET http://localhost:8000/api/v1/mercadopago/orders/{orderId}/status
Headers:
  Authorization: Bearer {token}
```

**Paso 6: Verificar Créditos**
```
GET http://localhost:8000/api/v1/messaging/credits
Headers:
  Authorization: Bearer {token}
```

### 2. Tarjetas de Prueba MercadoPago

**Aprobación Inmediata:**
- **Mastercard:** 5031 7557 3453 0604 - Titular: APRO
- **Visa:** 4509 9535 6623 3704 - Titular: APRO

**Rechazo por Fondos Insuficientes:**
- **Mastercard:** 5031 7557 3453 0604 - Titular: FUND

**Pendiente:**
- **Mastercard:** 5031 7557 3453 0604 - Titular: PEND

**Datos Comunes:**
- CVV: Cualquier 3 dígitos
- Fecha: Cualquier fecha futura
- DNI: Cualquier número

## Seguridad

### 1. Validaciones Implementadas

- ✅ Usuario debe ser tenant (no superadmin)
- ✅ Tipo de crédito válido (email/whatsapp)
- ✅ Cantidad en rango permitido (1-100000)
- ✅ Verificación de pago antes de agregar créditos
- ✅ Orden debe estar en estado 'pending' para procesarse
- ✅ Pago debe estar 'approved' para agregar créditos
- ✅ Transacción de base de datos para atomicidad

### 2. Protección contra Fraude

- Órdenes expiran en 24 horas
- Solo se procesan pagos aprobados
- Webhook verifica estado de pago con API de MercadoPago
- Log completo de todas las operaciones
- Auditoría automática de órdenes y transacciones

### 3. Manejo de Errores

```php
// Errores capturados:
- MPApiException: Errores de API de MercadoPago
- Validation errors: Datos inválidos
- Order not found: Orden inexistente o no pertenece al tenant
- Payment not approved: Pago rechazado o pendiente
- Order already processed: Orden ya completada previamente
```

## Monitoreo y Debugging

### Logs Importantes

```bash
# Ver logs de MercadoPago
tail -f storage/logs/laravel.log | grep -E "MercadoPago|Payment"

# Ver órdenes pendientes
php artisan tinker
>>> MessagingCreditOrder::pending()->get();

# Ver órdenes de un tenant específico
>>> Tenant::find(5)->messagingOrders()->latest()->get();

# Ver transacciones de compra
>>> MessagingCreditTransaction::where('transaction_type', 'purchase')->latest()->get();
```

### Comandos Útiles

```bash
# Limpiar órdenes expiradas (agregar a cron)
php artisan schedule:run

# Verificar configuración
php artisan config:show services.mercadopago

# Ver rutas
php artisan route:list --path=mercadopago
```

## Integración Frontend

### Ejemplo React

```jsx
import { useState } from 'react';
import axios from 'axios';

function BuyCreditsButton() {
  const [loading, setLoading] = useState(false);
  
  const handlePurchase = async () => {
    setLoading(true);
    
    try {
      // 1. Crear preferencia
      const response = await axios.post('/api/v1/mercadopago/create-preference', {
        type: 'whatsapp',
        quantity: 1000
      });
      
      const { order_id, sandbox_init_point } = response.data.data;
      
      // 2. Guardar order_id para consultar después
      localStorage.setItem('pending_order_id', order_id);
      
      // 3. Redirigir a MercadoPago
      window.location.href = sandbox_init_point;
      
    } catch (error) {
      console.error('Error creating payment:', error);
      alert('Error al crear pago');
      setLoading(false);
    }
  };
  
  return (
    <button onClick={handlePurchase} disabled={loading}>
      {loading ? 'Procesando...' : 'Comprar 1000 WhatsApp (COP $100,000)'}
    </button>
  );
}

// Página de retorno después del pago
function PaymentReturnPage() {
  const [status, setStatus] = useState('checking');
  
  useEffect(() => {
    const orderId = localStorage.getItem('pending_order_id');
    
    if (!orderId) {
      setStatus('error');
      return;
    }
    
    const checkPaymentStatus = async () => {
      try {
        const response = await axios.get(`/api/v1/mercadopago/orders/${orderId}/status`);
        const order = response.data.data;
        
        if (order.status === 'completed') {
          setStatus('success');
          localStorage.removeItem('pending_order_id');
          // Actualizar balance de créditos en la UI
          refreshCredits();
        } else if (order.status === 'pending') {
          // Reintentar en 3 segundos
          setTimeout(checkPaymentStatus, 3000);
        } else {
          setStatus('failed');
        }
      } catch (error) {
        setStatus('error');
      }
    };
    
    checkPaymentStatus();
  }, []);
  
  return (
    <div>
      {status === 'checking' && <p>Verificando pago...</p>}
      {status === 'success' && <p>¡Pago exitoso! Créditos agregados.</p>}
      {status === 'failed' && <p>Pago rechazado. Intenta nuevamente.</p>}
      {status === 'error' && <p>Error al verificar pago.</p>}
    </div>
  );
}
```

## Próximos Pasos

### Funcionalidades Futuras

1. **Paquetes de Créditos:**
   - Starter: 500 emails + 250 WhatsApp
   - Standard: 2000 emails + 1000 WhatsApp (5% descuento)
   - Premium: 10000 emails + 5000 WhatsApp (10% descuento)

2. **Pagos en Cuotas:**
   - Integrar sistema de cuotas de MercadoPago
   - Permitir 3, 6, 12 cuotas

3. **Reembolsos:**
   - Endpoint para procesar reembolsos
   - Deducir créditos cuando se emite reembolso

4. **Notificaciones por Email:**
   - Email cuando pago es aprobado
   - Email si pago falla
   - Resumen semanal de compras

5. **Dashboard de Pagos:**
   - Gráficos de compras por mes
   - Métodos de pago más usados
   - Análisis de conversión

## Soporte

Para problemas o consultas:
- Revisar logs: `storage/logs/laravel.log`
- Documentación MercadoPago: https://www.mercadopago.com.co/developers
- Testing credentials: https://www.mercadopago.com.co/developers/es/docs/checkout-pro/additional-content/test-cards
