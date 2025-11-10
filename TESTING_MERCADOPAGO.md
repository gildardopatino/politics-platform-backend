# Testing MercadoPago Integration Locally

## Problema Actual

Cuando pruebas pagos localmente, MercadoPago no puede llamar a tu webhook porque `http://localhost:8000` no es accesible desde internet.

## Solución: Usar ngrok

### 1. Instalar ngrok

Descarga desde: https://ngrok.com/download

O con Chocolatey:
```bash
choco install ngrok
```

### 2. Exponer tu servidor local

```bash
ngrok http 8000
```

Esto te dará una URL pública como: `https://abc123.ngrok.io`

### 3. Actualizar la URL del webhook temporalmente

Opción A - Editar el controlador temporalmente:

En `MercadoPagoController.php` línea ~90, cambia:
```php
'notification_url' => config('app.url') . '/api/v1/mercadopago/webhook',
```

Por:
```php
'notification_url' => 'https://TU-URL-NGROK.ngrok.io/api/v1/mercadopago/webhook',
```

Opción B - Variable de entorno:

Agrega a `.env`:
```env
WEBHOOK_URL=https://TU-URL-NGROK.ngrok.io
```

Y en el controlador usa:
```php
'notification_url' => env('WEBHOOK_URL', config('app.url')) . '/api/v1/mercadopago/webhook',
```

## Flujo de Testing Completo

### Paso 1: Iniciar ngrok
```bash
ngrok http 8000
```
**Copia la URL HTTPS** que te muestra (ejemplo: `https://abc123.ngrok.io`)

### Paso 2: Actualizar webhook URL
Edita temporalmente el código como se explicó arriba.

### Paso 3: Login como tenant
```bash
POST http://localhost:8000/api/v1/login
{
  "email": "tu-tenant@example.com",
  "password": "password"
}
```

### Paso 4: Crear preferencia de pago
```bash
POST http://localhost:8000/api/v1/mercadopago/create-preference
Headers:
  Authorization: Bearer {tu-token-jwt}
Body:
{
  "type": "whatsapp",
  "quantity": 100
}
```

**IMPORTANTE:** Guarda el `order_id` de la respuesta.

### Paso 5: Abrir checkout de MercadoPago

Copia el `sandbox_init_point` de la respuesta y ábrelo en tu navegador.

**NO uses `init_point`** - ese es para producción.

### Paso 6: Completar pago con tarjeta de prueba

Usa estos datos:
- **Tarjeta:** 5031 7557 3453 0604 (Mastercard)
- **CVV:** 123
- **Fecha:** 11/25
- **Titular:** APRO (para aprobación automática)
- **DNI:** 12345678

### Paso 7: Monitorear webhook

En otra terminal:
```bash
tail -f storage/logs/laravel.log | grep MercadoPago
```

Deberías ver logs de:
1. Webhook recibido
2. Pago procesado
3. Créditos agregados

### Paso 8: Verificar orden actualizada

```bash
GET http://localhost:8000/api/v1/mercadopago/orders/{order_id}/status
Headers:
  Authorization: Bearer {tu-token}
```

La respuesta debe mostrar `status: "completed"` y `payment_status: "approved"`

### Paso 9: Verificar créditos agregados

```bash
GET http://localhost:8000/api/v1/messaging/credits
Headers:
  Authorization: Bearer {tu-token}
```

Deberías ver los créditos aumentados en `whatsapp_available` o `emails_available`.

## Testing sin ngrok (Solo para desarrollo)

Si no quieres usar ngrok, puedes simular el webhook manualmente:

### 1. Completar el pago en MercadoPago

Usa `sandbox_init_point` y completa el pago normalmente.

### 2. Obtener el payment_id

MercadoPago te redirigirá a la URL de success con parámetros:
```
http://localhost:3000/payments/success?collection_id=123456789&...
```

El `collection_id` es el `payment_id`.

### 3. Simular webhook manualmente

```bash
POST http://localhost:8000/api/v1/mercadopago/webhook
Content-Type: application/json

{
  "action": "payment.created",
  "api_version": "v1",
  "data": {
    "id": "123456789"
  },
  "type": "payment",
  "user_id": "1903334445"
}
```

**Reemplaza `"id": "123456789"`** con el `payment_id` real.

Esto hará que el backend:
1. Consulte el pago en MercadoPago API
2. Actualice la orden
3. Agregue los créditos si el pago fue aprobado

## Verificación Final

```bash
# Ver todas las órdenes
php artisan tinker
>>> MessagingCreditOrder::with('user')->latest()->get();

# Ver créditos del tenant
>>> TenantMessagingCredit::where('tenant_id', 1)->first();

# Ver transacciones de compra
>>> MessagingCreditTransaction::where('transaction_type', 'purchase')->latest()->get();
```

## Tarjetas de Prueba MercadoPago

### Aprobación Inmediata
- **Mastercard:** 5031 7557 3453 0604 - Titular: APRO
- **Visa:** 4509 9535 6623 3704 - Titular: APRO
- **American Express:** 3711 803032 57522 - Titular: APRO

### Rechazo
- Titular: **OTHE** (rechazado por otros motivos)
- Titular: **FUND** (fondos insuficientes)
- Titular: **SECU** (código de seguridad inválido)
- Titular: **EXPI** (fecha de expiración inválida)
- Titular: **FORM** (error en formulario)
- Titular: **CALL** (autorización pendiente)

### Pendiente
- Titular: **PEND** (pago pendiente)

**Datos comunes para todas:**
- CVV: Cualquier 3 dígitos (123)
- Fecha: Cualquier fecha futura (11/25)
- DNI: Cualquier número (12345678)
- Email: test@test.com

## Troubleshooting

### El pago se queda cargando
**Causa:** Estás usando credenciales de test en el sitio de producción.
**Solución:** Usa `sandbox_init_point` en lugar de `init_point`.

### El webhook nunca se ejecuta
**Causa:** localhost no es accesible desde internet.
**Solución:** Usa ngrok o simula el webhook manualmente.

### Error "payment not found" en webhook
**Causa:** El payment_id no existe o estás usando credenciales incorrectas.
**Solución:** Verifica que uses las mismas credenciales (test) tanto al crear la preferencia como al consultar el pago.

### Los créditos no se agregan
**Causa:** El pago no tiene status "approved" o la orden ya fue procesada.
**Solución:** 
1. Verifica el status del pago en los logs
2. Verifica que la orden esté en status "pending"
3. Revisa `storage/logs/laravel.log` para errores

### "Order already processed"
**Causa:** Intentaste procesar la misma orden dos veces.
**Solución:** Normal, el sistema previene duplicación. Los créditos ya fueron agregados la primera vez.

## URLs de Referencia

- MercadoPago Docs: https://www.mercadopago.com.co/developers
- Test Cards: https://www.mercadopago.com.co/developers/es/docs/checkout-pro/additional-content/test-cards
- Webhooks: https://www.mercadopago.com.co/developers/es/docs/your-integrations/notifications/webhooks
- ngrok: https://ngrok.com
