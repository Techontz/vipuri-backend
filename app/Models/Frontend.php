<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Frontend extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['data_values' => 'object', 'seo_content' => 'object'];
    }
}
