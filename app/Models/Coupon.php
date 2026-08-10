<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Coupon extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'exclude_sale_items' => 'boolean',
            'exclude_offers' => 'boolean',
            'expiry_date' => 'date',
            'amount' => 'float',
            'max_discount' => 'float',
            'minimum_spend' => 'float',
            'maximum_spend' => 'float',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'coupon_product');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'coupon_category');
    }

    public function usages(): HasMany
    {
        return $this->hasMany(CouponUsage::class);
    }

    public function isExpired(): bool
    {
        return $this->expiry_date && $this->expiry_date->endOfDay()->isPast();
    }

    public function isPercent(): bool
    {
        return (int) $this->discount_type === Status::COUPON_DISCOUNT_PERCENT;
    }

    public function isFixedCart(): bool
    {
        return (int) $this->discount_type === Status::COUPON_DISCOUNT_FIXED_CART;
    }

    public function isFixedProduct(): bool
    {
        return (int) $this->discount_type === Status::COUPON_DISCOUNT_FIXED_PRODUCT;
    }
}
