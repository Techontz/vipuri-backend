<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class BranchInventory extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'last_counted_at' => 'datetime',
            'cost_price' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function variation(): BelongsTo
    {
        return $this->belongsTo(ProductVariation::class, 'variation_id');
    }

    /** Stock that can still be sold from this branch. */
    public function getAvailableQuantityAttribute(): int
    {
        return max(0, (int) $this->stock_quantity - (int) $this->reserved_quantity);
    }

    public function isLow(): bool
    {
        return $this->min_stock_quantity > 0
            && $this->stock_quantity <= $this->min_stock_quantity;
    }

    public function scopeLowStock($query)
    {
        return $query->whereColumn('stock_quantity', '<=', 'min_stock_quantity')
            ->where('min_stock_quantity', '>', 0);
    }

    public function scopeOutOfStock($query)
    {
        return $query->where('stock_quantity', '<=', 0);
    }
}
