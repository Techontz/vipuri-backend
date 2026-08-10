<?php

namespace App\Models;

use App\Constants\Status;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Attribute extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_global' => 'boolean'];
    }

    public function values(): HasMany
    {
        return $this->hasMany(AttributeValue::class);
    }

    public function productAttributes(): HasMany
    {
        return $this->hasMany(ProductAttribute::class);
    }

    public function scopeGlobal($query)
    {
        return $query->where('is_global', Status::YES);
    }
}
