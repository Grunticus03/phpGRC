<?php
declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\AuditEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Cookie;
use Tests\TestCase;

final class BruteForceGuardTest extends TestCase
{
    use RefreshDatabase;

    public function test_session_strategy_locks_after_max_attempts(): void
    {
        $this->withExceptionHandling();

        config([
            'cache.default'                         => 'file',
            'core.audit.enabled'                    => true,
            'core.auth.bruteforce.enabled'          => true,
            'core.auth.bruteforce.strategy'         => 'session',
            'core.auth.bruteforce.window_seconds'   => 900,
            'core.auth.bruteforce.max_attempts'     => 3,
            'core.auth.bruteforce.lock_http_status' => 429,
            'core.auth.session_cookie.name'         => 'phpgrc_auth_attempt',
        ]);
        Cache::setDefaultDriver('file');
        Cache::store('file')->flush();

        $name = (string) config('core.auth.session_cookie.name');

        // 1) No client cookie: guard issues one, controller returns 401 on bad creds
        $r1 = $this->postJson('/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(401);
        $issued = $this->getCookieFromResponse($r1, $name);
        $this->assertNotNull($issued, 'Guard did not set session cookie');
        /** @var string $cookieVal */
        $cookieVal = $issued->getValue();

        // 2) Reuse server-issued cookie, still below threshold -> 401
        $this->withCookie($name, $cookieVal)
            ->postJson('/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(401);

        // 3) Third attempt must lock -> 429 with Retry-After
        $r3 = $this->withCookie($name, $cookieVal)
            ->postJson('/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $r3->assertStatus(429);
        $retryHeader = (string) ($r3->headers->get('Retry-After') ?? '0');
        $this->assertNotSame('0', $retryHeader);

        $locked = AuditEvent::query()
            ->where('category', 'AUTH')
            ->where('action', 'auth.login.locked')
            ->count();
        $failed = AuditEvent::query()
            ->where('category', 'AUTH')
            ->where('action', 'auth.login.failed')
            ->count();

        // Guard logs 'failed' each time below threshold; controller logs failed too on invalid creds.
        $this->assertSame(1, $locked);
        $this->assertSame(4, $failed);
    }

    public function test_ip_strategy_locks_after_max_attempts(): void
    {
        $this->withExceptionHandling();

        config([
            'cache.default'                         => 'file',
            'core.audit.enabled'                    => true,
            'core.auth.bruteforce.enabled'          => true,
            'core.auth.bruteforce.strategy'         => 'ip',
            'core.auth.bruteforce.window_seconds'   => 900,
            'core.auth.bruteforce.max_attempts'     => 2,
            'core.auth.bruteforce.lock_http_status' => 429,
        ]);
        Cache::setDefaultDriver('file');
        Cache::store('file')->flush();

        // 1) First bad attempt -> 401
        $this->postJson('/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong'])
            ->assertStatus(401);

        // 2) Second bad attempt -> lock 429
        $r2 = $this->postJson('/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong']);
        $r2->assertStatus(429);
        $retryHeader = (string) ($r2->headers->get('Retry-After') ?? '0');
        $this->assertNotSame('0', $retryHeader);

        $locked = AuditEvent::query()
            ->where('category', 'AUTH')
            ->where('action', 'auth.login.locked')
            ->count();
        $failed = AuditEvent::query()
            ->where('category', 'AUTH')
            ->where('action', 'auth.login.failed')
            ->count();

        $this->assertSame(1, $locked);
        $this->assertSame(2, $failed);
    }

    private function getCookieFromResponse(\Illuminate\Testing\TestResponse $resp, string $name): ?Cookie
    {
        foreach ($resp->baseResponse->headers->getCookies() as $c) {
            if ($c->getName() === $name) {
                return $c;
            }
        }
        return null;
    }
}
