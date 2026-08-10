<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Page extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['secs' => 'array', 'seo_content' => 'object', 'is_default' => 'boolean'];
    }
}
