<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class BranchResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'code' => $this->code,
            'slug' => $this->slug,
            'email' => $this->email,
            'dial_code' => $this->dial_code,
            'phone' => $this->phone,
            'address' => $this->address,
            'city' => $this->city,
            'region' => $this->region,
            'postal_code' => $this->postal_code,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
            'image' => fileUrl('branch', $this->image),
            'is_default' => (bool) $this->is_default,
            'is_pickup_point' => (bool) $this->is_pickup_point,
            'status' => (bool) $this->status,
            'opening_hours' => $this->opening_hours,
            'staff_count' => $this->when(isset($this->staff_count), fn () => (int) $this->staff_count),
            'orders_count' => $this->when(isset($this->orders_count), fn () => (int) $this->orders_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
