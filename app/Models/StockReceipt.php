<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A goods received note: one supplier delivery booked into one branch. */
class StockReceipt extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'received_at' => 'datetime',
            'total_cost' => 'float',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockReceiptItem::class);
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }

    /**
     * GRN-{branch prefix}-{zero-padded id}, e.g. GRN-DOM-000123.
     *
     * The prefix is the branch code up to its first dash ("DOM-01" → "DOM").
     * The number is the row id, so it is unique across the company even when
     * two branches share a prefix (DSM-01, DSM-02).
     */
    public static function makeReference(int $id, ?string $branchCode): string
    {
        $prefix = strtoupper((string) preg_replace('/[^A-Za-z0-9]/', '', explode('-', (string) $branchCode)[0]));

        return sprintf('GRN-%s-%06d', $prefix !== '' ? $prefix : 'BR', $id);
    }
}
