<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('evidence')) {
            try {
                Schema::table('evidence', function (Blueprint $table): void {
                    $table->foreign('owner_id', 'evidence_owner_id_fk')
                        ->references('id')->on('users')
                        ->cascadeOnDelete();
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (stripos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        }

        if (Schema::hasTable('avatars')) {
            try {
                Schema::table('avatars', function (Blueprint $table): void {
                    $table->foreign('user_id', 'avatars_user_id_fk')
                        ->references('id')->on('users')
                        ->cascadeOnDelete();
                });
            } catch (\Illuminate\Database\QueryException $e) {
                if (stripos($e->getMessage(), 'Duplicate') === false) {
                    throw $e;
                }
            }
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('evidence')) {
            try {
                Schema::table('evidence', function (Blueprint $table): void {
                    $table->dropForeign('evidence_owner_id_fk');
                });
            } catch (\Throwable $e) {
                // no-op
            }
        }

        if (Schema::hasTable('avatars')) {
            try {
                Schema::table('avatars', function (Blueprint $table): void {
                    $table->dropForeign('avatars_user_id_fk');
                });
            } catch (\Throwable $e) {
                // no-op
            }
        }
    }
};
