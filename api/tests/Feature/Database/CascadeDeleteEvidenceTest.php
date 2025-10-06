<?php

declare(strict_types=1);

namespace Tests\Feature\Database;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Verifies ON DELETE CASCADE from users -> evidence.
 * Evidence schema and FK are documented in docs/db/schema.md.
 */
final class CascadeDeleteEvidenceTest extends TestCase
{
    use RefreshDatabase;

    public function test_deleting_user_cascades_evidence(): void
    {
        // Arrange: create user
        /** @var User $user */
        $user = User::factory()->create();

        // Insert two evidence rows owned by the user
        $this->insertEvidence($user->id, 'report-a.pdf');
        $this->insertEvidence($user->id, 'report-b.pdf');

        $this->assertSame(2, (int) DB::table('evidence')->where('owner_id', $user->id)->count());

        // Act: delete user
        $user->delete();

        // Assert: evidence rows are gone due to FK cascade
        $this->assertSame(0, (int) DB::table('evidence')->where('owner_id', $user->id)->count());
    }

    private function insertEvidence(int $ownerId, string $filename): void
    {
        $bytes = random_bytes(16);
        DB::table('evidence')->insert([
            'id' => 'ev_'.Str::ulid()->toBase32(),
            'owner_id' => $ownerId,
            'filename' => $filename,
            'mime' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'version' => 1,
            'bytes' => $bytes,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ]);
    }
}
