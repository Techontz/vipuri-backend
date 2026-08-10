<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SupportTicket extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['last_reply' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function messages(): HasMany
    {
        return $this->hasMany(SupportMessage::class)->orderByDesc('id');
    }

    public function scopeOpen($query)
    {
        return $query->where('status', Status::TICKET_OPEN);
    }

    public function scopeAnswered($query)
    {
        return $query->where('status', Status::TICKET_ANSWER);
    }

    public function scopeReplied($query)
    {
        return $query->where('status', Status::TICKET_REPLY);
    }

    public function scopeClosed($query)
    {
        return $query->where('status', Status::TICKET_CLOSE);
    }
}
