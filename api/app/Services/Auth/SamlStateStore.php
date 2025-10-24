<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\IdpProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;

/**
 * Persists short-lived SAML state between AuthnRequest and ACS handling.
 */
final class SamlStateStore
{
    private const string CACHE_PREFIX = 'idp:saml:state:';

    private const int STATE_TTL_SECONDS = 600;

    public function __construct(private readonly CacheRepository $cache) {}

    public function issue(IdpProvider $provider, string $requestId, ?string $intendedPath, Request $request): string
    {
        $token = bin2hex(random_bytes(20));

        $payload = [
            'provider_id' => $provider->id,
            'provider_key' => $provider->key,
            'request_id' => $requestId,
            'intended' => $intendedPath,
            'ip' => (string) $request->ip(),
            'ua' => (string) $request->userAgent(),
            'issued_at' => time(),
        ];

        $this->cache->put($this->cacheKey($token), $payload, self::STATE_TTL_SECONDS);

        return $token;
    }

    /**
     * @return array<string,string>|null
     */
    public function consume(string $token, Request $request): ?array
    {
        /** @var array<string,mixed>|null $stored */
        $stored = $this->cache->pull($this->cacheKey($token));
        if (! is_array($stored) || $stored === []) {
            return null;
        }

        $ip = (string) $request->ip();
        $ua = (string) $request->userAgent();

        if (($stored['ip'] ?? null) !== $ip || ($stored['ua'] ?? null) !== $ua) {
            return null;
        }

        /** @var array<string,string> $normalized */
        $normalized = [];
        foreach ($stored as $key => $value) {
            if (! is_string($value)) {
                continue;
            }

            $normalized[$key] = $value;
        }

        return $normalized;
    }

    private function cacheKey(string $token): string
    {
        return self::CACHE_PREFIX.$token;
    }
}
