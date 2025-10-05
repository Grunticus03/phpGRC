<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

final class AuditPolicy
{
    public function view(?User $user): bool
    {
        if (! (bool) config('core.rbac.enabled', false)) {
            return true;
        }
        return $user !== null && $user->hasAnyRole(['Admin', 'Auditor']);
    }
}
