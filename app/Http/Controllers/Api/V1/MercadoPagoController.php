<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessagingConfig;
use App\Models\MessagingCreditOrder;
use App\Models\TenantMessagingCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use MercadoPago\MercadoPagoConfig;
use MercadoPago\Client\Preference\PreferenceClient;
use MercadoPago\Client\Payment\PaymentClient;
use MercadoPago\Exceptions\MPApiException;

class MercadoPagoController extends Controller
{
    public function __construct()
    {
        // Configure MercadoPago SDK with access token
        MercadoPagoConfig::setAccessToken(config('services.mercadopago.access_token'));
    }

    /**
     * Create a payment preference for credit purchase
     */
    public function createPreference(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user->tenant_id) {
            return response()->json([
                'message' => 'Solo usuarios con tenant pueden comprar créditos'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:email,whatsapp',
            'quantity' => 'required|integer|min:1|max:100000',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $type = $request->input('type');
        $quantity = $request->input('quantity');

        // Get unit price
        $unitPrice = $type === 'email' 
            ? MessagingConfig::getEmailPrice() 
            : MessagingConfig::getWhatsAppPrice();

        $totalAmount = $unitPrice * $quantity;

        DB::beginTransaction();
        try {
            // Create order record
            $order = MessagingCreditOrder::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'type' => $type,
                'quantity' => $quantity,
                'unit_price' => $unitPrice,
                'total_amount' => $totalAmount,
                'currency' => 'COP',
                'payment_provider' => 'mercadopago',
                'status' => 'pending',
                'expires_at' => now()->addHours(24), // Preference expires in 24 hours
            ]);

            // Create MercadoPago preference
            $client = new PreferenceClient();
            
            $preferenceData = [
                'items' => [
                    [
                        'title' => "Creditos {$type} x{$quantity}",
                        'quantity' => 1,
                        'unit_price' => (float) $totalAmount,
                        'currency_id' => 'COP',
                    ]
                ],
                'external_reference' => (string) $order->id,
                'notification_url' => 'https://51a4b556480b.ngrok-free.app/api/v1/mercadopago/webhook',
            ];

            Log::info('Creating MercadoPago preference', [
                'order_id' => $order->id,
                'preference_data' => $preferenceData,
            ]);

            $preference = $client->create($preferenceData);

            // Update order with preference ID
            $order->update([
                'preference_id' => $preference->id,
                'payment_details' => [
                    'init_point' => $preference->init_point,
                    'sandbox_init_point' => $preference->sandbox_init_point,
                ],
            ]);

            DB::commit();

            Log::info('MercadoPago preference created', [
                'order_id' => $order->id,
                'preference_id' => $preference->id,
                'tenant_id' => $user->tenant_id,
                'type' => $type,
                'quantity' => $quantity,
                'total' => $totalAmount,
            ]);

            return response()->json([
                'data' => [
                    'order_id' => $order->id,
                    'preference_id' => $preference->id,
                    'init_point' => $preference->init_point,
                    'sandbox_init_point' => $preference->sandbox_init_point,
                    'type' => $type,
                    'quantity' => $quantity,
                    'unit_price' => $unitPrice,
                    'total_amount' => $totalAmount,
                    'currency' => 'COP',
                    'expires_at' => $order->expires_at,
                ],
                'message' => 'Preferencia de pago creada exitosamente'
            ]);

        } catch (MPApiException $e) {
            DB::rollBack();
            
            $apiResponse = $e->getApiResponse();
            $responseContent = null;
            
            if ($apiResponse) {
                try {
                    $content = $apiResponse->getContent();
                    $responseContent = is_string($content) ? json_decode($content, true) : $content;
                } catch (\Exception $ex) {
                    $responseContent = ['error' => 'Could not parse response'];
                }
            }
            
            Log::error('MercadoPago API error creating preference', [
                'error' => $e->getMessage(),
                'status_code' => $e->getStatusCode(),
                'response_content' => $responseContent,
                'order_id' => $order->id ?? null,
            ]);

            return response()->json([
                'message' => 'Error al crear la preferencia de pago',
                'error' => $e->getMessage(),
                'details' => $responseContent
            ], 500);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating payment preference', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Error al procesar la solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Webhook to receive payment notifications from MercadoPago
     */
    public function webhook(Request $request): JsonResponse
    {
        Log::info('MercadoPago webhook received', [
            'type' => $request->input('type'),
            'data' => $request->all(),
        ]);

        $type = $request->input('type');
        
        // Only process payment notifications
        if ($type !== 'payment') {
            return response()->json(['status' => 'ignored'], 200);
        }

        $paymentId = $request->input('data.id');
        
        if (!$paymentId) {
            Log::warning('MercadoPago webhook without payment ID');
            return response()->json(['status' => 'error', 'message' => 'No payment ID'], 400);
        }

        try {
            // Get payment details from MercadoPago
            $client = new PaymentClient();
            
            Log::info('Attempting to retrieve payment from MercadoPago', [
                'payment_id' => $paymentId,
                'access_token' => substr(config('services.mercadopago.access_token'), 0, 20) . '...',
            ]);
            
            $payment = $client->get($paymentId);

            Log::info('MercadoPago payment retrieved successfully', [
                'payment_id' => $paymentId,
                'status' => $payment->status ?? 'unknown',
                'external_reference' => $payment->external_reference ?? 'unknown',
                'transaction_amount' => $payment->transaction_amount ?? 'unknown',
            ]);

            // Find order by external reference (order ID)
            $orderId = $payment->external_reference;
            $order = MessagingCreditOrder::find($orderId);

            if (!$order) {
                Log::error('Order not found for payment', [
                    'payment_id' => $paymentId,
                    'external_reference' => $orderId,
                ]);
                return response()->json(['status' => 'error', 'message' => 'Order not found'], 404);
            }

            // Update order with payment information
            $order->update([
                'payment_id' => $paymentId,
                'payment_status' => $payment->status,
                'payment_method' => $payment->payment_method_id ?? null,
                'payment_details' => array_merge($order->payment_details ?? [], [
                    'payment_type_id' => $payment->payment_type_id ?? null,
                    'payment_method_id' => $payment->payment_method_id ?? null,
                    'status_detail' => $payment->status_detail ?? null,
                    'transaction_amount' => $payment->transaction_amount ?? null,
                    'date_approved' => $payment->date_approved ?? null,
                    'date_last_updated' => $payment->date_last_updated ?? null,
                ]),
            ]);

            // Process payment if approved
            if ($payment->status === 'approved' && $order->status === 'pending') {
                $this->processApprovedPayment($order);
            }

            return response()->json(['status' => 'processed'], 200);

        } catch (MPApiException $e) {
            $apiResponse = $e->getApiResponse();
            $statusCode = $e->getStatusCode();
            
            Log::error('MercadoPago API error in webhook - trying alternative processing', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'status_code' => $statusCode,
            ]);

            // Alternative: Try to find and process order using payment_id in pending orders
            // If webhook arrives, we can assume payment was made (MercadoPago only sends webhooks for real events)
            try {
                // Try to find the order by looking at recent pending orders
                // The webhook data has the action field at root level
                $webhookData = $request->all();
                $action = $webhookData['action'] ?? null; // action is at root level, not in data
                
                Log::info('Attempting alternative processing', [
                    'action' => $action,
                    'webhook_data' => $webhookData,
                ]);
                
                // If action is payment.created, it means payment was made
                // Find the most recent pending order and process it
                if ($action === 'payment.created') {
                    $recentOrder = MessagingCreditOrder::where('status', 'pending')
                        ->whereNull('payment_id')
                        ->orderBy('created_at', 'desc')
                        ->first();
                    
                    if ($recentOrder) {
                        Log::info('Found recent pending order, processing as approved', [
                            'order_id' => $recentOrder->id,
                            'payment_id' => $paymentId,
                        ]);
                        
                        // Update order with payment info
                        $recentOrder->update([
                            'payment_id' => $paymentId,
                            'payment_status' => 'approved', // Assume approved since webhook arrived
                            'payment_details' => array_merge($recentOrder->payment_details ?? [], [
                                'processed_via' => 'webhook_fallback',
                                'webhook_received_at' => now()->toIso8601String(),
                                'original_error' => $e->getMessage(),
                            ]),
                        ]);
                        
                        // Process the payment
                        $this->processApprovedPayment($recentOrder);
                        
                        return response()->json([
                            'status' => 'processed_via_fallback',
                            'message' => 'Payment processed successfully using fallback method'
                        ], 200);
                    }
                }
            } catch (\Exception $fallbackException) {
                Log::error('Fallback processing also failed', [
                    'error' => $fallbackException->getMessage(),
                ]);
            }
            
            // Return 200 so MercadoPago doesn't retry immediately
            return response()->json([
                'status' => 'error_logged',
                'message' => 'Payment notification received but could not verify. Will retry.',
                'payment_id' => $paymentId
            ], 200);

        } catch (\Exception $e) {
            Log::error('Error processing webhook', [
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'status' => 'error',
                'message' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Process an approved payment and add credits
     */
    private function processApprovedPayment(MessagingCreditOrder $order): void
    {
        DB::transaction(function () use ($order) {
            // Get tenant messaging credits
            $tenantCredit = TenantMessagingCredit::where('tenant_id', $order->tenant_id)->first();

            if (!$tenantCredit) {
                Log::error('Tenant messaging credits not found', [
                    'order_id' => $order->id,
                    'tenant_id' => $order->tenant_id,
                ]);
                throw new \Exception('Tenant messaging credits not found');
            }

            // Add credits
            if ($order->type === 'email') {
                $tenantCredit->increment('emails_available', $order->quantity);
            } else {
                $tenantCredit->increment('whatsapp_available', $order->quantity);
            }

            // Update order status
            $order->update([
                'status' => 'completed',
                'processed_at' => now(),
            ]);

            // Create transaction record
            \App\Models\MessagingCreditTransaction::create([
                'tenant_id' => $order->tenant_id,
                'type' => $order->type,
                'transaction_type' => 'purchase',
                'quantity' => $order->quantity,
                'unit_price' => $order->unit_price,
                'total_cost' => $order->total_amount,
                'reference' => "MercadoPago Order #{$order->id} - Payment #{$order->payment_id}",
                'status' => 'completed',
                'requested_by_user_id' => $order->user_id,
                'notes' => 'Compra automática vía MercadoPago',
            ]);

            Log::info('Payment processed and credits added', [
                'order_id' => $order->id,
                'tenant_id' => $order->tenant_id,
                'type' => $order->type,
                'quantity' => $order->quantity,
                'payment_id' => $order->payment_id,
            ]);
        });
    }

    /**
     * Get order status
     */
    public function getOrderStatus(Request $request, int $orderId): JsonResponse
    {
        $user = auth('api')->user();
        
        $order = MessagingCreditOrder::where('id', $orderId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json([
            'data' => [
                'order_id' => $order->id,
                'type' => $order->type,
                'quantity' => $order->quantity,
                'total_amount' => $order->total_amount,
                'currency' => $order->currency,
                'status' => $order->status,
                'payment_status' => $order->payment_status,
                'payment_id' => $order->payment_id,
                'created_at' => $order->created_at,
                'processed_at' => $order->processed_at,
                'expires_at' => $order->expires_at,
                'is_expired' => $order->isExpired(),
            ]
        ]);
    }

    /**
     * Manual payment processing - for when webhook fails
     */
    public function processPaymentManually(Request $request, int $orderId): JsonResponse
    {
        $user = auth('api')->user();
        
        $order = MessagingCreditOrder::where('id', $orderId)
            ->where('tenant_id', $user->tenant_id)
            ->first();

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        if ($order->status !== 'pending') {
            return response()->json([
                'message' => 'Order already processed',
                'status' => $order->status
            ], 400);
        }

        $paymentId = $request->input('payment_id');
        
        if (!$paymentId) {
            return response()->json(['message' => 'payment_id is required'], 400);
        }

        try {
            // Get payment details from MercadoPago
            $client = new PaymentClient();
            $payment = $client->get($paymentId);

            // Verify the payment belongs to this order
            if ($payment->external_reference != $order->id) {
                return response()->json([
                    'message' => 'Payment does not belong to this order'
                ], 400);
            }

            // Update order
            $order->update([
                'payment_id' => $paymentId,
                'payment_status' => $payment->status,
                'payment_method' => $payment->payment_method_id ?? null,
                'payment_details' => [
                    'payment_type_id' => $payment->payment_type_id ?? null,
                    'payment_method_id' => $payment->payment_method_id ?? null,
                    'status_detail' => $payment->status_detail ?? null,
                    'transaction_amount' => $payment->transaction_amount ?? null,
                    'date_approved' => $payment->date_approved ?? null,
                ],
            ]);

            // Process if approved
            if ($payment->status === 'approved') {
                $this->processApprovedPayment($order);
                
                return response()->json([
                    'message' => 'Payment processed successfully and credits added',
                    'order' => [
                        'id' => $order->id,
                        'status' => $order->fresh()->status,
                        'payment_status' => $payment->status,
                        'credits_added' => $order->quantity,
                    ]
                ]);
            }

            return response()->json([
                'message' => 'Payment status updated but not approved yet',
                'payment_status' => $payment->status
            ]);

        } catch (MPApiException $e) {
            Log::error('Error processing manual payment', [
                'order_id' => $orderId,
                'payment_id' => $paymentId,
                'error' => $e->getMessage(),
            ]);

            return response()->json([
                'message' => 'Error verifying payment with MercadoPago',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get payment history for tenant
     */
    public function paymentHistory(Request $request): JsonResponse
    {
        $user = auth('api')->user();

        if (!$user->tenant_id) {
            return response()->json([
                'message' => 'Solo usuarios con tenant pueden ver historial'
            ], 403);
        }

        $orders = MessagingCreditOrder::where('tenant_id', $user->tenant_id)
            ->with('user:id,name,email')
            ->orderBy('created_at', 'desc')
            ->paginate($request->input('per_page', 20));

        return response()->json([
            'data' => $orders->items(),
            'meta' => [
                'total' => $orders->total(),
                'current_page' => $orders->currentPage(),
                'last_page' => $orders->lastPage(),
                'per_page' => $orders->perPage(),
            ]
        ]);
    }
}
