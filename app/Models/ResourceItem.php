<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use OwenIt\Auditing\Contracts\Auditable;

class ResourceItem extends Model implements Auditable
{
    use HasFactory, HasTenant, SoftDeletes;
    use \OwenIt\Auditing\Auditable;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'unit',
        'unit_cost',
        'currency',
        'stock_quantity',
        'reserved_quantity',
        'min_stock',
        'supplier',
        'supplier_contact',
        'metadata',
        'is_active',
        'is_inventory_tracked',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'stock_quantity' => 'integer',
        'reserved_quantity' => 'integer',
        'min_stock' => 'integer',
        'metadata' => 'array',
        'is_active' => 'boolean',
        'is_inventory_tracked' => 'boolean',
    ];

    // Relationships
    public function tenant()
    {
        return $this->belongsTo(Tenant::class);
    }

    public function allocationItems()
    {
        return $this->hasMany(ResourceAllocationItem::class);
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeByCategory($query, $category)
    {
        return $query->where('category', $category);
    }

    public function scopeLowStock($query)
    {
        return $query->whereNotNull('stock_quantity')
                     ->whereNotNull('min_stock')
                     ->whereColumn('stock_quantity', '<=', 'min_stock');
    }

    // Accessors
    public function getIsLowStockAttribute(): bool
    {
        if (is_null($this->stock_quantity) || is_null($this->min_stock)) {
            return false;
        }
        return $this->stock_quantity <= $this->min_stock;
    }

    public function getFormattedCostAttribute(): string
    {
        return number_format($this->unit_cost, 2) . ' ' . $this->currency;
    }

    public function getAvailableQuantityAttribute(): int
    {
        if (!$this->is_inventory_tracked) {
            return PHP_INT_MAX; // Siempre disponible si no se controla inventario
        }
        return max(0, $this->stock_quantity - $this->reserved_quantity);
    }

    // Methods for inventory management
    public function hasAvailableStock(int $quantity): bool
    {
        if (!$this->is_inventory_tracked) {
            return true; // Siempre disponible si no se controla inventario
        }
        return $this->getAvailableQuantityAttribute() >= $quantity;
    }

    public function reserveStock(int $quantity): bool
    {
        if (!$this->is_inventory_tracked) {
            return true; // No hace nada si no se controla inventario
        }
        
        if (!$this->hasAvailableStock($quantity)) {
            return false;
        }

        $this->increment('reserved_quantity', $quantity);
        return true;
    }

    public function releaseReservedStock(int $quantity): void
    {
        if (!$this->is_inventory_tracked) {
            return; // No hace nada si no se controla inventario
        }
        
        $this->decrement('reserved_quantity', min($quantity, $this->reserved_quantity));
    }

    public function decreaseStock(int $quantity): bool
    {
        if (!$this->is_inventory_tracked) {
            return true; // No hace nada si no se controla inventario
        }
        
        if ($this->stock_quantity < $quantity) {
            return false;
        }

        $this->decrement('stock_quantity', $quantity);
        return true;
    }

    public function increaseStock(int $quantity): void
    {
        if (!$this->is_inventory_tracked) {
            return; // No hace nada si no se controla inventario
        }
        
        $this->increment('stock_quantity', $quantity);
    }
}
