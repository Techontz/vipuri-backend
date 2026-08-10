<?php

namespace App\Models;

use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Gateway extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'crypto' => 'boolean',
            'gateway_parameters' => 'object',
            'supported_currencies' => 'object',
            'extra' => 'object',
        ];
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(GatewayCurrency::class, 'method_code', 'code');
    }

    /** Manual gateways have no automated driver; they need admin approval. */
    public function isManual(): bool
    {
        return $this->code >= 1000;
    }

    public function isAutomatic(): bool
    {
        return ! $this->isManual();
    }
}
