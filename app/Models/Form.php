<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Form extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['form_data' => 'object'];
    }
}
