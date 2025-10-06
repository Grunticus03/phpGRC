<?php

declare(strict_types=1);

namespace App\Policies;

use App\Models\Export;
use App\Models\User;

/**
 * ExportPolicy
 *
 * Contracts:
 * - Create requires Admin role AND capability core.exports.generate enabled.
 * - Status/Download require Admin OR Auditor role.
 *
 * Route-level RBAC and capability gates remain authoritative. This policy
 * reflects the same rules for use with $this->authorize(...) when adopted.
 */
final class ExportPolicy
{
    /**
     * Determine whether the user can create an export job.
     */
    public function create(?User $user): bool
    {
        if ($user === null) {
            return false;
        }

        if (! $this->capabilityEnabled('core.exports.generate')) {
            return false;
        }

        return $this->hasAnyRole($user, ['Admin']);
    }

    /**
     * Determine whether the user can view export status.
     */
    public function viewStatus(?User $user, ?Export $export = null): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->hasAnyRole($user, ['Admin', 'Auditor']);
    }

    /**
     * Determine whether the user can download an export artifact.
     */
    public function download(?User $user, ?Export $export = null): bool
    {
        if ($user === null) {
            return false;
        }

        return $this->hasAnyRole($user, ['Admin', 'Auditor']);
    }

    /**
     * @param  array<int,string>  $roles
     */
    private function hasAnyRole(User $user, array $roles): bool
    {
        return $user->hasAnyRole($roles);
    }

    private function capabilityEnabled(string $capability): bool
    {
        /** @var bool $enabled */
        $enabled = (bool) config('core.capabilities.'.$capability, true);

        return $enabled;
    }
}
