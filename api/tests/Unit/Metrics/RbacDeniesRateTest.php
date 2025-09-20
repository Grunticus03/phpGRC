<?php
declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Models\AuditEvent;
use App\Services\Metrics\RbacDeniesCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class RbacDeniesRateTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_denies_rate_and_daily_buckets(): void
    {
        $now = CarbonImmutable::now('UTC')->startOfDay();

        // Day -2: 2 RBAC denies, 1 AUTH event
        $this->audit('RBAC', 'rbac.deny.policy', $now->subDays(2)->addHours(1));
        $this->audit('RBAC', 'rbac.deny.role_mismatch', $now->subDays(2)->addHours(2));
        $this->audit('AUTH', 'auth.login.success', $now->subDays(2)->addHours(3));

        // Day -1: 1 RBAC non-deny, 2 AUTH events
        $this->audit('RBAC', 'rbac.policy.checked', $now->subDay()->addHours(1));
        $this->audit('AUTH', 'auth.login.failed', $now->subDay()->addHours(2));
        $this->audit('AUTH', 'auth.login.success', $now->subDay()->addHours(3));

        // Day 0: 1 RBAC deny, 0 AUTH
        $this->audit('RBAC', 'rbac.deny.capability', $now->addHours(1));

        $calc = new RbacDeniesCalculator();
        $out = $calc->compute(3);

        $this->assertSame(3, $out['window_days']);
        $this->assertSame(6, $out['total']);
        $this->assertSame(3, $out['denies']);
        $this->assertEquals(0.5, $out['rate']);

        $this->assertCount(3, $out['daily']);

        foreach ($out['daily'] as $d) {
            $this->assertIsString($d['date']);
            $this->assertGreaterThanOrEqual(0, $d['denies']);
            $this->assertGreaterThanOrEqual(0, $d['total']);
            $this->assertGreaterThanOrEqual(0.0, $d['rate']);
            $this->assertLessThanOrEqual(1.0, $d['rate']);
        }
    }

    private function audit(string $category, string $action, CarbonImmutable $when): void
    {
        AuditEvent::query()->create([
            'id'          => Str::ulid()->toBase32(),
            'occurred_at' => $when,
            'actor_id'    => null,
            'action'      => $action,
            'category'    => $category,
            'entity_type' => 'test',
            'entity_id'   => 'seed',
            'ip'          => '127.0.0.1',
            'ua'          => 'phpunit',
            'meta'        => ['seed' => true],
            'created_at'  => $when,
        ]);
    }
}

