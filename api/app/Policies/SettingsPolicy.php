<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Purpose: Policy skeleton for Admin Settings (stub-only).
 * Capability: core.settings.manage
 */
final class SettingsPolicy
{
    public function view(?User $user): bool
    {
        // TODO: gate on RBAC; allow Admin by default
        return true; // stub
    }

    public function update(?User $user): bool
    {
        // TODO: gate on RBAC; allow Admin by default
        return true; // stub
    }
}
