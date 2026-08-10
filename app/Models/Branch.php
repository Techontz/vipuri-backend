<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Branch extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'is_default' => 'boolean',
            'is_pickup_point' => 'boolean',
            'opening_hours' => 'array',
            'latitude' => 'float',
            'longitude' => 'float',
        ];
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function staff(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    public function inventories(): HasMany
    {
        return $this->hasMany(BranchInventory::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function stockLogs(): HasMany
    {
        return $this->hasMany(StockLog::class);
    }

    public function outgoingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'from_branch_id');
    }

    public function incomingTransfers(): HasMany
    {
        return $this->hasMany(StockTransfer::class, 'to_branch_id');
    }

    public function scopeDefault($query)
    {
        return $query->where('is_default', Status::YES);
    }

    /** Branch used when an order has no explicit branch (nearest fallback). */
    public static function fallback(): ?self
    {
        return static::query()->active()->orderByDesc('is_default')->orderBy('id')->first();
    }
}
