<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Per-visitor checkout state (applied coupon, chosen shipping rate, chosen
 * branch) — the stateless-API replacement for the source system's session keys.
 */
class CartContext extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['coupon_discount' => 'float'];
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function shippingRate(): BelongsTo
    {
        return $this->belongsTo(ShippingRate::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }
}
