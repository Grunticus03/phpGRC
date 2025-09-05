<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\User;

/**
 * Purpose: Policy skeleton for Evidence actions (stub-only).
 * Capability: core.evidence.manage
 */
final class EvidencePolicy
{
    public function create(?User $user): bool
    {
        // TODO: enforce size/mime rules + role checks
        return true; // stub
    }

    public function view(?User $user): bool
    {
        // TODO: restrict by ownership or role
        return true; // stub
    }
}
