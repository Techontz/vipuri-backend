<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'shipping_address' => 'object',
            'cod' => 'boolean',
            'subtotal' => 'float',
            'shipping_charge' => 'float',
            'total_tax' => 'float',
            'discount' => 'float',
            'total' => 'float',
            'dispatched_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function processedBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'processed_by');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function guest(): BelongsTo
    {
        return $this->belongsTo(Guest::class);
    }

    public function orderItems(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function shippingMethod(): BelongsTo
    {
        return $this->belongsTo(ShippingMethod::class, 'shipping_method_id');
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function statusLogs(): HasMany
    {
        return $this->hasMany(OrderStatusLog::class)->latest('id');
    }

    public function scopePending($query)
    {
        return $query->where('status', Status::ORDER_PENDING);
    }

    public function scopeProcessing($query)
    {
        return $query->where('status', Status::ORDER_PROCESSING);
    }

    public function scopeDispatched($query)
    {
        return $query->where('status', Status::ORDER_DISPATCHED);
    }

    public function scopeDelivered($query)
    {
        return $query->where('status', Status::ORDER_DELIVERED);
    }

    public function scopeReturned($query)
    {
        return $query->where('status', Status::ORDER_RETURNED);
    }

    public function scopeCancelled($query)
    {
        return $query->where('status', Status::ORDER_CANCELLED);
    }

    public function scopeCompleted($query)
    {
        return $query->where('status', Status::ORDER_DELIVERED);
    }

    public function scopePaid($query)
    {
        return $query->where('payment_status', Status::PAYMENT_SUCCESS);
    }

    public function scopeUnpaid($query)
    {
        return $query->where('payment_status', '!=', Status::PAYMENT_SUCCESS);
    }

    public function scopeCod($query)
    {
        return $query->where('cod', Status::YES);
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::ORDER_STATUS_LABELS[$this->status] ?? 'Unknown';
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return Status::PAYMENT_STATUS_LABELS[$this->payment_status] ?? 'Unknown';
    }

    public function isFinal(): bool
    {
        return in_array($this->status, [Status::ORDER_DELIVERED, Status::ORDER_CANCELLED, Status::ORDER_RETURNED], true);
    }
}
