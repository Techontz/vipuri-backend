<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AdminResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'username' => $this->username,
            'dial_code' => $this->dial_code,
            'mobile' => $this->mobile,
            'image' => fileUrl('adminProfile', $this->image),
            'status' => (bool) $this->status,
            'ban_reason' => $this->ban_reason,
            'branch' => $this->branch ? [
                'id' => $this->branch->id,
                'name' => $this->branch->name,
                'code' => $this->branch->code,
                'city' => $this->branch->city,
            ] : null,
            'branch_id' => $this->branch_id,
            'role' => $this->getRoleNames()->first(),
            'permissions' => $this->getAllPermissions()->pluck('name')->values(),
            'is_super_admin' => $this->isSuperAdmin(),
            // Company-wide staff (super admin, admin) see every branch.
            'is_company_wide' => $this->isCompanyWide(),
            'role_level' => $this->roleLevel(),
            'last_login_at' => $this->last_login_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
