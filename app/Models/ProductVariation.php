<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ProductVariation extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'attribute_values' => 'array',
            'sale_is_scheduled' => 'boolean',
            'allow_backorder' => 'boolean',
            'display_available' => 'boolean',
            'display_stock_quantity' => 'boolean',
            'schedule_sale_start' => 'datetime',
            'schedule_sale_end' => 'datetime',
            'regular_price' => 'float',
            'sale_price' => 'float',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function media(): HasMany
    {
        return $this->hasMany(ProductMedia::class, 'variation_id');
    }

    /** The AttributeValue models this variation is composed of. */
    public function attributeValueModels()
    {
        return AttributeValue::with('attribute')
            ->whereIn('id', $this->attribute_values ?? [])
            ->get();
    }

    public function getNameAttribute(): string
    {
        return $this->attributeValueModels()->pluck('name')->implode(' / ');
    }

    public function trackInventory(): bool
    {
        return (int) $this->inventory_type === Status::TRACK_INVENTORY;
    }

    public function isOnSale(): bool
    {
        if (! $this->sale_price || $this->sale_price <= 0) {
            return false;
        }

        if (! $this->sale_is_scheduled) {
            return true;
        }

        return $this->schedule_sale_start
            && $this->schedule_sale_end
            && now()->between($this->schedule_sale_start, $this->schedule_sale_end);
    }

    public function getPriceAttribute(): float
    {
        return $this->isOnSale() ? (float) $this->sale_price : (float) $this->regular_price;
    }

    public function isBuyable(): bool
    {
        if (! $this->trackInventory() || $this->allow_backorder) {
            return true;
        }

        if ((int) $this->low_stock_activity !== Status::LOW_STOCK_NOTHING) {
            return $this->stock_quantity > $this->min_stock_quantity;
        }

        return $this->stock_quantity > 0;
    }
}
