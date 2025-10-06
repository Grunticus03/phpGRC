<?php

declare(strict_types=1);

namespace Tests\Unit\Resources;

use App\Http\Resources\EvidenceResource;
use App\Models\Evidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidenceResourceTest extends TestCase
{
    use RefreshDatabase;

    public function test_maps_size_and_omits_size_bytes(): void
    {
        /** @var User $user */
        $user = User::factory()->create();

        $bytes = random_bytes(7);
        $row = [
            'id' => 'ev_'.Str::ulid()->toBase32(),
            'owner_id' => $user->id,
            'filename' => 'doc.pdf',
            'mime' => 'application/pdf',
            'size_bytes' => strlen($bytes),
            'sha256' => hash('sha256', $bytes),
            'version' => 1,
            'bytes' => $bytes,
            'created_at' => now('UTC'),
            'updated_at' => now('UTC'),
        ];
        DB::table('evidence')->insert($row);

        $model = Evidence::query()->findOrFail($row['id']);
        $out = (new EvidenceResource($model))->resolve();

        $this->assertSame($row['id'], $out['id']);
        $this->assertSame($row['owner_id'], $out['owner_id']);
        $this->assertSame($row['filename'], $out['filename']);
        $this->assertSame($row['mime'], $out['mime']);
        $this->assertSame($row['size_bytes'], $out['size']);
        $this->assertSame($row['sha256'], $out['sha256']);
        $this->assertSame($row['version'], $out['version']);
        $this->assertArrayNotHasKey('size_bytes', $out);
    }
}
