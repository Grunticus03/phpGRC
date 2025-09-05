<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Purpose: Policy skeleton for Audit viewing (stub-only).
 * Capability: core.audit.view
 */
final class AuditPolicy
{
    public function view(?User $user): bool
    {
        // TODO: allow Admin/Auditor only when RBAC active
        return true; // stub
    }
}
