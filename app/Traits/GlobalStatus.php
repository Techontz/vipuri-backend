<?php

namespace App\Traits;

use App\Constants\Status;

trait GlobalStatus
{
    public function scopeActive($query)
    {
        return $query->where('status', Status::ENABLE);
    }

    public function scopeInactive($query)
    {
        return $query->where('status', Status::DISABLE);
    }

    /** Flip the `status` column and persist. */
    public function changeStatus(): static
    {
        $this->status = $this->status == Status::ENABLE ? Status::DISABLE : Status::ENABLE;
        $this->save();

        return $this;
    }
}
