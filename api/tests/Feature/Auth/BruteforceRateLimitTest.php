<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

final class BruteforceRateLimitTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withExceptionHandling();

        config([
            'core.audit.enabled'                    => true,
            'core.auth.bruteforce.enabled'          => true,
            'core.auth.bruteforce.max_attempts'     => 3,
            'core.auth.bruteforce.window_seconds'   => 5,
            'core.auth.bruteforce.lock_http_status' => 429,
            'core.auth.bruteforce.strategy'         => 'session',
            'core.auth.session_cookie.name'         => 'phpgrc_auth_attempt',
        ]);

        Cache::store('array')->flush();
        try { Cache::store('file')->flush(); } catch (\Throwable) {}
        DB::table('audit_events')->truncate();
    }

    public function test_session_strategy_locks_then_unlocks_and_sets_retry_after(): void
    {
        $cookieName = (string) config('core.auth.session_cookie.name');
        $cookieVal  = 'session-abc';

        $t0 = CarbonImmutable::create(2025, 9, 25, 6, 0, 0, 'UTC');
        CarbonImmutable::setTestNow($t0);

        $this->withCookie($cookieName, $cookieVal)->postJson('/auth/login', [])->assertStatus(200);
        $this->withCookie($cookieName, $cookieVal)->postJson('/auth/login', [])->assertStatus(200);

        $r3 = $this->withCookie($cookieName, $cookieVal)->postJson('/auth/login', []);
        $r3->assertStatus(429);
        $retryHeader = $r3->headers->get('Retry-After'); // ?string
        $retry = (int) ($retryHeader ?? '0');
        $this->assertGreaterThanOrEqual(1, $retry);
        $this->assertLessThanOrEqual(5, $retry);

        $failed = DB::table('audit_events')->where('action', 'auth.login.failed')->count();
        $locked = DB::table('audit_events')->where('action', 'auth.login.locked')->count();
        $this->assertSame(2, $failed);
        $this->assertSame(1, $locked);

        CarbonImmutable::setTestNow($t0->addSeconds(6));
        $this->withCookie($cookieName, $cookieVal)->postJson('/auth/login', [])->assertStatus(200);
    }

    public function test_ip_strategy_counts_per_ip_and_uses_retry_after(): void
    {
        config([
            'core.auth.bruteforce.strategy'      => 'ip',
            'core.auth.bruteforce.max_attempts'  => 2,
            'core.auth.bruteforce.window_seconds'=> 2,
        ]);

        $ip1 = ['REMOTE_ADDR' => '203.0.113.10'];
        $ip2 = ['REMOTE_ADDR' => '203.0.113.20'];

        $this->withServerVariables($ip1)->postJson('/auth/login', [])->assertStatus(200);

        $lock = $this->withServerVariables($ip1)->postJson('/auth/login', []);
        $lock->assertStatus(429);
        $this->assertNotSame('', (string) ($lock->headers->get('Retry-After') ?? ''));

        $this->withServerVariables($ip2)->postJson('/auth/login', [])->assertStatus(200);

        CarbonImmutable::setTestNow(CarbonImmutable::now('UTC')->addSeconds(3));
        $this->withServerVariables($ip1)->postJson('/auth/login', [])->assertStatus(200);
    }
}
