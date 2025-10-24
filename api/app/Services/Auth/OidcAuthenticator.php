<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Auth\OidcAuthenticatorContract;
use App\Exceptions\Auth\OidcAuthenticationException;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Concerns\ResolvesJitAssignments;
use Firebase\JWT\JWK;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class OidcAuthenticator implements OidcAuthenticatorContract
{
    use ResolvesJitAssignments;

    private const JWKS_CACHE_TTL = 900;

    public function __construct(
        private readonly ClientInterface $http,
        private readonly CacheRepository $cache,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly OidcProviderMetadataService $metadata
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    #[\Override]
    public function authenticate(IdpProvider $provider, array $input, Request $request): User
    {
        /** @var array<string,mixed> $config */
        $config = $this->metadata->readConfig($provider);
        $driverKey = strtolower($provider->driver);

        if (! in_array($driverKey, ['oidc', 'entra'], true)) {
            throw ValidationException::withMessages([
                'provider' => ['Provider driver does not support OIDC flows.'],
            ])->status(422);
        }

        $nonce = null;
        if (isset($input['nonce']) && is_string($input['nonce'])) {
            $nonceCandidate = trim($input['nonce']);
            if ($nonceCandidate !== '') {
                $nonce = $nonceCandidate;
            }
        }

        try {
            $discovery = $this->metadata->discovery($provider, $config);

            $idToken = $this->resolveIdToken($provider, $config, $discovery, $input);
            $claims = $this->validateIdToken($provider, $config, $discovery, $idToken, $nonce);

            $user = $this->provisionUser($config, $claims);

            $entityId = trim($provider->id);
            if ($entityId === '') {
                $entityId = trim($provider->key);
            }
            if ($entityId === '') {
                throw new RuntimeException('Provider identifier missing.');
            }

            $this->audit->log([
                'actor_id' => $user->id,
                'action' => 'auth.oidc.login',
                'category' => 'AUTH',
                'entity_type' => 'idp.provider',
                'entity_id' => $entityId,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => [
                    'provider_key' => $provider->key,
                    'issuer' => $config['issuer'] ?? null,
                    'subject' => $claims['sub'] ?? null,
                    'email' => $claims['email'] ?? null,
                ],
            ]);

            return $user;
        } catch (ValidationException $e) {
            /** @var array<string,mixed> $errors */
            $errors = $e->errors();
            $this->logFailure($provider, $request, $config, $errors);
            throw $e;
        } catch (OidcAuthenticationException $e) {
            $details = ['message' => [$e->getMessage()]];
            $this->logFailure($provider, $request, $config, $details);
            throw ValidationException::withMessages([
                'code' => [$e->getMessage()],
            ])->status(401);
        } catch (RuntimeException $e) {
            $details = ['message' => [$e->getMessage()]];
            $this->logFailure($provider, $request, $config, $details);
            throw ValidationException::withMessages([
                'code' => [$e->getMessage()],
            ])->status(401);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $discovery
     * @param  array<string,mixed>  $input
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     * @SuppressWarnings("PMD.ExcessiveMethodLength")
     */
    private function resolveIdToken(IdpProvider $provider, array $config, array $discovery, array $input): string
    {
        if (isset($input['id_token']) && is_string($input['id_token'])) {
            $idTokenCandidate = trim($input['id_token']);
            if ($idTokenCandidate !== '') {
                return $idTokenCandidate;
            }
        }

        if (! (isset($input['code']) && is_string($input['code']) && trim($input['code']) !== '')) {
            throw ValidationException::withMessages([
                'code' => ['Authorization code is required.'],
            ])->status(422);
        }
        $code = trim($input['code']);

        if (! (isset($input['redirect_uri']) && is_string($input['redirect_uri']) && trim($input['redirect_uri']) !== '')) {
            throw ValidationException::withMessages([
                'redirect_uri' => ['Redirect URI is required when exchanging a code.'],
            ])->status(422);
        }
        $redirectUri = trim($input['redirect_uri']);

        $tokenEndpoint = $discovery['token_endpoint'] ?? null;
        if (! is_string($tokenEndpoint) || trim($tokenEndpoint) === '') {
            throw new RuntimeException('Discovery document missing token endpoint.');
        }

        $clientIdRaw = $config['client_id'] ?? null;
        if (! is_string($clientIdRaw) || trim($clientIdRaw) === '') {
            throw new RuntimeException('Provider client_id is not configured.');
        }
        $clientId = trim($clientIdRaw);

        $clientSecretRaw = $config['client_secret'] ?? null;
        if (! is_string($clientSecretRaw) || trim($clientSecretRaw) === '') {
            throw new RuntimeException('Provider client_secret is not configured.');
        }
        $clientSecret = trim($clientSecretRaw);

        $form = [
            'grant_type' => 'authorization_code',
            'code' => $code,
            'redirect_uri' => $redirectUri,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ];

        $scopes = [];
        if (isset($config['scopes']) && is_array($config['scopes'])) {
            foreach ($config['scopes'] as $scope) {
                if (! is_string($scope)) {
                    continue;
                }

                $trimmed = trim($scope);
                if ($trimmed === '') {
                    continue;
                }

                $scopes[$trimmed] = true;
            }
        }

        if (! isset($scopes['openid'])) {
            $scopes['openid'] = true;
        }

        $form['scope'] = implode(' ', array_keys($scopes));

        if (isset($input['code_verifier']) && is_string($input['code_verifier'])) {
            $form['code_verifier'] = trim($input['code_verifier']);
        }

        try {
            $response = $this->http->request('POST', $tokenEndpoint, [
                'headers' => ['Accept' => 'application/json'],
                'form_params' => $form,
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('OIDC token exchange failed.', ['provider' => $provider->id, 'error' => $e->getMessage()]);
            throw new OidcAuthenticationException('Token exchange failed.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new OidcAuthenticationException('Token response malformed.');
        }

        $idTokenResponse = $decoded['id_token'] ?? null;
        if (! is_string($idTokenResponse) || trim($idTokenResponse) === '') {
            $description = null;
            if (isset($decoded['error_description']) && is_string($decoded['error_description'])) {
                $candidate = trim($decoded['error_description']);
                if ($candidate !== '') {
                    $description = $candidate;
                }
            }

            if ($description === null && isset($decoded['error']) && is_string($decoded['error'])) {
                $candidate = trim($decoded['error']);
                if ($candidate !== '') {
                    $description = $candidate;
                }
            }

            $message = $description ?? 'Provider did not return an id_token.';

            throw new OidcAuthenticationException($message);
        }

        return trim($idTokenResponse);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $discovery
     *
     * @SuppressWarnings("PMD.NPathComplexity")
     * @SuppressWarnings("PMD.StaticAccess")
     *
     * @return array<string,mixed>
     */
    private function validateIdToken(IdpProvider $provider, array $config, array $discovery, string $idToken, ?string $nonce): array
    {
        $jwksUri = $discovery['jwks_uri'] ?? null;
        if (! is_string($jwksUri) || trim($jwksUri) === '') {
            throw new RuntimeException('Discovery document missing jwks_uri.');
        }

        $keys = $this->retrieveJwks($provider, $jwksUri);
        if ($keys === []) {
            throw new RuntimeException('Provider JWKS is empty.');
        }

        try {
            $decoded = JWT::decode($idToken, $keys);
        } catch (\Throwable $e) {
            $this->logger->warning('ID token validation error.', ['provider' => $provider->id, 'error' => $e->getMessage()]);
            throw new OidcAuthenticationException('Unable to validate ID token.');
        }

        /** @var array<string,mixed> $claims */
        $claims = json_decode(json_encode($decoded, JSON_THROW_ON_ERROR), true, 512, JSON_THROW_ON_ERROR);

        $issuer = $config['issuer'] ?? null;
        if (! is_string($issuer) || trim($issuer) === '') {
            throw new RuntimeException('Provider issuer is not configured.');
        }

        $issClaim = $claims['iss'] ?? null;
        if (! is_string($issClaim) || trim($issClaim) === '' || $issClaim !== $issuer) {
            throw new OidcAuthenticationException('Issuer mismatch.');
        }

        $clientId = $config['client_id'] ?? null;
        if (! is_string($clientId) || trim($clientId) === '') {
            throw new RuntimeException('Provider client_id is not configured.');
        }
        /** @var array<array-key, mixed>|string|null $audClaim */
        $audClaim = $claims['aud'] ?? null;
        $audValid = false;
        if (is_array($audClaim)) {
            $audValid = in_array($clientId, $audClaim, true);
        } elseif (is_string($audClaim)) {
            $audValid = $audClaim === $clientId;
        }
        if (! $audValid) {
            throw new OidcAuthenticationException('Audience mismatch.');
        }

        $expiresAt = 0;
        if (isset($claims['exp']) && is_numeric($claims['exp'])) {
            $expiresAt = (int) $claims['exp'];
        }
        if ($expiresAt !== 0 && $expiresAt < (time() - 60)) {
            throw new OidcAuthenticationException('ID token expired.');
        }

        if ($nonce !== null && $nonce !== '') {
            if (! (isset($claims['nonce']) && is_string($claims['nonce']) && $claims['nonce'] === $nonce)) {
                throw new OidcAuthenticationException('Nonce mismatch.');
            }
        }

        return $claims;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $claims
     *
     * @SuppressWarnings("PMD.StaticAccess")
     * @SuppressWarnings("PMD.ElseExpression")
     */
    private function provisionUser(array $config, array $claims): User
    {
        $jit = $this->resolveJitSettings($config);

        $email = $this->extractEmail($claims);
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => ['OIDC response missing email claim.'],
            ])->status(422);
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();

        $createUsers = $jit['create_users'];

        if (! $user instanceof User) {
            if (! $createUsers) {
                throw ValidationException::withMessages([
                    'email' => ['User does not exist and automatic provisioning is disabled.'],
                ])->status(422);
            }

            $user = User::create([
                'name' => $this->resolveName($claims, $email),
                'email' => $email,
                'password' => Hash::make(Str::uuid()->toString()),
            ]);
        } else {
            $this->updateUserName($user, $claims);
        }

        $roles = $this->resolveRoles($jit, fn (string $claim): mixed => $this->extractClaimValue($claims, $claim));
        if ($roles !== []) {
            $existingRoleIds = Role::query()
                ->whereIn('id', $roles)
                ->pluck('id')
                ->all();

            if ($existingRoleIds !== []) {
                /** @var list<string> $existingRoleIds */
                $user->roles()->syncWithoutDetaching($existingRoleIds);
            }
        }

        return $user;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{create_users:bool,default_roles:list<string>,role_templates:list<array{claim:string,values:list<string>,roles:list<string>}>}
     */
    /**
     * @param  array<string,mixed>  $claims
     */
    private function extractClaimValue(array $claims, string $path): mixed
    {
        return data_get($claims, $path);
    }

    /**
     * @param  list<string>  $expectedValues
     */
    /**
     * @param  array<string,mixed>  $claims
     */
    private function extractEmail(array $claims): string
    {
        $candidateKeys = [
            'email',
            'preferred_username',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            'upn',
            'unique_name',
        ];

        foreach ($candidateKeys as $key) {
            $candidate = $claims[$key] ?? null;
            if (! is_string($candidate)) {
                continue;
            }

            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            if (in_array($key, ['upn', 'unique_name'], true) && ! str_contains($trimmed, '@')) {
                continue;
            }

            return mb_strtolower($trimmed);
        }

        try {
            $this->logger->notice('OIDC email claim not found in ID token.', [
                'issuer' => $claims['iss'] ?? null,
                'subject' => $claims['sub'] ?? null,
                'available_claims' => array_keys($claims),
            ]);
        } catch (\Throwable $e) {
            // Logging should never interfere with authentication flow.
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function resolveName(array $claims, string $fallbackEmail): string
    {
        $display = $this->firstNonEmptyString($claims, [
            'name',
            'http://schemas.microsoft.com/identity/claims/displayname',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/name',
        ]);
        if ($display !== null) {
            return $display;
        }

        $given = $this->firstNonEmptyString($claims, [
            'given_name',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/givenname',
        ]);
        $family = $this->firstNonEmptyString($claims, [
            'family_name',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/surname',
        ]);

        if ($given !== null && $family !== null) {
            return trim($given).' '.trim($family);
        }

        if ($given !== null) {
            return $given;
        }

        if ($family !== null) {
            return $family;
        }

        $principal = $this->firstNonEmptyString($claims, ['unique_name', 'upn']);
        if ($principal !== null) {
            return $this->normalizePrincipal($principal);
        }

        try {
            $this->logger->notice('OIDC name claims missing; falling back to email.', [
                'issuer' => $claims['iss'] ?? null,
                'subject' => $claims['sub'] ?? null,
                'available_claims' => array_keys($claims),
            ]);
        } catch (\Throwable $e) {
            // Logging should never interfere with authentication flow.
        }

        return $fallbackEmail;
    }

    /**
     * @param  array<string,mixed>  $claims
     * @param  list<string>  $keys
     */
    private function firstNonEmptyString(array $claims, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $claims[$key] ?? null;
            if (! is_string($value)) {
                continue;
            }

            $trimmed = trim($value);
            if ($trimmed !== '') {
                return $trimmed;
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function updateUserName(User $user, array $claims): void
    {
        $resolved = trim($this->resolveName($claims, $user->email));
        $currentName = trim($user->name);

        if (strcasecmp($resolved, trim($user->email)) === 0) {
            return;
        }

        if ($currentName === $resolved) {
            return;
        }

        $user->name = $resolved;
        $user->save();
    }

    private function normalizePrincipal(string $principal): string
    {
        $normalized = trim($principal);
        if ($normalized === '') {
            return $normalized;
        }

        if (! str_contains($normalized, '@') && str_contains($normalized, '\\')) {
            $parts = array_filter(explode('\\', $normalized), static fn ($part): bool => $part !== '');
            if ($parts !== []) {
                /** @var list<string> $parts */
                $last = end($parts);
                if ($last !== false) {
                    return trim($last);
                }
            }
        }

        return $normalized;
    }

    /**
     * @return array<string, Key>
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function retrieveJwks(IdpProvider $provider, string $jwksUri): array
    {
        $cacheKey = sprintf('idp:%s:oidc:jwks', $provider->id);
        /** @var array<string,mixed>|null $cached */
        $cached = $this->cache->get($cacheKey);
        if (is_array($cached) && $cached !== []) {
            $normalizedCached = $this->normalizeJwksDocument($cached);
            /** @var array<string, Key> $parsedCached */
            $parsedCached = JWK::parseKeySet($normalizedCached);

            if ($parsedCached === []) {
                throw new RuntimeException('JWKS response malformed.');
            }

            return $parsedCached;
        }

        try {
            $response = $this->http->request('GET', $jwksUri, [
                'headers' => ['Accept' => 'application/json'],
                'connect_timeout' => 5,
                'timeout' => 10,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->error('Unable to fetch JWKS.', ['provider' => $provider->id, 'error' => $e->getMessage()]);
            throw new RuntimeException('Unable to fetch JWKS.');
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('JWKS response malformed.');
        }

        $normalized = $this->normalizeJwksDocument($decoded);

        $this->cache->put($cacheKey, $normalized, self::JWKS_CACHE_TTL);

        /** @var array<string, Key> $parsed */
        $parsed = JWK::parseKeySet($normalized);
        if ($parsed === []) {
            throw new RuntimeException('JWKS response malformed.');
        }

        return $parsed;
    }

    /**
     * @param  array<string,mixed>  $jwks
     * @return array<string,mixed>
     */
    /**
     * @param  array<mixed,mixed>  $jwks
     * @return array<string,mixed>
     */
    private function normalizeJwksDocument(array $jwks): array
    {
        /** @var array<string,mixed> $document */
        $document = $jwks;

        $rawKeys = $document['keys'] ?? null;
        if (! is_array($rawKeys)) {
            return $document;
        }

        $document['keys'] = $this->normalizeJwkList($rawKeys);

        return $document;
    }

    /**
     * @param  array<int|string,mixed>  $rawKeys
     * @return array<int|string,array<array-key,mixed>>
     */
    private function normalizeJwkList(array $rawKeys): array
    {
        $normalized = [];

        foreach ($rawKeys as $index => $key) {
            if (! is_array($key)) {
                continue;
            }

            /** @var mixed $algRaw */
            $algRaw = $key['alg'] ?? null;
            $alg = is_string($algRaw) ? trim($algRaw) : '';
            if ($alg === '') {
                /** @var mixed $ktyRaw */
                $ktyRaw = $key['kty'] ?? null;
                $kty = is_string($ktyRaw) ? strtoupper(trim($ktyRaw)) : '';
                if ($kty === 'RSA') {
                    $key['alg'] = 'RS256';
                }
            }

            /** @var array<array-key,mixed> $key */
            $normalized[$index] = $key;
        }

        return $normalized;
    }

    /**
     * @param  array<string,mixed>|null  $config
     * @param  array<string,mixed>|null  $errors
     */
    private function logFailure(IdpProvider $provider, Request $request, ?array $config, ?array $errors): void
    {
        $meta = [
            'provider_key' => $provider->key,
            'issuer' => $config['issuer'] ?? null,
        ];

        if (is_array($errors)) {
            $meta['validation'] = $errors;
        }

        $entityId = trim($provider->id);
        if ($entityId === '') {
            $entityId = trim($provider->key);
        }
        if ($entityId === '') {
            $entityId = 'idp';
        }

        $this->audit->log([
            'actor_id' => null,
            'action' => 'auth.oidc.login.failed',
            'category' => 'AUTH',
            'entity_type' => 'idp.provider',
            'entity_id' => $entityId,
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'meta' => $meta,
        ]);
    }
}
