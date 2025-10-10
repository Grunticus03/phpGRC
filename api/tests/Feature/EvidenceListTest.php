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

    private int $u1Id;

    private int $u2Id;

    private string $evAId;

    private string $evBId;

    private string $evCId;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);

        Gate::define('core.evidence.manage', fn (User $u) => true);

        $u1 = User::factory()->create();
        $u2 = User::factory()->create();
        $this->u1Id = (int) $u1->id;
        $this->u2Id = (int) $u2->id;

        Sanctum::actingAs($u1);

        $t0 = Carbon::parse('2025-01-01T00:00:00Z');
        $t1 = Carbon::parse('2025-01-01T00:01:00Z');
        $t2 = Carbon::parse('2025-01-01T00:02:00Z');

        $evA = Evidence::query()->create([
            'owner_id' => $this->u1Id,
            'filename' => 'report-a.txt',
            'mime' => 'text/plain',
            'size_bytes' => 11,
            'sha256' => hash('sha256', 'hello world'),
            'version' => 1,
            'bytes' => 'hello world',
            'created_at' => $t0,
        ]);
        $this->evAId = (string) $evA->getAttribute('id');

        $evB = Evidence::query()->create([
            'owner_id' => $this->u1Id,
            'filename' => 'image-1.png',
            'mime' => 'image/png',
            'size_bytes' => 3,
            'sha256' => hash('sha256', 'png'),
            'version' => 2,
            'bytes' => 'png',
            'created_at' => $t1,
        ]);
        $this->evBId = (string) $evB->getAttribute('id');

        $evC = Evidence::query()->create([
            'owner_id' => $this->u2Id,
            'filename' => 'photo.jpg',
            'mime' => 'image/jpeg',
            'size_bytes' => 3,
            'sha256' => hash('sha256', 'jpg'),
            'version' => 1,
            'bytes' => 'jpg',
            'created_at' => $t2,
        ]);
        $this->evCId = (string) $evC->getAttribute('id');
    }

    public function test_list_owner_and_mime_filters(): void
    {
        $res = $this->getJson('/evidence?owner_id='.$this->u1Id.'&mime=image/*&order=asc&limit=10');

        $res->assertStatus(200)->assertJsonPath('ok', true);
        $json = $res->json();

        $this->assertIsArray($json['data']);
        $this->assertCount(1, $json['data']);
        $this->assertSame($this->evBId, $json['data'][0]['id']);
        $this->assertSame('image/png', $json['data'][0]['mime']);
        $this->assertSame('PNG image', $json['data'][0]['mime_label']);
    }

    public function test_cursor_pagination_desc(): void
    {
        $r1 = $this->getJson('/evidence?limit=1&order=desc');
        $r1->assertStatus(200)->assertJsonPath('ok', true)->assertJsonCount(1, 'data');
        $cursor = $r1->json('next_cursor');
        $firstId = $r1->json('data.0.id');
        $this->assertNotEmpty($cursor);

        $r2 = $this->getJson('/evidence?limit=1&order=desc&cursor='.urlencode((string) $cursor));
        $r2->assertStatus(200)->assertJsonPath('ok', true)->assertJsonCount(1, 'data');
        $this->assertNotSame($firstId, $r2->json('data.0.id'));
    }

    public function test_filter_by_friendly_label(): void
    {
        $res = $this->getJson('/evidence?mime_label=png');
        $res->assertStatus(200)->assertJsonPath('ok', true);

        $data = $res->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame('PNG image', $data[0]['mime_label']);
        $this->assertSame('image/png', $data[0]['mime']);
    }

    public function test_filter_by_prefix_friendly_label(): void
    {
        $now = Carbon::parse('2025-01-01T00:03:00Z');

        $ppt = Evidence::query()->create([
            'owner_id' => $this->u1Id,
            'filename' => 'deck.pptx',
            'mime' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
            'size_bytes' => 42,
            'sha256' => hash('sha256', 'pptx'),
            'version' => 1,
            'bytes' => 'pptx',
            'created_at' => $now,
        ]);

        $res = $this->getJson('/evidence?mime_label=powerpoint');
        $res->assertStatus(200)->assertJsonPath('ok', true);

        $data = $res->json('data');
        $this->assertIsArray($data);
        $ids = array_map(static fn (array $row): string => (string) $row['id'], $data);
        $this->assertContains((string) $ppt->getAttribute('id'), $ids);

        $match = collect($data)->firstWhere('id', (string) $ppt->getAttribute('id'));
        $this->assertIsArray($match);
        $this->assertSame('Microsoft PowerPoint presentation', $match['mime_label']);
    }
}
