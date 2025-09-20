<?php
declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Models\Evidence;
use App\Services\Metrics\EvidenceFreshnessCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class EvidenceFreshnessTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_freshness_and_breakdown(): void
    {
        $now = CarbonImmutable::now('UTC')->startOfDay();

        // Stale: 40d old (pdf)
        $this->evidence('application/pdf', $now->subDays(40));
        // Fresh: 10d old (pdf)
        $this->evidence('application/pdf', $now->subDays(10));
        // Stale: 31d old (png)
        $this->evidence('image/png', $now->subDays(31));

        $calc = new EvidenceFreshnessCalculator();
        $out = $calc->compute(30);

        $this->assertSame(30, $out['days']);
        $this->assertSame(3, $out['total']);
        $this->assertSame(2, $out['stale']);
        $this->assertEquals(2/3, $out['percent']);

        // by_mime has deterministic sort order
        $this->assertIsArray($out['by_mime']);
        $this->assertNotEmpty($out['by_mime']);

        $map = [];
        foreach ($out['by_mime'] as $row) {
            $this->assertIsString($row['mime']);
            $map[$row['mime']] = $row;
        }

        // application/pdf: 2 total, 1 stale
        $this->assertArrayHasKey('application/pdf', $map);
        $this->assertSame(2, $map['application/pdf']['total']);
        $this->assertSame(1, $map['application/pdf']['stale']);
        $this->assertEquals(0.5, $map['application/pdf']['percent']);

        // image/png: 1 total, 1 stale
        $this->assertArrayHasKey('image/png', $map);
        $this->assertSame(1, $map['image/png']['total']);
        $this->assertSame(1, $map['image/png']['stale']);
        $this->assertEquals(1.0, $map['image/png']['percent']);
    }

    private function evidence(string $mime, CarbonImmutable $updatedAt): void
    {
        Evidence::query()->create([
            'id'         => Str::ulid()->toBase32(),
            'owner_id'   => 1,
            'filename'   => 'seed.txt',
            'mime'       => $mime,
            'size_bytes' => 123,
            'sha256'     => str_repeat('a', 64),
            'version'    => 1,
            'bytes'      => random_bytes(1),
            'created_at' => $updatedAt,
            'updated_at' => $updatedAt,
        ]);
    }
}

