<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Deposit extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'detail' => 'object',
            'amount' => 'float',
            'charge' => 'float',
            'rate' => 'float',
            'final_amount' => 'float',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'method_code', 'code');
    }

    public function gatewayCurrency(): BelongsTo
    {
        return $this->belongsTo(GatewayCurrency::class, 'method_code', 'method_code');
    }

    public function scopeSuccessful($query)
    {
        return $query->where('status', Status::PAYMENT_SUCCESS);
    }

    public function scopePending($query)
    {
        return $query->where('status', Status::PAYMENT_PENDING);
    }

    public function scopeRejected($query)
    {
        return $query->where('status', Status::PAYMENT_REJECT);
    }

    public function scopeInitiated($query)
    {
        return $query->where('status', Status::PAYMENT_INITIATE);
    }
}
