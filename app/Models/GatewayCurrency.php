<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GatewayCurrency extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'gateway_parameter' => 'object',
            'min_amount' => 'float',
            'max_amount' => 'float',
            'percent_charge' => 'float',
            'fixed_charge' => 'float',
            'rate' => 'float',
        ];
    }

    public function gateway(): BelongsTo
    {
        return $this->belongsTo(Gateway::class, 'method_code', 'code');
    }
}
