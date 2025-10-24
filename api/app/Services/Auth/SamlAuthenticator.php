<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Auth\SamlAuthenticatorContract;
use App\Exceptions\Auth\SamlLibraryException;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Concerns\ResolvesJitAssignments;
use Illuminate\Contracts\Hashing\Hasher;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use OneLogin\Saml2\Auth as OneLoginAuth;
use Psr\Log\LoggerInterface;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class SamlAuthenticator implements SamlAuthenticatorContract
{
    use ResolvesJitAssignments;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger,
        private readonly SamlLibraryBridge $bridge,
        private readonly Hasher $hasher,
        private readonly User $userModel
    ) {}

    #[\Override]
    public function authenticate(IdpProvider $provider, array $input, Request $request): User
    {
        if (strtolower($provider->driver) !== 'saml') {
            throw ValidationException::withMessages([
                'provider' => ['Provider driver does not support SAML flows.'],
            ])->status(422);
        }

        /** @var array<string,mixed> $config */
        $config = (array) $provider->getAttribute('config');
        $expectedRequestId = $this->stringFromInput($input['request_id'] ?? null);

        try {
            $auth = $this->resolveLibraryAuth($provider, $config, $request, $expectedRequestId, $input);
            /** @var array<string,mixed> $claims */
            $claims = $this->buildClaimsFromLibrary($auth);
            $jit = $this->resolveJitSettings($config);

            $email = $this->extractEmail($claims);
            if ($email === '') {
                throw ValidationException::withMessages([
                    'email' => ['SAML response missing email attribute.'],
                ])->status(422);
            }

            $existingUser = $this->userModel->newQuery()
                ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
                ->first();

            if (! $existingUser instanceof User) {
                if (! $jit['create_users']) {
                    throw ValidationException::withMessages([
                        'email' => ['User does not exist and automatic provisioning is disabled.'],
                    ])->status(422);
                }

                $user = $this->userModel->newQuery()->create([
                    'name' => $this->resolveName($claims, $email),
                    'email' => $email,
                    'password' => $this->hasher->make(Str::uuid()->toString()),
                ]);
                $isExistingUser = false;
            } else {
                $user = $existingUser;
                $isExistingUser = true;
            }

            /** @var User $user */
            if ($isExistingUser) {
                $this->updateUserName($user, $claims);
            }

            $roles = $this->resolveRoles($jit, fn (string $claim): mixed => $this->claimValue($claims, $claim));
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

            $this->logSuccess($provider, $user, $request, $claims);

            return $user;
        } catch (ValidationException $e) {
            $this->logFailure($provider, $request, $config, $e->errors());
            throw $e;
        } catch (\Throwable $e) {
            $this->logFailure($provider, $request, $config, ['message' => [$e->getMessage()]]);

            throw ValidationException::withMessages([
                'SAMLResponse' => ['Unexpected error while processing SAML response.'],
            ])->status(401);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,mixed>  $input
     */
    private function resolveLibraryAuth(
        IdpProvider $provider,
        array $config,
        Request $request,
        ?string $expectedRequestId,
        array $input
    ): OneLoginAuth {
        if (isset($input['saml_auth']) && $input['saml_auth'] instanceof OneLoginAuth) {
            return $input['saml_auth'];
        }

        $encodedResponse = $input['SAMLResponse'] ?? $input['saml_response'] ?? null;
        if (! is_string($encodedResponse) || trim($encodedResponse) === '') {
            throw ValidationException::withMessages([
                'SAMLResponse' => ['SAMLResponse is required.'],
            ])->status(422);
        }

        try {
            return $this->bridge->processResponse($provider, $config, $request, $expectedRequestId);
        } catch (SamlLibraryException $e) {
            throw ValidationException::withMessages([
                'SAMLResponse' => [$e->getMessage()],
            ])->status(401);
        }
    }

    /**
     * @return array<string,mixed>
     */
    private function buildClaimsFromLibrary(OneLoginAuth $auth): array
    {
        /** @var array<string,mixed> $claims */
        $claims = [];
        $issuer = $this->resolveIssuerFromSettings($auth);
        if ($issuer !== null) {
            $this->storeClaim($claims, 'response.issuer', $issuer);
            $this->storeClaim($claims, 'assertion.issuer', $issuer);
        }

        /** @var string|null $rawNameId */
        $rawNameId = $auth->getNameId();
        if (is_string($rawNameId)) {
            $nameId = trim($rawNameId);
            if ($nameId !== '') {
                $this->storeClaim($claims, 'subject.name_id', $nameId);
            }
        }

        /** @var array<array-key,mixed> $attributes */
        $attributes = $auth->getAttributes();
        $this->applyAttributeValues($claims, $attributes);

        /** @var array<array-key,mixed> $friendlyAttributes */
        $friendlyAttributes = $auth->getAttributesWithFriendlyName();
        $this->applyAttributeValues($claims, $friendlyAttributes);

        /** @var string|null $rawSessionIndex */
        $rawSessionIndex = $auth->getSessionIndex();
        if (is_string($rawSessionIndex)) {
            $sessionIndex = trim($rawSessionIndex);
            if ($sessionIndex !== '') {
                $this->storeClaim($claims, 'session.index', $sessionIndex);
            }
        }

        /** @var string|null $rawAssertionId */
        $rawAssertionId = $auth->getLastAssertionId();
        if (is_string($rawAssertionId)) {
            $assertionId = trim($rawAssertionId);
            if ($assertionId !== '') {
                $this->storeClaim($claims, 'assertion.id', $assertionId);
            }
        }

        return $claims;
    }

    /**
     * @param  array<string,mixed>  $claims
     *
     * @param-out array<string,mixed> $claims
     *
     * @param  array<array-key,mixed>  $attributes
     */
    private function applyAttributeValues(array &$claims, array $attributes): void
    {
        foreach ($attributes as $attribute => $values) {
            if (! is_array($values)) {
                continue;
            }

            /** @var array<mixed> $values */
            /** @psalm-suppress MixedAssignment */
            foreach ($values as $value) {
                if (! is_string($value)) {
                    continue;
                }

                $this->storeClaim($claims, (string) $attribute, $value);
            }
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function extractEmail(array $claims): string
    {
        $candidateKeys = [
            'email',
            'mail',
            'emailaddress',
            'user.email',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/emailaddress',
            'urn:oid:0.9.2342.19200300.100.1.3',
            'subject.name_id',
        ];

        foreach ($candidateKeys as $key) {
            $candidate = $this->claimValue($claims, $key);
            if (! is_string($candidate)) {
                continue;
            }

            $email = trim($candidate);
            if ($email !== '' && str_contains($email, '@')) {
                return mb_strtolower($email);
            }
        }

        try {
            $this->logger->notice('SAML email attribute not found in assertion.', [
                'available_claims' => array_keys($claims),
            ]);
        } catch (\Throwable $e) {
            // Logging should never block authentication.
        }

        return '';
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function resolveName(array $claims, string $fallbackEmail): string
    {
        $displayName = $this->firstNonEmptyClaim($claims, [
            'displayname',
            'cn',
            'name',
            'http://schemas.xmlsoap.org/ws/2005/05/identity/claims/displayname',
        ]);
        if ($displayName !== null) {
            return $displayName;
        }

        $given = $this->firstNonEmptyClaim($claims, ['givenname', 'given_name']);
        $surname = $this->firstNonEmptyClaim($claims, ['sn', 'surname', 'family_name']);

        if ($given !== null && $surname !== null) {
            return $given.' '.$surname;
        }

        if ($given !== null) {
            return $given;
        }

        if ($surname !== null) {
            return $surname;
        }

        $principal = $this->firstNonEmptyClaim($claims, ['subject.name_id']);
        if ($principal !== null) {
            return $this->normalizePrincipal($principal);
        }

        return $fallbackEmail;
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function updateUserName(User $user, array $claims): void
    {
        $resolved = trim($this->resolveName($claims, $user->email));
        $currentName = trim($user->name);

        if ($resolved === '' || strcasecmp($resolved, trim($user->email)) === 0) {
            return;
        }

        if (strcasecmp($resolved, $currentName) === 0) {
            return;
        }

        $user->name = $resolved;
        $user->save();
    }

    /**
     * @param  array<string,mixed>  $claims
     */
    private function claimValue(array $claims, string $key): mixed
    {
        $candidates = [];
        $trimmed = trim($key);
        if ($trimmed === '') {
            return null;
        }

        $candidates[] = $trimmed;
        $lower = mb_strtolower($trimmed);
        if ($lower !== $trimmed) {
            $candidates[] = $lower;
        }

        foreach ($candidates as $candidate) {
            if (array_key_exists($candidate, $claims)) {
                return $claims[$candidate];
            }
        }

        return null;
    }

    /**
     * @param  array<string,mixed>  $claims
     *
     * @param-out array<string,mixed> $claims
     */
    private function storeClaim(array &$claims, string $key, string $value): void
    {
        $normalizedKey = trim($key);
        if ($normalizedKey === '') {
            return;
        }

        $this->appendClaimValue($claims, $normalizedKey, $value);

        $lower = mb_strtolower($normalizedKey);
        if ($lower !== $normalizedKey) {
            $this->appendClaimValue($claims, $lower, $value);
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     *
     * @param-out array<string,mixed> $claims
     */
    private function appendClaimValue(array &$claims, string $key, string $value): void
    {
        if (! array_key_exists($key, $claims)) {
            $claims[$key] = $value;

            return;
        }

        if (is_string($claims[$key])) {
            if (strcasecmp($claims[$key], $value) === 0) {
                return;
            }

            $claims[$key] = [$claims[$key], $value];

            return;
        }

        if (is_array($claims[$key])) {
            $values = $claims[$key];
            $values[] = $value;
            $stringValues = array_values(array_filter(
                $values,
                static fn ($item): bool => is_string($item)
            ));
            /** @var list<string> $unique */
            $unique = array_values(array_unique($stringValues));
            $claims[$key] = $unique;
        }
    }

    /**
     * @param  array<string,mixed>  $claims
     * @param  list<string>  $keys
     */
    private function firstNonEmptyClaim(array $claims, array $keys): ?string
    {
        foreach ($keys as $key) {
            $value = $this->claimValue($claims, $key);
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
    private function logSuccess(IdpProvider $provider, User $user, Request $request, array $claims): void
    {
        $entityId = trim($provider->id);
        if ($entityId === '') {
            $entityId = trim($provider->key);
        }
        if ($entityId === '') {
            $entityId = 'idp.provider';
        }

        $meta = [
            'provider_key' => $provider->key,
            'issuer' => $claims['response.issuer'] ?? $claims['assertion.issuer'] ?? null,
            'subject' => $claims['subject.name_id'] ?? null,
        ];

        try {
            $this->audit->log([
                'actor_id' => $user->id,
                'action' => 'auth.saml.login',
                'category' => 'AUTH',
                'entity_type' => 'idp.provider',
                'entity_id' => $entityId,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $meta,
            ]);
        } catch (\Throwable $e) {
            // Never block on audit logging.
        }
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<array-key,mixed>  $details
     */
    private function logFailure(IdpProvider $provider, Request $request, array $config, array $details): void
    {
        try {
            $this->logger->warning('SAML authentication failure.', [
                'provider' => $provider->key,
                'issuer' => $config['entity_id'] ?? null,
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'details' => $details,
            ]);
        } catch (\Throwable $e) {
            // never block on logging.
        }
    }

    private function resolveIssuerFromSettings(OneLoginAuth $auth): ?string
    {
        $settings = $auth->getSettings();
        $idpData = $settings->getIdPData();
        $issuer = $idpData['entityId'] ?? null;
        if (! is_string($issuer)) {
            return null;
        }

        $trimmed = trim($issuer);

        return $trimmed === '' ? null : $trimmed;
    }

    private function normalizePrincipal(string $principal): string
    {
        $trimmed = trim($principal);
        if (! str_contains($trimmed, '\\')) {
            return $trimmed;
        }

        $parts = explode('\\', $trimmed);
        /** @var string|false $last */
        $last = end($parts);

        if ($last === false || $last === '') {
            return $trimmed;
        }

        return $last;
    }

    private function stringFromInput(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
