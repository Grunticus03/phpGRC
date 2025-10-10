<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('policy_roles', function (Blueprint $table): void {
            $table->string('policy')->primary();
            $table->string('label')->nullable();
            $table->timestampsTz();
        });

        Schema::create('policy_role_assignments', function (Blueprint $table): void {
            $table->string('policy');
            $table->string('role_id');
            $table->timestampsTz();

            $table->primary(['policy', 'role_id']);

            $table->foreign('policy')
                ->references('policy')
                ->on('policy_roles')
                ->cascadeOnDelete();

            $table->foreign('role_id')
                ->references('id')
                ->on('roles')
                ->cascadeOnDelete();
        });

        $timestamp = now('UTC')->toDateTimeString();

        $policies = [
            ['policy' => 'core.settings.manage',   'label' => 'Manage core settings'],
            ['policy' => 'core.audit.view',        'label' => 'View audit events'],
            ['policy' => 'core.audit.export',      'label' => 'Export audit events'],
            ['policy' => 'core.metrics.view',      'label' => 'View metrics'],
            ['policy' => 'core.reports.view',      'label' => 'View reports'],
            ['policy' => 'core.users.view',        'label' => 'View users'],
            ['policy' => 'core.users.manage',      'label' => 'Manage users'],
            ['policy' => 'core.evidence.view',     'label' => 'View evidence'],
            ['policy' => 'core.evidence.manage',   'label' => 'Manage evidence'],
            ['policy' => 'core.exports.generate',  'label' => 'Generate exports'],
            ['policy' => 'core.rbac.view',         'label' => 'View RBAC policies'],
            ['policy' => 'rbac.roles.manage',      'label' => 'Manage roles'],
            ['policy' => 'rbac.user_roles.manage', 'label' => 'Manage user roles'],
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

        $assignments = [
            ['policy' => 'core.settings.manage',   'role_id' => 'role_admin'],
            ['policy' => 'core.audit.view',        'role_id' => 'role_admin'],
            ['policy' => 'core.audit.view',        'role_id' => 'role_auditor'],
            ['policy' => 'core.audit.view',        'role_id' => 'role_risk_manager'],
            ['policy' => 'core.audit.export',      'role_id' => 'role_admin'],
            ['policy' => 'core.audit.export',      'role_id' => 'role_auditor'],
            ['policy' => 'core.metrics.view',      'role_id' => 'role_admin'],
            ['policy' => 'core.metrics.view',      'role_id' => 'role_auditor'],
            ['policy' => 'core.metrics.view',      'role_id' => 'role_risk_manager'],
            ['policy' => 'core.reports.view',      'role_id' => 'role_admin'],
            ['policy' => 'core.reports.view',      'role_id' => 'role_auditor'],
            ['policy' => 'core.reports.view',      'role_id' => 'role_risk_manager'],
            ['policy' => 'core.users.view',        'role_id' => 'role_admin'],
            ['policy' => 'core.users.manage',      'role_id' => 'role_admin'],
            ['policy' => 'core.evidence.view',     'role_id' => 'role_admin'],
            ['policy' => 'core.evidence.view',     'role_id' => 'role_auditor'],
            ['policy' => 'core.evidence.view',     'role_id' => 'role_risk_manager'],
            ['policy' => 'core.evidence.view',     'role_id' => 'role_user'],
            ['policy' => 'core.evidence.manage',   'role_id' => 'role_admin'],
            ['policy' => 'core.evidence.manage',   'role_id' => 'role_risk_manager'],
            ['policy' => 'core.exports.generate',  'role_id' => 'role_admin'],
            ['policy' => 'core.exports.generate',  'role_id' => 'role_risk_manager'],
            ['policy' => 'core.rbac.view',         'role_id' => 'role_admin'],
            ['policy' => 'core.rbac.view',         'role_id' => 'role_auditor'],
            ['policy' => 'rbac.roles.manage',      'role_id' => 'role_admin'],
            ['policy' => 'rbac.user_roles.manage', 'role_id' => 'role_admin'],
        ];

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
        Schema::dropIfExists('policy_role_assignments');
        Schema::dropIfExists('policy_roles');
    }
};
