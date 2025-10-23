<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\IdpProvider;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;

/**
 * Persists short-lived OIDC authorization state for PKCE/nonce validation.
 */
final class OidcStateStore
{
    private const string CACHE_PREFIX = 'idp:oidc:state:';

    private const int STATE_TTL_SECONDS = 600;

    public function __construct(private readonly CacheRepository $cache) {}

    public function issue(IdpProvider $provider, string $redirectUri, string $codeVerifier, string $nonce, Request $request): string
    {
        $state = bin2hex(random_bytes(20));

        $payload = [
            'provider_id' => $provider->id,
            'provider_key' => $provider->key,
            'redirect_uri' => $redirectUri,
            'code_verifier' => $codeVerifier,
            'nonce' => $nonce,
            'ip' => (string) $request->ip(),
            'ua' => (string) $request->userAgent(),
            'issued_at' => time(),
        ];

        $this->cache->put(
            $this->cacheKey($state),
            $payload,
            self::STATE_TTL_SECONDS
        );

        return $state;
    }

    /**
     * @return array<string,string>|null
     */
    public function consume(string $state, Request $request): ?array
    {
        /** @var array<string,mixed>|null $stored */
        $stored = $this->cache->pull($this->cacheKey($state));
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

    private function cacheKey(string $state): string
    {
        return self::CACHE_PREFIX.$state;
    }
}
