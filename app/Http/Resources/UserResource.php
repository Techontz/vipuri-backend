<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'firstname' => $this->firstname,
            'lastname' => $this->lastname,
            'fullname' => $this->fullname,
            'username' => $this->username,
            'email' => $this->email,
            'dial_code' => $this->dial_code,
            'mobile' => $this->mobile,
            'image' => fileUrl('userProfile', $this->image),
            'address' => $this->address,
            'city' => $this->city,
            'state' => $this->state,
            'zip' => $this->zip,
            'country_name' => $this->country_name,
            'country_code' => $this->country_code,
            'preferred_branch_id' => $this->preferred_branch_id,
            'status' => (bool) $this->status,
            'ban_reason' => $this->ban_reason,
            'email_verified' => (bool) $this->ev,
            'mobile_verified' => (bool) $this->sv,
            'profile_complete' => (bool) $this->profile_complete,
            'orders_count' => $this->when(isset($this->orders_count), fn () => (int) $this->orders_count),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
