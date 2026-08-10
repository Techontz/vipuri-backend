<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class NotificationTemplate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'shortcodes' => 'object',
            'email_status' => 'boolean',
            'sms_status' => 'boolean',
            'push_status' => 'boolean',
        ];
    }
}
