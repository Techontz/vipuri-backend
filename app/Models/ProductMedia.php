<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ProductMedia extends Model
{
    protected $table = 'product_media';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_main' => 'boolean', 'for_variant' => 'boolean'];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(Product::class);
    }

    public function scopeMain($query)
    {
        return $query->where('is_main', 1);
    }
}
