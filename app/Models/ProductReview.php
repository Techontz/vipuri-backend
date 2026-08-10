<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

class ProductReview extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['images' => 'array', 'is_viewed' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function productReviewReply(): HasOne
    {
        return $this->hasOne(ProductReviewReply::class);
    }

    public function scopeApproved($query)
    {
        return $query->where('status', Status::REVIEW_APPROVED);
    }

    public function scopePending($query)
    {
        return $query->where('status', Status::REVIEW_PENDING);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', Status::REVIEW_REJECTED);
    }
}
