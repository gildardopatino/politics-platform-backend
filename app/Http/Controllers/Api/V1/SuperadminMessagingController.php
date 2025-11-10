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

class SuperadminMessagingController extends Controller
{
    /**
     * Validate that the authenticated user is a superadmin
     */
    private function validateSuperadmin(): ?JsonResponse
    {
        $user = auth('api')->user();
        if ($user->tenant_id !== null) {
            return response()->json([
                'message' => 'Solo el superadministrador puede acceder a esta función'
            ], 403);
        }
        return null;
    }

    /**
     * Get all pending credit requests
     */
    public function pendingRequests(): JsonResponse
    {
        if ($error = $this->validateSuperadmin()) {
            return $error;
        }
        $requests = MessagingCreditTransaction::pending()
            ->with(['tenant', 'requestedBy:id,name,email'])
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json([
            'data' => $requests->items(),
            'meta' => [
                'total' => $requests->total(),
                'current_page' => $requests->currentPage(),
                'last_page' => $requests->lastPage(),
            ]
        ]);
    }

    /**
     * Approve a credit request
     */
    public function approveRequest(Request $request, int $transactionId): JsonResponse
    {
        if ($error = $this->validateSuperadmin()) {
            return $error;
        }

        $user = auth('api')->user();
        $transaction = MessagingCreditTransaction::find($transactionId);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->status !== 'pending') {
            return response()->json([
                'message' => 'Solo se pueden aprobar solicitudes pendientes'
            ], 400);
        }

        DB::beginTransaction();
        try {
            // Add credits to tenant
            $tenantCredit = TenantMessagingCredit::where('tenant_id', $transaction->tenant_id)->first();
            
            if (!$tenantCredit) {
                // Create tenant messaging credits if they don't exist
                $tenantCredit = TenantMessagingCredit::create([
                    'tenant_id' => $transaction->tenant_id,
                    'emails_available' => 0,
                    'whatsapp_available' => 0,
                    'emails_used' => 0,
                    'whatsapp_used' => 0,
                ]);
                
                Log::info('Tenant messaging credits created automatically', [
                    'tenant_id' => $transaction->tenant_id,
                    'created_by' => $user->id,
                ]);
            }

            // Add credits WITHOUT creating a new transaction (we already have the pending one)
            if ($transaction->type === 'email') {
                $tenantCredit->increment('emails_available', $transaction->quantity);
            } else {
                $tenantCredit->increment('whatsapp_available', $transaction->quantity);
            }

            // Update the existing transaction status
            $transaction->update([
                'status' => 'approved',
                'approved_by_user_id' => $user->id,
                'approved_at' => now(),
                'notes' => $request->input('notes', $transaction->notes), // Keep or update notes
            ]);

            DB::commit();

            Log::info('Credit request approved', [
                'transaction_id' => $transaction->id,
                'tenant_id' => $transaction->tenant_id,
                'approved_by' => $user->id,
                'type' => $transaction->type,
                'quantity' => $transaction->quantity,
            ]);

            return response()->json([
                'data' => $transaction->fresh(),
                'message' => 'Solicitud aprobada y créditos agregados'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error approving credit request', [
                'transaction_id' => $transactionId,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Error aprobando solicitud',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Reject a credit request
     */
    public function rejectRequest(Request $request, int $transactionId): JsonResponse
    {
        if ($error = $this->validateSuperadmin()) {
            return $error;
        }

        $user = auth('api')->user();
        $transaction = MessagingCreditTransaction::find($transactionId);

        if (!$transaction) {
            return response()->json(['message' => 'Transaction not found'], 404);
        }

        if ($transaction->status !== 'pending') {
            return response()->json([
                'message' => 'Solo se pueden rechazar solicitudes pendientes'
            ], 400);
        }

        $transaction->update([
            'status' => 'rejected',
            'approved_by_user_id' => $user->id,
            'approved_at' => now(),
            'notes' => $request->input('notes', $transaction->notes),
        ]);

        Log::info('Credit request rejected', [
            'transaction_id' => $transaction->id,
            'tenant_id' => $transaction->tenant_id,
            'rejected_by' => $user->id,
        ]);

        return response()->json([
            'data' => $transaction,
            'message' => 'Solicitud rechazada'
        ]);
    }

    /**
     * Manually add credits to a tenant (without request)
     */
    public function addCredits(Request $request): JsonResponse
    {
        if ($error = $this->validateSuperadmin()) {
            return $error;
        }

        $user = auth('api')->user();

        $validator = Validator::make($request->all(), [
            'tenant_id' => 'required|exists:tenants,id',
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

        $tenantCredit = TenantMessagingCredit::where('tenant_id', $request->tenant_id)->first();

        if (!$tenantCredit) {
            // Create tenant messaging credits if they don't exist
            $tenantCredit = TenantMessagingCredit::create([
                'tenant_id' => $request->tenant_id,
                'emails_available' => 0,
                'whatsapp_available' => 0,
                'emails_used' => 0,
                'whatsapp_used' => 0,
            ]);
            
            Log::info('Tenant messaging credits created automatically', [
                'tenant_id' => $request->tenant_id,
                'created_by' => $user->id,
            ]);
        }

        DB::beginTransaction();
        try {
            if ($request->type === 'email') {
                $tenantCredit->addEmailCredits(
                    $request->quantity,
                    $user->id,
                    "Manual addition by superadmin",
                    $request->notes
                );
            } else {
                $tenantCredit->addWhatsAppCredits(
                    $request->quantity,
                    $user->id,
                    "Manual addition by superadmin",
                    $request->notes
                );
            }

            DB::commit();

            Log::info('Credits added manually by superadmin', [
                'tenant_id' => $request->tenant_id,
                'type' => $request->type,
                'quantity' => $request->quantity,
                'added_by' => $user->id,
            ]);

            return response()->json([
                'data' => $tenantCredit->fresh()->getSummary(),
                'message' => 'Créditos agregados exitosamente'
            ]);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error adding credits manually', [
                'tenant_id' => $request->tenant_id,
                'error' => $e->getMessage(),
            ]);
            
            return response()->json([
                'message' => 'Error agregando créditos',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get messaging credits for all tenants
     */
    public function allTenantCredits(): JsonResponse
    {
        if ($error = $this->validateSuperadmin()) {
            return $error;
        }

        $credits = TenantMessagingCredit::with('tenant:id,nombre')
            ->get()
            ->map(function ($credit) {
                return [
                    'tenant_id' => $credit->tenant_id,
                    'tenant_name' => $credit->tenant->nombre ?? 'N/A',
                    'summary' => $credit->getSummary(),
                    'last_updated' => $credit->updated_at,
                ];
            });

        return response()->json([
            'data' => $credits
        ]);
    }

    /**
     * Get or update pricing configuration
     */
    public function pricing(Request $request): JsonResponse
    {
        if ($error = $this->validateSuperadmin()) {
            return $error;
        }

        if ($request->isMethod('get')) {
            return response()->json([
                'data' => [
                    'email_price' => MessagingConfig::getEmailPrice(),
                    'whatsapp_price' => MessagingConfig::getWhatsAppPrice(),
                    'currency' => 'COP',
                ]
            ]);
        }

        // Update pricing
        $validator = Validator::make($request->all(), [
            'email_price' => 'nullable|numeric|min:0',
            'whatsapp_price' => 'nullable|numeric|min:0',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'message' => 'Validation failed',
                'errors' => $validator->errors()
            ], 422);
        }

        $user = auth('api')->user();

        if ($request->has('email_price')) {
            MessagingConfig::setEmailPrice($request->email_price);
            Log::info('Email price updated', [
                'new_price' => $request->email_price,
                'updated_by' => $user->id,
            ]);
        }

        if ($request->has('whatsapp_price')) {
            MessagingConfig::setWhatsAppPrice($request->whatsapp_price);
            Log::info('WhatsApp price updated', [
                'new_price' => $request->whatsapp_price,
                'updated_by' => $user->id,
            ]);
        }

        return response()->json([
            'data' => [
                'email_price' => MessagingConfig::getEmailPrice(),
                'whatsapp_price' => MessagingConfig::getWhatsAppPrice(),
                'currency' => 'COP',
            ],
            'message' => 'Precios actualizados exitosamente'
        ]);
    }
}
