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

        DB::table('policy_roles')->upsert([
            [
                'policy' => 'integrations.connectors.manage',
                'label' => 'Manage integration connectors',
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ], ['policy'], ['label', 'updated_at']);

        if (Schema::hasTable('policy_role_assignments') && Schema::hasTable('roles')) {
            $adminExists = DB::table('roles')
                ->where('id', 'role_admin')
                ->exists();

            if ($adminExists) {
                DB::table('policy_role_assignments')->upsert([
                    [
                        'policy' => 'integrations.connectors.manage',
                        'role_id' => 'role_admin',
                        'created_at' => $timestamp,
                        'updated_at' => $timestamp,
                    ],
                ], ['policy', 'role_id'], ['updated_at']);
            }
        }
    }

    public function down(): void
    {
        if (! Schema::hasTable('policy_roles')) {
            return;
        }

        DB::table('policy_role_assignments')
            ->where('policy', 'integrations.connectors.manage')
            ->delete();

        DB::table('policy_roles')
            ->where('policy', 'integrations.connectors.manage')
            ->delete();
    }
};
