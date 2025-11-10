<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use OwenIt\Auditing\Contracts\Auditable;

class TenantMessagingCredit extends Model implements Auditable
{
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'emails_available',
        'emails_used',
        'whatsapp_available',
        'whatsapp_used',
        'total_email_cost',
        'total_whatsapp_cost',
    ];

    protected $casts = [
        'emails_available' => 'integer',
        'emails_used' => 'integer',
        'whatsapp_available' => 'integer',
        'whatsapp_used' => 'integer',
        'total_email_cost' => 'decimal:2',
        'total_whatsapp_cost' => 'decimal:2',
    ];

    /**
     * Relationships
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(MessagingCreditTransaction::class, 'tenant_id', 'tenant_id');
    }

    /**
     * Check if tenant has email credits
     */
    public function hasEmailCredits(int $quantity = 1): bool
    {
        return $this->emails_available >= $quantity;
    }

    /**
     * Check if tenant has WhatsApp credits
     */
    public function hasWhatsAppCredits(int $quantity = 1): bool
    {
        return $this->whatsapp_available >= $quantity;
    }

    /**
     * Consume email credit
     */
    public function consumeEmail(int $quantity = 1, ?string $reference = null): bool
    {
        return DB::transaction(function () use ($quantity, $reference) {
            // Check availability
            if (!$this->hasEmailCredits($quantity)) {
                Log::warning('Insufficient email credits', [
                    'tenant_id' => $this->tenant_id,
                    'available' => $this->emails_available,
                    'requested' => $quantity,
                ]);
                return false;
            }

            $price = MessagingConfig::getEmailPrice();
            $totalCost = $price * $quantity;

            // Update credits
            $this->increment('emails_used', $quantity);
            $this->decrement('emails_available', $quantity);
            $this->increment('total_email_cost', $totalCost);

            // Record transaction
            MessagingCreditTransaction::create([
                'tenant_id' => $this->tenant_id,
                'type' => 'email',
                'transaction_type' => 'consumption',
                'quantity' => -$quantity,
                'unit_price' => $price,
                'total_cost' => $totalCost,
                'reference' => $reference,
                'status' => 'completed',
            ]);

            return true;
        });
    }

    /**
     * Consume WhatsApp credit
     */
    public function consumeWhatsApp(int $quantity = 1, ?string $reference = null): bool
    {
        return DB::transaction(function () use ($quantity, $reference) {
            // Check availability
            if (!$this->hasWhatsAppCredits($quantity)) {
                Log::warning('Insufficient WhatsApp credits', [
                    'tenant_id' => $this->tenant_id,
                    'available' => $this->whatsapp_available,
                    'requested' => $quantity,
                ]);
                return false;
            }

            $price = MessagingConfig::getWhatsAppPrice();
            $totalCost = $price * $quantity;

            // Update credits
            $this->increment('whatsapp_used', $quantity);
            $this->decrement('whatsapp_available', $quantity);
            $this->increment('total_whatsapp_cost', $totalCost);

            // Record transaction
            MessagingCreditTransaction::create([
                'tenant_id' => $this->tenant_id,
                'type' => 'whatsapp',
                'transaction_type' => 'consumption',
                'quantity' => -$quantity,
                'unit_price' => $price,
                'total_cost' => $totalCost,
                'reference' => $reference,
                'status' => 'completed',
            ]);

            return true;
        });
    }

    /**
     * Add email credits
     */
    public function addEmailCredits(
        int $quantity,
        int $approvedByUserId,
        ?string $reference = null,
        ?string $notes = null
    ): bool {
        return DB::transaction(function () use ($quantity, $approvedByUserId, $reference, $notes) {
            $price = MessagingConfig::getEmailPrice();
            $totalCost = $price * $quantity;

            // Update credits
            $this->increment('emails_available', $quantity);

            // Record transaction
            MessagingCreditTransaction::create([
                'tenant_id' => $this->tenant_id,
                'type' => 'email',
                'transaction_type' => 'purchase',
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_cost' => $totalCost,
                'reference' => $reference,
                'notes' => $notes,
                'status' => 'completed',
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Add WhatsApp credits
     */
    public function addWhatsAppCredits(
        int $quantity,
        int $approvedByUserId,
        ?string $reference = null,
        ?string $notes = null
    ): bool {
        return DB::transaction(function () use ($quantity, $approvedByUserId, $reference, $notes) {
            $price = MessagingConfig::getWhatsAppPrice();
            $totalCost = $price * $quantity;

            // Update credits
            $this->increment('whatsapp_available', $quantity);

            // Record transaction
            MessagingCreditTransaction::create([
                'tenant_id' => $this->tenant_id,
                'type' => 'whatsapp',
                'transaction_type' => 'purchase',
                'quantity' => $quantity,
                'unit_price' => $price,
                'total_cost' => $totalCost,
                'reference' => $reference,
                'notes' => $notes,
                'status' => 'completed',
                'approved_by_user_id' => $approvedByUserId,
                'approved_at' => now(),
            ]);

            return true;
        });
    }

    /**
     * Get credit summary
     */
    public function getSummary(): array
    {
        return [
            'emails' => [
                'available' => $this->emails_available,
                'used' => $this->emails_used,
                'total_cost' => $this->total_email_cost,
                'unit_price' => MessagingConfig::getEmailPrice(),
            ],
            'whatsapp' => [
                'available' => $this->whatsapp_available,
                'used' => $this->whatsapp_used,
                'total_cost' => $this->total_whatsapp_cost,
                'unit_price' => MessagingConfig::getWhatsAppPrice(),
            ],
            'total_cost' => $this->total_email_cost + $this->total_whatsapp_cost,
        ];
    }
}
