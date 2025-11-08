<?php

namespace App\Models;

use App\Traits\HasTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class ResourceItem extends Model
{
    use HasFactory, HasTenant, SoftDeletes;

    protected $fillable = [
        'tenant_id',
        'name',
        'description',
        'category',
        'unit',
        'unit_cost',
        'currency',
        'stock_quantity',
        'min_stock',
        'supplier',
        'supplier_contact',
        'metadata',
        'is_active',
    ];

    protected $casts = [
        'unit_cost' => 'decimal:2',
        'stock_quantity' => 'integer',
        'min_stock' => 'integer',
        'metadata' => 'array',
        'is_active' => 'boolean',
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
}
