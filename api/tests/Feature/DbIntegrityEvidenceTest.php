<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DbIntegrityEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_insert_succeeds(): void
    {
        $user = User::factory()->create();

        $id = 'ev_'.Str::ulid()->toBase32();

        Evidence::query()->create([
            'id' => $id,
            'owner_id' => $user->id,
            'filename' => 'ok.txt',
            'mime' => 'text/plain',
            'size_bytes' => 2,
            'sha256' => hash('sha256', 'ok'),
            'version' => 1,
            'bytes' => 'ok',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->assertDatabaseHas('evidence', ['id' => $id, 'owner_id' => $user->id]);
    }

    public function test_fk_blocks_orphan_insert_on_mysql(): void
    {
        if (DB::connection()->getDriverName() !== 'mysql') {
            $this->markTestSkipped('FK enforcement test runs on MySQL only.');
        }

        $this->expectException(QueryException::class);

        Evidence::query()->create([
            'id' => 'ev_'.Str::ulid()->toBase32(),
            'owner_id' => PHP_INT_MAX, // non-existent
            'filename' => 'bad.txt',
            'mime' => 'text/plain',
            'size_bytes' => 3,
            'sha256' => hash('sha256', 'bad'),
            'version' => 1,
            'bytes' => 'bad',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
