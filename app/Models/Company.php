<?php

namespace App\Models;

use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Company extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['status' => 'boolean'];
    }

    public function branches(): HasMany
    {
        return $this->hasMany(Branch::class);
    }

    public function admins(): HasMany
    {
        return $this->hasMany(Admin::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    /** The single VIPURI company record. */
    public static function current(): self
    {
        return once(fn () => static::query()->orderBy('id')->firstOrFail());
    }
}
