<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\MessagingConfig;
use App\Models\MessagingCreditTransaction;
use App\Models\TenantMessagingCredit;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class TenantMessagingController extends Controller
{
    /**
     * Get current tenant's messaging credits summary
     */
    public function index(): JsonResponse
    {
        $user = auth('api')->user();
        
        // Superadmin can see any tenant, others only their own
        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Superadmin debe especificar tenant_id como parámetro'
            ], 400);
        }

        $credit = TenantMessagingCredit::where('tenant_id', $user->tenant_id)->first();

        if (!$credit) {
            return response()->json([
                'message' => 'No messaging credits found for tenant'
            ], 404);
        }

        return response()->json([
            'data' => [
                'tenant_id' => $credit->tenant_id,
                'summary' => $credit->getSummary(),
                'last_updated' => $credit->updated_at,
            ]
        ]);
    }

    /**
     * Get messaging credit history/transactions
     */
    public function transactions(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        
        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Superadmin debe especificar tenant_id como parámetro'
            ], 400);
        }

        $query = MessagingCreditTransaction::where('tenant_id', $user->tenant_id)
            ->with(['requestedBy:id,name,email', 'approvedBy:id,name,email'])
            ->orderBy('created_at', 'desc');

        // Filter by type (email or whatsapp)
        if ($request->has('type')) {
            $query->where('type', $request->type);
        }

        // Filter by transaction_type (purchase, consumption, etc)
        if ($request->has('transaction_type')) {
            $query->where('transaction_type', $request->transaction_type);
        }

        $transactions = $query->paginate($request->get('per_page', 20));

        return response()->json([
            'data' => $transactions->items(),
            'meta' => [
                'total' => $transactions->total(),
                'current_page' => $transactions->currentPage(),
                'last_page' => $transactions->lastPage(),
                'per_page' => $transactions->perPage(),
            ]
        ]);
    }

    /**
     * Request credit recharge (creates pending transaction for superadmin approval)
     */
    public function requestRecharge(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        
        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Superadmin no necesita solicitar créditos'
            ], 400);
        }

        $validator = Validator::make($request->all(), [
            'type' => 'required|in:email,whatsapp',
            'quantity' => 'required|integer|min:1',
            'notes' => 'nullable|string|max:500',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $type = $request->type;
        $quantity = $request->quantity;
        $price = $type === 'email' 
            ? MessagingConfig::getEmailPrice() 
            : MessagingConfig::getWhatsAppPrice();
        $totalCost = $price * $quantity;

        $transaction = MessagingCreditTransaction::create([
            'tenant_id' => $user->tenant_id,
            'type' => $type,
            'transaction_type' => 'purchase',
            'quantity' => $quantity,
            'unit_price' => $price,
            'total_cost' => $totalCost,
            'notes' => $request->notes,
            'status' => 'pending',
            'requested_by_user_id' => $user->id,
        ]);

        Log::info('Credit recharge requested', [
            'transaction_id' => $transaction->id,
            'tenant_id' => $user->tenant_id,
            'type' => $type,
            'quantity' => $quantity,
            'total_cost' => $totalCost,
        ]);

        return response()->json([
            'data' => $transaction,
            'message' => 'Solicitud de recarga creada exitosamente.',
            'alert' => [
                'type' => 'info', // Para SweetAlert2
                'title' => 'Solicitud Creada',
                'message' => 'Tu solicitud ha sido registrada. Un administrador la revisará en las próximas 24-72 horas.',
            ],
            'next_steps' => [
                'step_1' => [
                    'title' => '📝 Número de Solicitud',
                    'content' => "Solicitud #{$transaction->id}",
                    'instruction' => 'Guarda este número como referencia',
                ],
                'step_2' => [
                    'title' => '💳 Realizar Pago',
                    'content' => "Total a pagar: $" . number_format($totalCost, 2, ',', '.') . " COP",
                    'instruction' => 'Contacta al administrador para obtener instrucciones de pago',
                ],
                'step_3' => [
                    'title' => '⏳ Esperar Aprobación',
                    'content' => 'El administrador verificará tu pago',
                    'instruction' => 'Recibirás una notificación cuando los créditos sean agregados',
                ],
            ],
            'payment_info' => [
                'amount' => $totalCost,
                'currency' => 'COP',
                'reference' => "SOLICITUD-{$transaction->id}",
                'note' => 'Usa esta referencia al realizar el pago',
            ],
        ], 201);
    }

    /**
     * Get pricing information
     */
    public function pricing(): JsonResponse
    {
        return response()->json([
            'data' => [
                'email_price' => MessagingConfig::getEmailPrice(),
                'whatsapp_price' => MessagingConfig::getWhatsAppPrice(),
                'currency' => 'COP',
            ]
        ]);
    }

    /**
     * Get purchase options (for frontend to show 2 buttons)
     * Returns information about both manual request and MercadoPago payment
     */
    public function purchaseOptions(Request $request): JsonResponse
    {
        $user = auth('api')->user();
        
        if ($user->tenant_id === null) {
            return response()->json([
                'message' => 'Superadmin no necesita comprar créditos'
            ], 400);
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

        $type = $request->type;
        $quantity = $request->quantity;
        $price = $type === 'email' 
            ? MessagingConfig::getEmailPrice() 
            : MessagingConfig::getWhatsAppPrice();
        $totalCost = $price * $quantity;

        return response()->json([
            'data' => [
                'type' => $type,
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_amount' => $totalCost,
                'currency' => 'COP',
                'options' => [
                    'manual_request' => [
                        'available' => true,
                        'endpoint' => '/api/v1/messaging/request-recharge',
                        'method' => 'POST',
                        'description' => 'Solicitud manual de recarga',
                        'warning' => [
                            'title' => '⚠️ Tiempo de Espera',
                            'message' => 'Esta solicitud requiere aprobación del administrador. El tiempo de procesamiento puede variar entre 24-72 horas dependiendo de la verificación del pago.',
                            'type' => 'warning', // Para SweetAlert2
                        ],
                        'steps' => [
                            '1. Se crea una solicitud de recarga',
                            '2. Debes realizar el pago por transferencia bancaria o método acordado',
                            '3. El administrador verificará el pago',
                            '4. Los créditos serán agregados tras la aprobación',
                        ],
                        'payment_info' => [
                            'message' => 'Después de crear la solicitud, recibirás instrucciones de pago.',
                            'note' => 'Guarda el número de solicitud como referencia del pago.',
                        ],
                    ],
                    'mercadopago' => [
                        'available' => true,
                        'endpoint' => '/api/v1/mercadopago/create-preference',
                        'method' => 'POST',
                        'description' => 'Pago inmediato con MercadoPago',
                        'benefits' => [
                            '✅ Procesamiento instantáneo',
                            '✅ Créditos agregados automáticamente',
                            '✅ Sin aprobación manual requerida',
                            '✅ Pago seguro con tarjeta de crédito/débito',
                        ],
                        'payment_methods' => [
                            'credit_card' => true,
                            'debit_card' => true,
                            'pse' => false, // Puedes activar PSE si MercadoPago lo soporta
                        ],
                        'estimated_time' => 'Inmediato (menos de 5 minutos)',
                    ],
                ],
                'recommendation' => [
                    'title' => '💡 Recomendación',
                    'message' => 'Para recibir tus créditos inmediatamente, te recomendamos usar MercadoPago. Si prefieres pagar por transferencia, usa la solicitud manual.',
                ],
            ],
        ]);
    }
}
