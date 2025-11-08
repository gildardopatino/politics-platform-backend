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
        'unit_cost',
        'subtotal',
        'notes',
        'metadata',
        'status',
        'delivered_at',
        'returned_at',
        'delivered_by_user_id',
        'returned_to_user_id',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'subtotal' => 'decimal:2',
        'metadata' => 'array',
        'delivered_at' => 'datetime',
        'returned_at' => 'datetime',
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
