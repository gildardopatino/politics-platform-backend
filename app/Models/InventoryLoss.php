<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use OwenIt\Auditing\Contracts\Auditable;

/**
 * Una merma: lo que salió del almacén y no volvió (Spec 0057).
 *
 * `unit_cost` y `value` se guardan calculados a propósito. Son la fotografía del
 * momento del cierre: si el catálogo sube de precio, la pérdida de la semana
 * pasada sigue valiendo lo que valía cuando ocurrió.
 */
class InventoryLoss extends Model implements Auditable
{
    use HasFactory, HasTenant;
    use \OwenIt\Auditing\Auditable;

    public const PERDIDO = 'perdido';

    public const DANADO = 'dañado';

    protected $fillable = [
        'tenant_id',
        'resource_item_id',
        'resource_allocation_item_id',
        'resource_allocation_id',
        'meeting_id',
        'responsible_user_id',
        'quantity',
        'unit_cost',
        'value',
        'reason',
        'notes',
    ];

    protected $casts = [
        'quantity' => 'decimal:2',
        'unit_cost' => 'decimal:2',
        'value' => 'decimal:2',
    ];

    public function resourceItem()
    {
        return $this->belongsTo(ResourceItem::class);
    }

    public function allocationItem()
    {
        return $this->belongsTo(ResourceAllocationItem::class, 'resource_allocation_item_id');
    }

    public function allocation()
    {
        return $this->belongsTo(ResourceAllocation::class, 'resource_allocation_id');
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }

    public function responsible()
    {
        return $this->belongsTo(User::class, 'responsible_user_id');
    }
}
