<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Evidence;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceListTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);

        // Permit ability and authenticate a user
        Gate::define('core.evidence.manage', fn (User $u) => true);
        $u = User::query()->create([
            'name' => 'T',
            'email' => 't@example.com',
            'password' => bcrypt('x'),
        ]);
        Sanctum::actingAs($u);

        $t0 = Carbon::parse('2025-01-01T00:00:00Z');
        $t1 = Carbon::parse('2025-01-01T00:01:00Z');
        $t2 = Carbon::parse('2025-01-01T00:02:00Z');

        Evidence::query()->create([
            'id' => 'ev_a',
            'owner_id' => 1,
            'filename' => 'report-a.txt',
            'mime' => 'text/plain',
            'size_bytes' => 11,
            'sha256' => hash('sha256', 'hello world'),
            'version' => 1,
            'bytes' => 'hello world',
            'created_at' => $t0,
        ]);

        Evidence::query()->create([
            'id' => 'ev_b',
            'owner_id' => 1,
            'filename' => 'image-1.png',
            'mime' => 'image/png',
            'size_bytes' => 3,
            'sha256' => hash('sha256', 'png'),
            'version' => 2,
            'bytes' => 'png',
            'created_at' => $t1,
        ]);

        Evidence::query()->create([
            'id' => 'ev_c',
            'owner_id' => 2,
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 3,
            'sha256' => hash('sha256', 'jpg'),
            'version' => 1,
            'bytes' => 'jpg',
            'created_at' => $t2,
        ]);
    }

    public function test_list_owner_and_mime_filters(): void
    {
        $res = $this->getJson('/api/evidence?owner_id=1&mime=image/*&order=asc&limit=10');

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $json = $res->json();

        $this->assertIsArray($json['data']);
        $this->assertCount(1, $json['data']);
        $this->assertSame('ev_b', $json['data'][0]['id']);
        $this->assertSame('image/png', $json['data'][0]['mime']);
    }

    public function test_cursor_pagination_desc(): void
    {
        // First page newest first, limit 1
        $r1 = $this->getJson('/api/evidence?limit=1&order=desc');
        $r1->assertStatus(200)->assertJsonPath('ok', true)->assertJsonCount(1, 'data');
        $cursor = $r1->json('next_cursor');
        $firstId = $r1->json('data.0.id');
        $this->assertNotEmpty($cursor);

        // Second page via cursor returns a different id
        $r2 = $this->getJson('/api/evidence?limit=1&order=desc&cursor=' . urlencode($cursor));
        $r2->assertStatus(200)->assertJsonPath('ok', true)->assertJsonCount(1, 'data');
        $this->assertNotSame($firstId, $r2->json('data.0.id'));
    }
}
