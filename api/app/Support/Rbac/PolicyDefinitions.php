<?php

declare(strict_types=1);

namespace App\Support\Rbac;

final class PolicyDefinitions
{
    /**
     * @return array<string, array{label:?string, description:?string}>
     */
    public static function definitions(): array
    {
        return [
            'core.settings.manage' => [
                'label' => 'Manage core settings',
                'description' => 'Allows administrators to update global configuration values.',
            ],
            'core.audit.view' => [
                'label' => 'View audit events',
                'description' => 'Grants read-only access to the audit log.',
            ],
            'core.audit.export' => [
                'label' => 'Export audit events',
                'description' => 'Allows exporting audit logs to CSV.',
            ],
            'core.metrics.view' => [
                'label' => 'View metrics',
                'description' => 'Authorizes access to KPI dashboards and metrics APIs.',
            ],
            'core.reports.view' => [
                'label' => 'View reports',
                'description' => 'Allows access to generated compliance and risk reports.',
            ],
            'core.users.view' => [
                'label' => 'View users',
                'description' => 'Permits listing users and viewing user details.',
            ],
            'core.users.manage' => [
                'label' => 'Manage users',
                'description' => 'Allows creating, updating, and deleting users.',
            ],
            'core.evidence.view' => [
                'label' => 'View evidence',
                'description' => 'Grants read-only access to uploaded evidence artifacts.',
            ],
            'core.evidence.manage' => [
                'label' => 'Manage evidence',
                'description' => 'Allows uploading, updating, and deleting evidence.',
            ],
            'core.exports.generate' => [
                'label' => 'Generate exports',
                'description' => 'Authorizes launching data export jobs.',
            ],
            'core.rbac.view' => [
                'label' => 'View RBAC policies',
                'description' => 'Allows inspection of role/policy assignments.',
            ],
            'rbac.roles.manage' => [
                'label' => 'Manage roles',
                'description' => 'Allows creating, renaming, and deleting roles.',
            ],
            'rbac.user_roles.manage' => [
                'label' => 'Manage user roles',
                'description' => 'Allows assigning roles to users.',
            ],
            'integrations.connectors.manage' => [
                'label' => 'Manage integration connectors',
                'description' => 'Allows administrators to configure Integration Bus connectors and secrets.',
            ],
        ];
    }
}
