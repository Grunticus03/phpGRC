<?php
declare(strict_types=1);

namespace Tests\Feature\Metrics;

use App\Models\AuditEvent;
use App\Models\Evidence;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class DashboardKpisTest extends TestCase
{
    use RefreshDatabase;

    public function test_kpis_endpoint_returns_data(): void
    {
        config([
            'core.rbac.require_auth' => false,
            'core.audit.enabled'     => true,
            'core.evidence.enabled'  => true,
        ]);

        $now = CarbonImmutable::now('UTC');

        // Baseline counts in case other fixtures exist.
        $beforeTotal = (int) Evidence::query()->count();
        $beforeStale = (int) Evidence::query()
            ->where('updated_at', '<', $now->subDays(30))
            ->count();

        // Seed audit events: 3 total, 1 deny in window
        AuditEvent::query()->create([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $now->subDays(1),
            'actor_id'    => null,
            'action'      => 'rbac.deny.policy',
            'category'    => 'RBAC',
            'entity_type' => 'route',
            'entity_id'   => 'test',
            'ip'          => '127.0.0.1',
            'ua'          => 'phpunit',
            'meta'        => [],
            'created_at'  => $now,
        ]);
        AuditEvent::query()->create([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $now->subDays(1),
            'actor_id'    => 1,
            'action'      => 'auth.login.success',
            'category'    => 'AUTH',
            'entity_type' => 'user',
            'entity_id'   => 'u1',
            'ip'          => '127.0.0.1',
            'ua'          => 'phpunit',
            'meta'        => [],
            'created_at'  => $now,
        ]);
        AuditEvent::query()->create([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $now->subDays(2),
            'actor_id'    => 1,
            'action'      => 'rbac.allow',
            'category'    => 'RBAC',
            'entity_type' => 'route',
            'entity_id'   => 'ok',
            'ip'          => '127.0.0.1',
            'ua'          => 'phpunit',
            'meta'        => [],
            'created_at'  => $now,
        ]);

        // Seed evidence: 2 new items, one stale for 30d window
        Evidence::query()->create([
            'id'         => 'ev_' . Str::ulid()->toBase32(),
            'owner_id'   => 1,
            'filename'   => 'a.pdf',
            'mime'       => 'application/pdf',
            'size_bytes' => 100,
            'sha256'     => str_repeat('a', 64),
            'version'    => 1,
            'bytes'      => 'x',
            'created_at' => $now->subDays(40),
            'updated_at' => $now->subDays(40),
        ]);
        Evidence::query()->create([
            'id'         => 'ev_' . Str::ulid()->toBase32(),
            'owner_id'   => 1,
            'filename'   => 'b.txt',
            'mime'       => 'text/plain',
            'size_bytes' => 50,
            'sha256'     => str_repeat('b', 64),
            'version'    => 1,
            'bytes'      => 'y',
            'created_at' => $now->subDays(2),
            'updated_at' => $now->subDays(2),
        ]);

        $resp = $this->getJson('/api/dashboard/kpis?days=30');
        $resp->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonStructure([
                'ok',
                'data' => [
                    'rbac_denies' => ['window_days','from','to','denies','total','rate','daily'],
                    'evidence_freshness' => ['days','total','stale','percent','by_mime'],
                ],
                'meta' => ['generated_at','window' => ['rbac_days','fresh_days']],
            ]);

        /** @var array<string,mixed> $data */
        $data = $resp->json('data');

        $this->assertSame(1, (int) $data['rbac_denies']['denies']);

        $expectedTotal = $beforeTotal + 2;
        $expectedStale = $beforeStale + 1;

        $this->assertSame($expectedTotal, (int) $data['evidence_freshness']['total']);
        $this->assertSame($expectedStale, (int) $data['evidence_freshness']['stale']);
    }

    public function test_kpis_endpoint_requires_auth_when_enabled(): void
    {
        config(['core.rbac.require_auth' => true]);
        $this->getJson('/api/dashboard/kpis')->assertStatus(401);
    }
}

