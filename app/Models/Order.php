<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Order extends Model
{
    /** Placed through the storefront / apps. */
    public const CHANNEL_ONLINE = 'online';

    /** Sold to a walk-in customer at a branch counter. */
    public const CHANNEL_POS = 'pos';

    /** How a counter sale was settled. */
    public const PAYMENT_METHODS = [
        'cash' => 'Cash',
        'mobile_money' => 'Mobile money',
        'card' => 'Card',
        'bank_transfer' => 'Bank transfer',
    ];

    protected $guarded = ['id'];

    protected $attributes = [
        'channel' => self::CHANNEL_ONLINE,
    ];

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
            'amount_received' => 'float',
            'change_due' => 'float',
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

    /** Staff member who made a counter sale. */
    public function soldBy(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'sold_by');
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

    public function scopePos($query)
    {
        return $query->where('channel', self::CHANNEL_POS);
    }

    public function scopeOnline($query)
    {
        return $query->where('channel', self::CHANNEL_ONLINE);
    }

    public function isPos(): bool
    {
        return $this->channel === self::CHANNEL_POS;
    }

    /** Human label for how the order was paid, when that is known. */
    public function getPaymentMethodLabelAttribute(): ?string
    {
        if ($this->payment_method) {
            return self::PAYMENT_METHODS[$this->payment_method] ?? ucfirst(str_replace('_', ' ', $this->payment_method));
        }

        return $this->cod ? 'Cash on delivery' : null;
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
