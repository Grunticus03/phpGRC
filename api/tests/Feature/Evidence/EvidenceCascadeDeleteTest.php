<?php
// FILE: api/tests/Feature/Evidence/EvidenceCascadeDeleteTest.php

declare(strict_types=1);

namespace Tests\Feature\Evidence;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class EvidenceCascadeDeleteTest extends TestCase
{
    use RefreshDatabase;

    public function test_evidence_rows_are_deleted_when_owner_user_is_deleted(): void
    {
        // Create a user via DB to avoid model/factory coupling.
        $userId = DB::table('users')->insertGetId([
            'name' => 'Owner',
            'email' => 'owner.' . Str::uuid() . '@example.test',
            'password' => bcrypt('secret1234'),
            'remember_token' => Str::random(10),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Insert two evidence rows owned by the user.
        $evidenceRows = [
            [
                'id' => 'ev_' . (string) Str::ulid(),
                'owner_id' => $userId,
                'filename' => 'doc1.pdf',
                'mime' => 'application/pdf',
                'size_bytes' => 0,
                'sha256' => str_repeat('0', 64),
                'version' => 1,
                'bytes' => '', // empty blob
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'id' => 'ev_' . (string) Str::ulid(),
                'owner_id' => $userId,
                'filename' => 'img1.png',
                'mime' => 'image/png',
                'size_bytes' => 0,
                'sha256' => str_repeat('f', 64),
                'version' => 1,
                'bytes' => '',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ];

        DB::table('evidence')->insert($evidenceRows);

        // Sanity: evidence present for this owner.
        $preCount = DB::table('evidence')->where('owner_id', $userId)->count();
        $this->assertSame(2, $preCount, 'Expected 2 evidence rows before delete');

        // Delete the user. FK should cascade delete evidence.
        DB::table('users')->where('id', $userId)->delete();

        // Assert cascade occurred.
        $postCount = DB::table('evidence')->where('owner_id', $userId)->count();
        $this->assertSame(0, $postCount, 'Expected 0 evidence rows after owner deletion');
    }
}
