<?php

namespace App\Models;

use App\Traits\GlobalStatus;
use Illuminate\Database\Eloquent\Model;

class ShippingClass extends Model
{
    protected $guarded = ['id'];

    use GlobalStatus;
}
