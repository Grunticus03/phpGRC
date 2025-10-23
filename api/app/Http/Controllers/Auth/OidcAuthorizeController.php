<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\IdpProvider;
use App\Services\Auth\IdpProviderService;
use App\Services\Auth\OidcProviderMetadataService;
use App\Services\Auth\OidcStateStore;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

final class OidcAuthorizeController extends Controller
{
    public function __construct(
        private readonly IdpProviderService $providers,
        private readonly OidcProviderMetadataService $metadata,
        private readonly OidcStateStore $stateStore
    ) {}

    /**
     * @SuppressWarnings("PMD.NPathComplexity")
     */
    public function redirect(Request $request): RedirectResponse|JsonResponse
    {
        if (! $this->providers->persistenceAvailable()) {
            return $this->errorResponse(409, 'IDP_PERSISTENCE_DISABLED');
        }

        $providerValue = $request->query('provider');
        if (! is_string($providerValue)) {
            $providerValue = '';
        }

        $providerIdentifier = trim($providerValue);
        if ($providerIdentifier === '' || strlen($providerIdentifier) > 160) {
            return $this->errorResponse(422, 'IDP_PROVIDER_INVALID', [
                'fields' => ['provider'],
            ]);
        }

        $provider = $this->providers->findByIdOrKey($providerIdentifier);
        if (! $provider instanceof IdpProvider) {
            return $this->errorResponse(404, 'IDP_PROVIDER_NOT_FOUND', [
                'provider' => $providerIdentifier,
            ]);
        }

        if (! $provider->enabled) {
            return $this->errorResponse(403, 'IDP_PROVIDER_DISABLED');
        }

        $driver = strtolower($provider->driver);
        if (! in_array($driver, ['oidc', 'entra'], true)) {
            return $this->errorResponse(422, 'IDP_PROVIDER_UNSUPPORTED');
        }

        /** @var array<string,mixed> $config */
        $config = $this->metadata->readConfig($provider);

        $redirectUri = $this->resolveRedirectUri($config, $request);
        $scope = $this->buildScope($config);

        try {
            $authEndpoint = $this->metadata->authorizationEndpoint($provider, $config);
        } catch (RuntimeException $e) {
            return $this->errorResponse(502, 'IDP_OIDC_DISCOVERY_FAILED', [
                'message' => $e->getMessage(),
            ]);
        }

        $codeVerifier = $this->generateCodeVerifier();
        $codeChallenge = $this->generateCodeChallenge($codeVerifier);
        $nonce = $this->generateNonce();

        $state = $this->stateStore->issue($provider, $redirectUri, $codeVerifier, $nonce, $request);

        $params = [
            'client_id' => $config['client_id'] ?? null,
            'redirect_uri' => $redirectUri,
            'response_type' => 'code',
            'scope' => $scope,
            'state' => $state,
            'code_challenge' => $codeChallenge,
            'code_challenge_method' => 'S256',
            'nonce' => $nonce,
        ];

        $filteredParams = array_filter($params, static fn ($value) => $value !== null && $value !== '');

        $delimiter = str_contains($authEndpoint, '?') ? '&' : '?';
        $location = $authEndpoint.$delimiter.http_build_query($filteredParams, '', '&', PHP_QUERY_RFC3986);

        if ($request->wantsJson()) {
            return response()->json([
                'ok' => true,
                'redirect' => $location,
            ]);
        }

        return redirect()->away($location);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function resolveRedirectUri(array $config, Request $request): string
    {
        /** @var mixed $redirectsRaw */
        $redirectsRaw = $config['redirect_uris'] ?? null;
        $configured = is_array($redirectsRaw) ? $redirectsRaw : null;

        if ($configured !== null) {
            foreach ($configured as $entry) {
                if (! is_string($entry)) {
                    continue;
                }

                $trimmed = trim($entry);
                if ($trimmed !== '') {
                    return $trimmed;
                }
            }
        }

        $origin = $request->getSchemeAndHttpHost();
        $normalizedOrigin = rtrim($origin, '/');

        return $normalizedOrigin.'/auth/callback';
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function buildScope(array $config): string
    {
        $scopes = $config['scopes'] ?? null;
        if (! is_array($scopes) || $scopes === []) {
            return 'openid';
        }

        $tokens = [];
        foreach ($scopes as $scope) {
            if (! is_string($scope)) {
                continue;
            }
            $normalized = trim($scope);
            if ($normalized === '') {
                continue;
            }
            $tokens[$normalized] = true;
        }

        if ($tokens === []) {
            return 'openid';
        }

        return implode(' ', array_keys($tokens));
    }

    private function generateCodeVerifier(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $verifier): string
    {
        return rtrim(strtr(base64_encode(hash('sha256', $verifier, true)), '+/', '-_'), '=');
    }

    private function generateNonce(): string
    {
        return bin2hex(random_bytes(16));
    }

    /**
     * @param  array<string,mixed>  $meta
     */
    private function errorResponse(int $status, string $code, array $meta = []): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'code' => $code,
            'meta' => $meta,
        ], $status);
    }
}
