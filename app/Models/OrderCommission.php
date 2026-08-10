<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One staff member's commission on one completed order.
 *
 * Every figure it reports is stored: the rate, the amount it was applied to
 * and the result. Nothing is recomputed on read, so changing the company's
 * rate tomorrow cannot quietly restate what was earned last month.
 */
class OrderCommission extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'rate' => 'float',
            'basis_amount' => 'float',
            'amount' => 'float',
            'earned_at' => 'datetime',
            'approved_at' => 'datetime',
            'paid_at' => 'datetime',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(Admin::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    /** Rows that still represent money owed — a reversal is not owed. */
    public function scopeOutstanding(Builder $query): Builder
    {
        return $query->whereIn('status', [Status::COMMISSION_PENDING, Status::COMMISSION_APPROVED]);
    }

    public function scopeNotReversed(Builder $query): Builder
    {
        return $query->where('status', '!=', Status::COMMISSION_REVERSED);
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::COMMISSION_STATUS_LABELS[(int) $this->status] ?? 'Unknown';
    }
}
