<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class StockTransfer extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'dispatched_at' => 'datetime',
            'received_at' => 'datetime',
        ];
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'to_branch_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(StockTransferItem::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'approved_by');
    }

    public function receiver(): BelongsTo
    {
        return $this->belongsTo(Admin::class, 'received_by');
    }

    public function getStatusLabelAttribute(): string
    {
        return Status::TRANSFER_STATUS_LABELS[$this->status] ?? 'Unknown';
    }
}
