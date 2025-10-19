<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Contracts\Auth\LdapAuthenticatorContract;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Auth\Concerns\ResolvesJitAssignments;
use App\Services\Auth\Ldap\LdapClientInterface;
use App\Services\Auth\Ldap\LdapException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * LDAP authenticator orchestrating credential validation + provisioning.
 *
 * @SuppressWarnings("PMD.CyclomaticComplexity")
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class LdapAuthenticator implements LdapAuthenticatorContract
{
    use ResolvesJitAssignments;

    public function __construct(
        private readonly LdapClientInterface $client,
        private readonly AuditLogger $audit,
        private readonly LoggerInterface $logger
    ) {}

    /**
     * @param  array<string,mixed>  $input
     */
    #[\Override]
    public function authenticate(IdpProvider $provider, array $input, Request $request): User
    {
        $this->assertLdapDriver($provider);

        [$username, $password] = $this->extractCredentials($input);
        $config = $this->readProviderConfig($provider);
        $entry = $this->performAuthentication($provider, $config, $username, $password);

        $user = $this->provisionUser($config, $entry['attributes']);
        $this->auditSuccess($provider, $request, $user, $username, $entry['dn']);

        return $user;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $attributes
     */
    private function provisionUser(array $config, array $attributes): User
    {
        $email = $this->resolveEmail($config, $attributes);
        $jit = $this->resolveJitSettings($config);

        /** @var User|null $user */
        $user = $this->findUser($email);
        $isNewUser = $user === null;

        if ($isNewUser) {
            if (! $jit['create_users']) {
                throw ValidationException::withMessages([
                    'email' => ['User does not exist and automatic provisioning is disabled.'],
                ])->status(422);
            }

            $user = $this->createUser($email, $this->resolveDisplayName($config, $attributes, $email));
        }

        if (! $user instanceof User) { // @phpstan-ignore-line
            throw new RuntimeException('LDAP user resolution failed.');
        }

        if (! $isNewUser) {
            $this->refreshDisplayName($user, $config, $attributes);
        }

        $this->syncRoles($user, $jit, $attributes);

        return $user;
    }

    private function assertLdapDriver(IdpProvider $provider): void
    {
        if (strtolower($provider->driver) === 'ldap') {
            return;
        }

        throw ValidationException::withMessages([
            'provider' => ['Provider driver does not support LDAP authentication.'],
        ])->status(422);
    }

    /**
     * @param  array<string,mixed>  $input
     * @return array{0:string,1:string}
     */
    private function extractCredentials(array $input): array
    {
        $usernameRaw = $input['username'] ?? null;
        if (! is_string($usernameRaw) || trim($usernameRaw) === '') {
            throw ValidationException::withMessages([
                'username' => ['The username field is required.'],
            ])->status(422);
        }

        $passwordRaw = $input['password'] ?? null;
        if (! is_string($passwordRaw) || $passwordRaw === '') {
            throw ValidationException::withMessages([
                'password' => ['The password field is required.'],
            ])->status(422);
        }

        return [trim($usernameRaw), $passwordRaw];
    }

    /**
     * @return array<string,mixed>
     */
    private function readProviderConfig(IdpProvider $provider): array
    {
        /** @var mixed $config */
        $config = $provider->getAttribute('config');

        if ($config instanceof \ArrayObject) {
            /** @var array<string,mixed> $copy */
            $copy = $config->getArrayCopy();

            return $copy;
        }

        if (is_array($config)) {
            /** @var array<string,mixed> $config */
            return $config;
        }

        return [];
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array{dn:string,attributes:array<string,list<string>>}
     */
    private function performAuthentication(IdpProvider $provider, array $config, string $username, string $password): array
    {
        try {
            return $this->client->authenticate($config, $username, $password);
        } catch (LdapException $e) {
            $this->logFailure($provider, $username, $e);
            throw $this->mapException($e);
        }
    }

    private function logFailure(IdpProvider $provider, string $username, LdapException $exception): void
    {
        $this->logger->warning('LDAP authentication failed.', [
            'provider_id' => $provider->id,
            'provider_key' => $provider->key,
            'username' => $username,
            'error' => $exception->getMessage(),
        ]);
    }

    private function mapException(LdapException $exception): ValidationException
    {
        if ($this->isCredentialException($exception)) {
            return ValidationException::withMessages([
                'username' => ['Invalid credentials.'],
            ])->status(401);
        }

        return ValidationException::withMessages([
            'provider' => ['LDAP authentication failed due to provider misconfiguration.'],
        ])->status(422);
    }

    private function isCredentialException(LdapException $exception): bool
    {
        return $exception->getMessage() === 'Invalid LDAP credentials.';
    }

    private function findUser(string $email): ?User
    {
        return User::query()
            ->whereRaw('LOWER(email) = ?', [mb_strtolower($email)])
            ->first();
    }

    /**
     * @SuppressWarnings("PMD.StaticAccess")
     */
    private function createUser(string $email, string $name): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make(Str::uuid()->toString()),
        ]);
    }

    /**
     * @param  array{create_users:bool,default_roles:list<string>,role_templates:list<array{claim:string,values:list<string>,roles:list<string>}>}  $jit
     * @param  array<string,list<string>>  $attributes
     */
    private function syncRoles(User $user, array $jit, array $attributes): void
    {
        $roles = $this->resolveRoles($jit, function (string $attribute) use ($attributes): mixed {
            return $this->attributeValues($attributes, $attribute);
        });

        if ($roles === []) {
            return;
        }

        $matchingRoleIds = Role::query()
            ->whereIn('id', $roles)
            ->pluck('id')
            ->all();

        if ($matchingRoleIds === []) {
            return;
        }

        /** @var list<string> $matchingRoleIds */
        $user->roles()->syncWithoutDetaching($matchingRoleIds);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $attributes
     */
    private function resolveEmail(array $config, array $attributes): string
    {
        $attribute = 'mail';
        if (isset($config['email_attribute']) && is_string($config['email_attribute'])) {
            $candidate = trim($config['email_attribute']);
            if ($candidate !== '') {
                $attribute = strtolower($candidate);
            }
        }

        $email = $this->firstAttribute($attributes, $attribute);
        if ($email === '') {
            throw ValidationException::withMessages([
                'email' => ['LDAP entry missing required email attribute.'],
            ])->status(422);
        }

        return mb_strtolower($email);
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $attributes
     */
    private function resolveDisplayName(array $config, array $attributes, string $fallbackEmail): string
    {
        $primaryAttribute = 'cn';
        if (isset($config['name_attribute']) && is_string($config['name_attribute'])) {
            $candidate = trim($config['name_attribute']);
            if ($candidate !== '') {
                $primaryAttribute = strtolower($candidate);
            }
        }

        $name = $this->firstAttribute($attributes, $primaryAttribute);
        if ($name !== '') {
            return $name;
        }

        $displayName = $this->firstAttribute($attributes, 'displayname');
        if ($displayName !== '') {
            return $displayName;
        }

        $givenName = $this->firstAttribute($attributes, 'givenname');
        $surname = $this->firstAttribute($attributes, 'sn');
        if ($givenName !== '' && $surname !== '') {
            return trim($givenName.' '.$surname);
        }

        return $fallbackEmail;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $attributes
     */
    private function refreshDisplayName(User $user, array $config, array $attributes): void
    {
        $resolved = trim($this->resolveDisplayName($config, $attributes, $user->email));
        $current = trim($user->name);

        if ($resolved === '' || $resolved === $current) {
            return;
        }

        $user->name = $resolved;
        $user->save();
    }

    /**
     * @param  array<string,list<string>>  $attributes
     * @return list<string>|null
     */
    private function attributeValues(array $attributes, string $attribute): ?array
    {
        $key = strtolower($attribute);
        $value = $attributes[$key] ?? null;

        if (! is_array($value)) {
            return null;
        }

        $results = [];
        foreach ($value as $candidate) {
            $trimmed = trim($candidate);
            if ($trimmed === '') {
                continue;
            }

            $results[] = $trimmed;
        }

        return $results;
    }

    /**
     * @param  array<string,list<string>>  $attributes
     */
    private function firstAttribute(array $attributes, string $attribute): string
    {
        $normalized = $this->attributeValues($attributes, $attribute);

        if ($normalized === null) {
            return '';
        }

        return $normalized[0] ?? '';
    }

    private function auditSuccess(IdpProvider $provider, Request $request, User $user, string $username, string $dn): void
    {
        $entityId = trim($provider->id);
        if ($entityId === '') {
            $fallback = trim($provider->key);
            if ($fallback === '') {
                throw new RuntimeException('Identity provider identifier is missing.');
            }

            $entityId = $fallback;
        }

        $this->audit->log([
            'actor_id' => $user->id,
            'action' => 'auth.ldap.login',
            'category' => 'AUTH',
            'entity_type' => 'idp.provider',
            'entity_id' => $entityId,
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'meta' => [
                'provider_key' => $provider->key,
                'username' => $username,
                'user_dn' => $dn,
            ],
        ]);
    }
}
