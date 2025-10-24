<?php

declare(strict_types=1);

namespace Tests\Unit\Auth;

use App\Models\IdpProvider;
use App\Services\Auth\SamlStateTokenFactory;
use App\Services\Auth\SamlStateTokenSigner;
use Carbon\Carbon;
use Carbon\CarbonImmutable;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;
use UnexpectedValueException;

final class SamlStateTokenFactoryTest extends TestCase
{
    private CacheRepository $cache;

    private SamlStateTokenSigner $signer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->cache = new CacheRepository(new ArrayStore);
        $this->signer = new SamlStateTokenSigner(
            issuer: 'phpgrc.saml.state',
            audience: 'https://phpgrc.example.test/auth/saml/acs',
            primarySecret: 'base64:'.base64_encode(random_bytes(32)),
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_issue_and_validate_roundtrip(): void
    {
        $factory = $this->factory(ttlSeconds: 300, skewSeconds: 30, enforceClientHash: true);
        $provider = $this->provider();
        $request = $this->request('198.51.100.10', 'UnitTest/1.0');

        Carbon::setTestNow(CarbonImmutable::create(2025, 1, 1, 0, 0, 0));
        $issued = $factory->issue($provider, '_req123', '/dashboard', $request);

        $this->assertNotNull($issued->token);
        $this->assertSame('_req123', $issued->requestId);

        $validated = $factory->validate($issued->token, $request);
        $this->assertSame($issued->requestId, $validated->requestId);
        $this->assertSame($issued->providerId, $validated->providerId);
        $this->assertSame('/dashboard', $validated->intendedPath);
    }

    public function test_rejects_replay(): void
    {
        $factory = $this->factory();
        $provider = $this->provider();
        $request = $this->request('198.51.100.10', 'UnitTest/1.0');

        Carbon::setTestNow(CarbonImmutable::create(2025, 1, 1, 0, 0, 0));
        $issued = $factory->issue($provider, '_reqReplay', null, $request);

        $factory->validate($issued->token, $request); // first is ok

        $this->expectException(UnexpectedValueException::class);
        $factory->validate($issued->token, $request);
    }

    public function test_rejects_expired_token(): void
    {
        $factory = $this->factory(ttlSeconds: 60, skewSeconds: 5, enforceClientHash: false);
        $provider = $this->provider();
        $request = $this->request('198.51.100.10', 'UnitTest/1.0');

        Carbon::setTestNow(CarbonImmutable::create(2025, 1, 1, 0, 0, 0));
        $issued = $factory->issue($provider, '_reqExpired', null, $request);

        Carbon::setTestNow(CarbonImmutable::create(2025, 1, 1, 0, 2, 0));
        $this->expectException(UnexpectedValueException::class);
        $factory->validate($issued->token, $request);
    }

    private function factory(int $ttlSeconds = 300, int $skewSeconds = 30, bool $enforceClientHash = true): SamlStateTokenFactory
    {
        return new SamlStateTokenFactory(
            cache: $this->cache,
            signer: $this->signer,
            ttlSeconds: $ttlSeconds,
            clockSkewSeconds: $skewSeconds,
            enforceClientHash: $enforceClientHash,
        );
    }

    private function provider(): IdpProvider
    {
        $provider = new IdpProvider;
        $provider->id = '01KABCDEF1234567890ABCDE12';
        $provider->key = 'saml-test';

        return $provider;
    }

    private function request(string $ip, string $userAgent): Request
    {
        $server = [
            'REMOTE_ADDR' => $ip,
            'HTTP_USER_AGENT' => $userAgent,
        ];

        return Request::create('/', 'GET', server: $server);
    }
}
