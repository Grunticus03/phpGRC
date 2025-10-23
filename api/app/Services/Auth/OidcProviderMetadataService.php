<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Models\IdpProvider;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * Provides cached access to OIDC discovery metadata and provider configuration.
 */
final class OidcProviderMetadataService
{
    private const int DISCOVERY_CACHE_TTL = 3600;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CacheRepository $cache,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @return array<string,mixed>
     */
    public function readConfig(IdpProvider $provider): array
    {
        /** @var mixed $configValue */
        $configValue = $provider->getAttribute('config');

        if ($configValue === null) {
            return [];
        }

        if ($configValue instanceof \ArrayObject) {
            /** @var array<string,mixed> $copy */
            $copy = $configValue->getArrayCopy();

            return $copy;
        }

        if ($configValue instanceof \Traversable) {
            /** @var array<string,mixed> $array */
            $array = iterator_to_array($configValue);

            return $array;
        }

        if (is_array($configValue)) {
            /** @var array<string,mixed> $configValue */
            return $configValue;
        }

        /** @var array<string,mixed> $cast */
        $cast = (array) $configValue;

        return $cast;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    public function discovery(IdpProvider $provider, array $config): array
    {
        $issuer = $config['issuer'] ?? null;
        if (! is_string($issuer) || trim($issuer) === '') {
            throw new RuntimeException('Provider issuer is not configured.');
        }

        $cacheKey = sprintf('idp:%s:oidc:discovery', $provider->id);

        /** @var array<string,mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && isset($cached['_cached_at'])) {
            return $cached;
        }

        $endpoint = rtrim($issuer, '/').'/.well-known/openid-configuration';

        try {
            $response = $this->http->request('GET', $endpoint, [
                'headers' => ['Accept' => 'application/json'],
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('OIDC discovery fetch failed.', [
                'provider' => $provider->id,
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);
            throw new RuntimeException('Unable to retrieve discovery document.');
        }

        $body = (string) $response->getBody();
        /** @var mixed $decoded */
        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Discovery document response is invalid.');
        }

        /** @var array<string,mixed> $document */
        $document = $decoded;
        $document['_cached_at'] = time();

        $this->cache->put($cacheKey, $document, self::DISCOVERY_CACHE_TTL);

        return $document;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    public function authorizationEndpoint(IdpProvider $provider, array $config): string
    {
        $document = $this->discovery($provider, $config);

        $authEndpoint = $document['authorization_endpoint'] ?? null;
        if (! is_string($authEndpoint) || trim($authEndpoint) === '') {
            throw new RuntimeException('Discovery document missing authorization_endpoint.');
        }

        return trim($authEndpoint);
    }
}
