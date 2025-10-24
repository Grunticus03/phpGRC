<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\IdpProvider;
use App\ValueObjects\Auth\SamlStateDescriptor;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use UnexpectedValueException;

final class SamlStateTokenFactory
{
    private const string CACHE_PREFIX = 'saml:state:';

    private const int TOKEN_VERSION = 1;

    public function __construct(
        private readonly CacheRepository $cache,
        private readonly SamlStateTokenSigner $signer,
        private readonly int $ttlSeconds,
        private readonly int $clockSkewSeconds,
        private readonly bool $enforceClientHash
    ) {}

    public function issue(IdpProvider $provider, string $requestId, ?string $intendedPath, Request $request): SamlStateDescriptor
    {
        $descriptor = new SamlStateDescriptor(
            requestId: $requestId,
            providerId: $provider->id,
            providerKey: $provider->key,
            intendedPath: $this->sanitizeIntendedPath($intendedPath),
            issuedAt: now()->getTimestamp(),
            clientHash: $this->clientHash($request, SamlStateTokenSigner::KEY_PRIMARY),
            issuer: $this->signer->issuer(),
            audience: $this->signer->audience(),
            version: self::TOKEN_VERSION,
        );

        $token = $this->signer->sign($descriptor);
        $descriptor = $descriptor->withToken($token, SamlStateTokenSigner::KEY_PRIMARY);

        $this->cache->put($this->cacheKey($requestId), 'pending', $this->ttlSeconds);

        return $descriptor;
    }

    public function validate(string $token, Request $request): SamlStateDescriptor
    {
        $descriptor = $this->signer->verify($token);

        $this->assertVersion($descriptor->version);
        $this->assertTimestamps($descriptor->issuedAt);
        $this->assertReplay($descriptor->requestId);
        $this->assertClientFingerprint($descriptor, $request);

        return $descriptor;
    }

    private function assertVersion(int $version): void
    {
        if ($version !== self::TOKEN_VERSION) {
            throw new UnexpectedValueException('Unsupported SAML state token version.');
        }
    }

    private function assertTimestamps(int $issuedAt): void
    {
        $now = now()->getTimestamp();

        if ($issuedAt > $now + $this->clockSkewSeconds) {
            throw new UnexpectedValueException('SAML state token issued in the future.');
        }

        if ($issuedAt + $this->ttlSeconds + $this->clockSkewSeconds < $now) {
            throw new UnexpectedValueException('SAML state token has expired.');
        }
    }

    private function assertReplay(string $requestId): void
    {
        $key = $this->cacheKey($requestId);
        /** @var null|string $state */
        $state = $this->cache->get($key);
        if ($state === null) {
            throw new UnexpectedValueException('SAML state token not recognized.');
        }

        if ($state === 'consumed') {
            throw new UnexpectedValueException('SAML state token already consumed.');
        }

        $this->cache->put($key, 'consumed', $this->ttlSeconds);
    }

    private function assertClientFingerprint(SamlStateDescriptor $descriptor, Request $request): void
    {
        if (! $this->enforceClientHash) {
            return;
        }

        if ($descriptor->clientHash === null) {
            throw new UnexpectedValueException('SAML state token missing client fingerprint.');
        }

        $raw = $this->rawFingerprint($request);
        if ($raw === null) {
            throw new UnexpectedValueException('Unable to derive client fingerprint.');
        }

        $expected = $this->signer->hashClientFingerprint($raw, $descriptor->signatureKey ?? SamlStateTokenSigner::KEY_PRIMARY);
        if (! hash_equals($descriptor->clientHash, $expected)) {
            throw new UnexpectedValueException('SAML state token fingerprint mismatch.');
        }
    }

    private function sanitizeIntendedPath(?string $intendedPath): ?string
    {
        if ($intendedPath === null) {
            return null;
        }

        $trimmed = trim($intendedPath);
        if ($trimmed === '' || ! Str::startsWith($trimmed, '/')) {
            return null;
        }

        if (Str::startsWith($trimmed, '//')) {
            return null;
        }

        return $trimmed;
    }

    private function clientHash(Request $request, string $keyId): ?string
    {
        if (! $this->enforceClientHash) {
            return null;
        }

        $raw = $this->rawFingerprint($request);
        if ($raw === null) {
            return null;
        }

        return $this->signer->hashClientFingerprint($raw, $keyId);
    }

    private function rawFingerprint(Request $request): ?string
    {
        $ip = $request->ip();
        $ua = $request->userAgent();
        if ($ip === null || $ua === null) {
            return null;
        }

        return $ip.'|'.$ua;
    }

    private function cacheKey(string $requestId): string
    {
        return self::CACHE_PREFIX.$requestId;
    }
}
