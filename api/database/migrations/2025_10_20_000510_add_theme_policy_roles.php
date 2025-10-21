<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('policy_roles')) {
            return;
        }

        $timestamp = now('UTC')->toDateTimeString();

        $policies = [
            ['policy' => 'ui.theme.view', 'label' => 'View theme settings'],
            ['policy' => 'ui.theme.manage', 'label' => 'Manage theme settings'],
            ['policy' => 'ui.theme.pack.manage', 'label' => 'Manage theme packs'],
        ];

        $policies = array_map(static function (array $row) use ($timestamp): array {
            return array_merge($row, [
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }, $policies);

        DB::table('policy_roles')->upsert(
            $policies,
            ['policy'],
            ['label', 'updated_at']
        );

        if (! Schema::hasTable('policy_role_assignments') || ! Schema::hasTable('roles')) {
            return;
        }

        /** @var list<string> $existingRoles */
        $existingRoles = DB::table('roles')->pluck('id')->all();
        if ($existingRoles === []) {
            return;
        }

        $assignments = [
            ['policy' => 'ui.theme.view', 'role_id' => 'role_admin'],
            ['policy' => 'ui.theme.view', 'role_id' => 'role_auditor'],
            ['policy' => 'ui.theme.view', 'role_id' => 'role_theme_manager'],
            ['policy' => 'ui.theme.view', 'role_id' => 'role_theme_auditor'],
            ['policy' => 'ui.theme.manage', 'role_id' => 'role_admin'],
            ['policy' => 'ui.theme.manage', 'role_id' => 'role_theme_manager'],
            ['policy' => 'ui.theme.pack.manage', 'role_id' => 'role_admin'],
            ['policy' => 'ui.theme.pack.manage', 'role_id' => 'role_theme_manager'],
        ];

        $assignments = array_values(array_filter(
            $assignments,
            static fn (array $row): bool => in_array($row['role_id'], $existingRoles, true)
        ));

        if ($assignments === []) {
            return;
        }

        $assignments = array_map(static function (array $row) use ($timestamp): array {
            return array_merge($row, [
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]);
        }, $assignments);

        DB::table('policy_role_assignments')->upsert(
            $assignments,
            ['policy', 'role_id'],
            ['updated_at']
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('policy_role_assignments') || ! Schema::hasTable('policy_roles')) {
            return;
        }

        DB::table('policy_role_assignments')
            ->whereIn('policy', [
                'ui.theme.view',
                'ui.theme.manage',
                'ui.theme.pack.manage',
            ])
            ->delete();

        DB::table('policy_roles')
            ->whereIn('policy', [
                'ui.theme.view',
                'ui.theme.manage',
                'ui.theme.pack.manage',
            ])
            ->delete();
    }
};
