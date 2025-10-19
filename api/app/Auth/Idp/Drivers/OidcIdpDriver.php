<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use Illuminate\Validation\ValidationException;

class OidcIdpDriver extends AbstractIdpDriver
{
    #[\Override]
    public function key(): string
    {
        return 'oidc';
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     *
     * @throws ValidationException
     */
    #[\Override]
    public function normalizeConfig(array $config): array
    {
        /** @var array<string,list<string>> $errors */
        $errors = [];

        $issuer = $this->requireUrl($config, 'issuer', $errors, true, 'Issuer must be a valid HTTPS URL.');
        $clientId = $this->requireString($config, 'client_id', $errors, 'Client ID is required.');
        $clientSecret = $this->requireString($config, 'client_secret', $errors, 'Client secret is required.');

        $scopes = $this->coerceStringList($config, 'scopes', $errors);
        if ($scopes !== []) {
            $config['scopes'] = array_values(array_unique($scopes));
        }

        $redirects = $this->coerceStringList($config, 'redirect_uris', $errors, 'Redirect URIs must be an array of URLs.');
        if ($redirects !== []) {
            $validatedRedirects = [];
            foreach ($redirects as $index => $url) {
                $validated = filter_var($url, FILTER_VALIDATE_URL);
                if ($validated === false) {
                    $this->addError($errors, "config.redirect_uris.$index", 'Redirect URI must be a valid URL.');

                    continue;
                }

                $validatedRedirects[] = $validated;
            }
            $config['redirect_uris'] = $validatedRedirects;
        }

        if (array_key_exists('jit', $config)) {
            $config['jit'] = $this->normalizeJitConfig($config['jit'], $errors);
        } else {
            $config['jit'] = [
                'create_users' => true,
                'default_roles' => [],
                'role_templates' => [],
            ];
        }

        if ($issuer !== '') {
            $config['issuer'] = $issuer;
        }

        if ($clientId !== '') {
            $config['client_id'] = $clientId;
        }

        if ($clientSecret !== '') {
            $config['client_secret'] = $clientSecret;
        }

        $this->throwIfErrors($errors);

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    #[\Override]
    public function checkHealth(array $config): IdpHealthCheckResult
    {
        try {
            $normalized = $this->normalizeConfig($config);
        } catch (ValidationException $e) {
            return IdpHealthCheckResult::failed('OIDC configuration invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        return IdpHealthCheckResult::healthy('OIDC configuration validated.', [
            'issuer' => $normalized['issuer'] ?? null,
            'scopes' => $normalized['scopes'] ?? [],
        ]);
    }

    /**
     * @param  array<string,list<string>>  $errors
     * @return array{create_users:bool,default_roles:list<string>,role_templates:list<array{claim:string,values:list<string>,roles:list<string>}>}
     */
    private function normalizeJitConfig(mixed $jitConfig, array &$errors): array
    {
        $normalized = [
            'create_users' => true,
            'default_roles' => [],
            'role_templates' => [],
        ];

        if (! is_array($jitConfig)) {
            $this->addError($errors, 'config.jit', 'Just-in-time provisioning must be an object.');

            return $normalized;
        }

        if (array_key_exists('create_users', $jitConfig)) {
            if (is_bool($jitConfig['create_users'])) {
                $normalized['create_users'] = $jitConfig['create_users'];
            } elseif (is_string($jitConfig['create_users']) || is_int($jitConfig['create_users'])) {
                $coerced = filter_var($jitConfig['create_users'], FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
                if ($coerced !== null) {
                    $normalized['create_users'] = $coerced;
                }
            }
        }

        if (array_key_exists('default_roles', $jitConfig)) {
            if (! is_array($jitConfig['default_roles'])) {
                $this->addError($errors, 'config.jit.default_roles', 'Default roles must be an array of role IDs.');
            } else {
                foreach ($jitConfig['default_roles'] as $role) {
                    if (! is_string($role) || trim($role) === '') {
                        $this->addError($errors, 'config.jit.default_roles', 'Role identifiers must be non-empty strings.');

                        continue;
                    }
                    $normalized['default_roles'][] = strtolower(trim($role));
                }
                $normalized['default_roles'] = array_values(array_unique($normalized['default_roles']));
            }
        }

        if (array_key_exists('role_templates', $jitConfig)) {
            if (! is_array($jitConfig['role_templates'])) {
                $this->addError($errors, 'config.jit.role_templates', 'Role templates must be an array.');
            } else {
                foreach ($jitConfig['role_templates'] as $idx => $templateRaw) {
                    if (! is_array($templateRaw)) {
                        $this->addError($errors, "config.jit.role_templates.$idx", 'Each template must be an object.');

                        continue;
                    }

                    $claimValue = $templateRaw['claim'] ?? null;
                    if (! is_string($claimValue) || trim($claimValue) === '') {
                        $this->addError($errors, "config.jit.role_templates.$idx.claim", 'Claim is required.');

                        continue;
                    }
                    $claim = trim($claimValue);

                    /** @var array<array-key, mixed>|string|null $valuesSource */
                    $valuesSource = $templateRaw['values'] ?? ($templateRaw['value'] ?? null);
                    $values = [];
                    if (is_string($valuesSource)) {
                        $values[] = trim($valuesSource);
                    } elseif (is_array($valuesSource)) {
                        /** @var array<array-key, mixed> $valuesSource */
                        $normalizedValues = array_values(array_filter(
                            array_map(
                                static fn ($candidate): ?string => is_string($candidate) ? trim($candidate) : null,
                                $valuesSource
                            ),
                            static fn (?string $candidate): bool => $candidate !== null && $candidate !== ''
                        ));

                        $values = array_merge($values, $normalizedValues);
                    } else {
                        $this->addError($errors, "config.jit.role_templates.$idx.values", 'Values must be a string or array of strings.');

                        continue;
                    }

                    $values = array_values(array_unique(array_filter(
                        $values,
                        static fn (string $value): bool => $value !== ''
                    )));

                    if ($values === []) {
                        $this->addError($errors, "config.jit.role_templates.$idx.values", 'At least one comparison value is required.');

                        continue;
                    }

                    if (! isset($templateRaw['roles']) || ! is_array($templateRaw['roles']) || $templateRaw['roles'] === []) {
                        $this->addError($errors, "config.jit.role_templates.$idx.roles", 'Roles must be a non-empty array of role IDs.');

                        continue;
                    }

                    $roles = [];
                    /** @var array<array-key, mixed> $rolesSource */
                    $rolesSource = $templateRaw['roles'];
                    $normalizedRoles = array_values(array_filter(
                        array_map(
                            static fn ($candidate): ?string => is_string($candidate) ? strtolower(trim($candidate)) : null,
                            $rolesSource
                        ),
                        static fn (?string $candidate): bool => $candidate !== null && $candidate !== ''
                    ));

                    $roles = array_merge($roles, $normalizedRoles);
                    $roles = array_values(array_unique($roles));

                    if ($roles === []) {
                        $this->addError($errors, "config.jit.role_templates.$idx.roles", 'Roles must be non-empty strings.');

                        continue;
                    }

                    $normalized['role_templates'][] = [
                        'claim' => $claim,
                        'values' => $values,
                        'roles' => $roles,
                    ];
                }
            }
        }

        return $normalized;
    }
}
