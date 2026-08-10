<?php

namespace App\Services;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Records who did what, where. Super admins read the whole trail; branch
 * managers only see entries for their own branch.
 */
class AuditService
{
    public function log(
        string $event,
        ?Model $subject = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        ?string $description = null,
        ?int $branchId = null,
    ): AuditLog {
        $admin = auth('admin')->user();
        $user = auth('user')->user();

        if ($admin) {
            $actorType = 'admin';
            $actorId = $admin->id;
            $actorName = $admin->name;
            $branchId ??= $admin->branch_id;
        } elseif ($user) {
            $actorType = 'user';
            $actorId = $user->id;
            $actorName = trim($user->firstname . ' ' . $user->lastname) ?: $user->username;
        } else {
            $actorType = 'system';
            $actorId = null;
            $actorName = 'System';
        }

        return AuditLog::create([
            'company_id' => optional(\App\Models\Company::query()->orderBy('id')->first())->id,
            'branch_id' => $branchId,
            'actor_type' => $actorType,
            'actor_id' => $actorId,
            'actor_name' => $actorName,
            'event' => $event,
            'auditable_type' => $subject ? $subject::class : null,
            'auditable_id' => $subject?->getKey(),
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => substr((string) request()->userAgent(), 0, 255),
        ]);
    }

    /** Convenience for model updates: records only the changed attributes. */
    public function logUpdate(string $event, Model $model, array $before, ?string $description = null): ?AuditLog
    {
        $after = $model->getAttributes();
        $changed = [];

        foreach ($after as $key => $value) {
            if (array_key_exists($key, $before) && $before[$key] != $value) {
                $changed[$key] = $value;
            }
        }

        if (empty($changed)) {
            return null;
        }

        return $this->log(
            $event,
            $model,
            array_intersect_key($before, $changed),
            $changed,
            $description,
        );
    }
}
