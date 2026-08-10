<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

/**
 * Storefront customer.
 */
class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $guarded = ['id'];

    protected $hidden = ['password', 'remember_token', 'ver_code'];

    protected $appends = ['fullname'];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'status' => 'boolean',
            'ev' => 'boolean',
            'sv' => 'boolean',
            'profile_complete' => 'boolean',
            'ver_code_send_at' => 'datetime',
        ];
    }

    public function getFullnameAttribute(): string
    {
        return trim(($this->firstname ?? '') . ' ' . ($this->lastname ?? ''));
    }

    public function preferredBranch(): BelongsTo
    {
        return $this->belongsTo(Branch::class, 'preferred_branch_id');
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(Address::class);
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(ProductReview::class);
    }

    public function wishlists(): HasMany
    {
        return $this->hasMany(Wishlist::class);
    }

    public function carts(): HasMany
    {
        return $this->hasMany(Cart::class);
    }

    public function deposits(): HasMany
    {
        return $this->hasMany(Deposit::class);
    }

    public function notifications(): HasMany
    {
        return $this->hasMany(UserNotification::class);
    }

    public function supportTickets(): HasMany
    {
        return $this->hasMany(SupportTicket::class);
    }

    public function logins(): HasMany
    {
        return $this->hasMany(UserLogin::class)->where('guard', 'user');
    }

    public function scopeActive($query)
    {
        return $query->where('status', Status::USER_ACTIVE);
    }

    public function scopeBanned($query)
    {
        return $query->where('status', Status::USER_BAN);
    }

    public function scopeEmailVerified($query)
    {
        return $query->where('ev', Status::VERIFIED);
    }

    public function scopeEmailUnverified($query)
    {
        return $query->where('ev', Status::UNVERIFIED);
    }

    public function scopeMobileVerified($query)
    {
        return $query->where('sv', Status::VERIFIED);
    }

    public function scopeMobileUnverified($query)
    {
        return $query->where('sv', Status::UNVERIFIED);
    }
}
