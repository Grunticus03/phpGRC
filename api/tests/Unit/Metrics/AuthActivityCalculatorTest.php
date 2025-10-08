<?php

declare(strict_types=1);

namespace Tests\Unit\Metrics;

use App\Models\AuditEvent;
use App\Services\Metrics\AuthActivityCalculator;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuthActivityCalculatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_compute_auth_activity_daily_counts(): void
    {
        $now = CarbonImmutable::now('UTC')->startOfDay();

        // Day -2: 2 successes, 1 failure
        $this->logAuth('auth.login', $now->subDays(2)->addHours(1));
        $this->logAuth('auth.login', $now->subDays(2)->addHours(2));
        $this->logAuth('auth.login.failed', $now->subDays(2)->addHours(3));

        // Day -1: 1 success
        $this->logAuth('auth.login', $now->subDay()->addHours(1));

        // Day 0: 2 failures
        $this->logAuth('auth.login.failed', $now->addHours(1));
        $this->logAuth('auth.login.failed', $now->addHours(2));

        $calc = new AuthActivityCalculator;
        $out = $calc->compute(3);

        $this->assertSame(3, $out['window_days']);
        $this->assertSame(3, $out['totals']['success']);
        $this->assertSame(3, $out['totals']['failed']);
        $this->assertSame(6, $out['totals']['total']);
        $this->assertSame(3, $out['max_daily_total']);

        $daily = collect($out['daily'])->keyBy(fn (array $row): string => $row['date']);

        $dayMinusTwo = $now->subDays(2)->format('Y-m-d');
        $dayMinusOne = $now->subDay()->format('Y-m-d');
        $dayZero = $now->format('Y-m-d');

        /** @var array{success:int,failed:int,total:int}|null $minusTwoData */
        $minusTwoData = $daily->get($dayMinusTwo);
        $this->assertNotNull($minusTwoData);
        $this->assertSame(2, $minusTwoData['success']);
        $this->assertSame(1, $minusTwoData['failed']);
        $this->assertSame(3, $minusTwoData['total']);

        /** @var array{success:int,failed:int,total:int}|null $minusOneData */
        $minusOneData = $daily->get($dayMinusOne);
        $this->assertNotNull($minusOneData);
        $this->assertSame(1, $minusOneData['success']);
        $this->assertSame(0, $minusOneData['failed']);
        $this->assertSame(1, $minusOneData['total']);

        /** @var array{success:int,failed:int,total:int}|null $dayZeroData */
        $dayZeroData = $daily->get($dayZero);
        $this->assertNotNull($dayZeroData);
        $this->assertSame(0, $dayZeroData['success']);
        $this->assertSame(2, $dayZeroData['failed']);
        $this->assertSame(2, $dayZeroData['total']);
    }

    private function logAuth(string $action, CarbonImmutable $when): void
    {
        AuditEvent::query()->create([
            'id' => Str::ulid()->toBase32(),
            'occurred_at' => $when,
            'actor_id' => 1,
            'action' => $action,
            'category' => 'AUTH',
            'entity_type' => 'core.auth',
            'entity_id' => 'login',
            'ip' => '127.0.0.1',
            'ua' => 'phpunit',
            'meta' => ['seed' => true],
            'created_at' => $when,
        ]);
    }
}
