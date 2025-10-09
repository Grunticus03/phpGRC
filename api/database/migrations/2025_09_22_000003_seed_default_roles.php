<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        $timestamp = now('UTC')->toDateTimeString();

        $roles = [
            ['id' => 'role_admin',    'name' => 'Admin',        'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 'role_auditor',  'name' => 'Auditor',      'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 'role_risk_mgr', 'name' => 'Risk Manager', 'created_at' => $timestamp, 'updated_at' => $timestamp],
            ['id' => 'role_user',     'name' => 'User',         'created_at' => $timestamp, 'updated_at' => $timestamp],
        ];

        DB::table('roles')->upsert(
            $roles,
            ['id'],
            ['name', 'updated_at']
        );
    }

    public function down(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        DB::table('roles')
            ->whereIn('id', ['role_admin', 'role_auditor', 'role_risk_mgr', 'role_user'])
            ->delete();
    }
};
