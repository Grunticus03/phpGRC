<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditIndexTest extends TestCase
{
    use RefreshDatabase;

    public function test_lists_with_cursor(): void
    {
        // Seed 3 events
        $t0 = Carbon::parse('2025-01-01T00:00:00Z');
        foreach ([0,1,2] as $i) {
            AuditEvent::create([
                'id'          => Str::ulid()->toBase32(),
                'occurred_at' => $t0->copy()->addMinutes($i),
                'actor_id'    => 1,
                'action'      => 'unit.test',
                'category'    => 'TEST',
                'entity_type' => 'x',
                'entity_id'   => (string) $i,
                'ip'          => null,
                'ua'          => null,
                'meta'        => null,
                'created_at'  => $t0->copy()->addMinutes($i),
            ]);
        }

        // Page 1
        $r1 = $this->getJson('/audit?limit=2')->assertOk()->json();
        $this->assertTrue($r1['ok']);
        $this->assertCount(2, $r1['items']);
        $this->assertNotNull($r1['nextCursor']);

        // Page 2
        $cursor = $r1['nextCursor'];
        $r2 = $this->getJson('/audit?limit=2&cursor='.$cursor)->assertOk()->json();
        $this->assertTrue($r2['ok']);
        $this->assertCount(1, $r2['items']);
        $this->assertNull($r2['nextCursor']);
    }
}
