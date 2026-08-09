<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ResourceAllocationItem extends Model
{
    use HasFactory;

    protected $fillable = [
        'resource_allocation_id',
        'resource_item_id',
        'quantity',
        'quantity_returned',
        'quantity_lost',
        'quantity_damaged',
        'unit_cost',
        'subtotal',
        'notes',
        'metadata',
        'status',
        'delivered_at',
        'returned_at',
        'closed_at',
        'delivered_by_user_id',
        'returned_to_user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'quantity_returned' => 'decimal:2',
        'quantity_lost' => 'decimal:2',
        'quantity_damaged' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'metadata' => 'array',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    // Relationships
    public function resourceAllocation()
    {
        return $this->belongsTo(ResourceAllocation::class);
    }

    public function resourceItem()
    {
        return $this->belongsTo(ResourceItem::class);
    }

    public function deliveredBy()
    {
        return $this->belongsTo(User::class, 'delivered_by_user_id');
    }

    public function returnedTo()
    {
        return $this->belongsTo(User::class, 'returned_to_user_id');
    }

    /**
     * Cómo terminó el ítem, deducido del desglose (Spec 0057).
     *
     * Se deriva en vez de guardarse para que no pueda contradecir a las
     * cantidades: si volvieron 8 de 10, el ítem es `parcial` aunque alguien
     * hubiera escrito otra cosa en `status`.
     */
    public function getReturnStateAttribute(): ?string
    {
        if ($this->closed_at === null) {
            return null;
        }

        $entregado = (float) $this->quantity;
        $devuelto = (float) $this->quantity_returned;
        $perdido = (float) $this->quantity_lost;
        $danado = (float) $this->quantity_damaged;

        if ($devuelto >= $entregado) {
            return 'devuelto';
        }

        if ($perdido >= $entregado) {
            return 'perdido';
        }

        if ($danado >= $entregado) {
            return 'dañado';
        }

        return 'parcial';
    }

    /** Lo que se declaró al cerrar, sea cual sea su desenlace. */
    public function getQuantityAccountedAttribute(): float
    {
        return (float) $this->quantity_returned
            + (float) $this->quantity_lost
            + (float) $this->quantity_damaged;
    }

    // Scopes
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', 'delivered');
    }

    public function scopeReturned($query)
    {
        return $query->where('status', 'returned');
    }

    // Mutators
    protected static function boot()
    {
        parent::boot();

        // Calcular subtotal automáticamente antes de guardar
        static::saving(function ($item) {
            $item->subtotal = $item->quantity * $item->unit_cost;
        });
    }
}
