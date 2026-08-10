<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

class Offer extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'apply_once_per_cart' => 'boolean',
            'show_on_section' => 'boolean',
            'start_at' => 'datetime',
            'end_at' => 'datetime',
            'amount' => 'float',
        ];
    }

    public function products(): BelongsToMany
    {
        return $this->belongsToMany(Product::class, 'offer_product');
    }

    public function categories(): BelongsToMany
    {
        return $this->belongsToMany(Category::class, 'offer_category');
    }

    public function scopeRunning($query)
    {
        return $query->where('status', Status::ENABLE)
            ->where('start_at', '<=', now())
            ->where('end_at', '>=', now());
    }

    public function isRunning(): bool
    {
        return $this->status
            && $this->start_at
            && $this->end_at
            && now()->between($this->start_at, $this->end_at);
    }

    public function isFixed(): bool
    {
        return (int) $this->discount_type === Status::OFFER_FIXED;
    }

    public function discountFor(float $price): float
    {
        return $this->isFixed()
            ? min((float) $this->amount, $price)
            : ($price * (float) $this->amount) / 100;
    }

    public function applyTo(float $price): float
    {
        return max(0, $price - $this->discountFor($price));
    }
}
