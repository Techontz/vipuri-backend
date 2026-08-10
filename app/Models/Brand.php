<?php

namespace App\Models;

use App\Constants\Status;
use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Brand extends Model
{
    use GlobalStatus;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => 'boolean',
            'is_popular' => 'boolean',
            'seo_content' => 'object',
        ];
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function scopePopular($query)
    {
        return $query->where('is_popular', Status::YES);
    }
}
