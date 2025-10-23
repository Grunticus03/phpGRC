<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\GuzzleException;
use Illuminate\Validation\ValidationException;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * @SuppressWarnings("PMD.CyclomaticComplexity")
 * @SuppressWarnings("PMD.NPathComplexity")
 * @SuppressWarnings("PMD.LongClass")
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 * @SuppressWarnings("PMD.LongMethod")
 * @SuppressWarnings("PMD.StaticAccess")
 * @SuppressWarnings("PMD.ElseExpression")
 */
class OidcIdpDriver extends AbstractIdpDriver
{
    public function __construct(
        private readonly ClientInterface $http,
        private readonly LoggerInterface $logger
    ) {}

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

        $details = [
            'issuer' => $normalized['issuer'] ?? null,
            'scopes' => $normalized['scopes'] ?? [],
        ];

        $issuer = $normalized['issuer'] ?? null;
        if (! is_string($issuer) || trim($issuer) === '') {
            return IdpHealthCheckResult::failed('Issuer is required to run health checks.', [
                'errors' => [
                    'config.issuer' => ['Issuer must be provided.'],
                ],
            ]);
        }

        try {
            $discovery = $this->fetchDiscoveryDocument($issuer);
            $details['discovery'] = [
                'issuer' => $discovery['issuer'] ?? null,
                'authorization_endpoint' => $discovery['authorization_endpoint'] ?? null,
                'token_endpoint' => $discovery['token_endpoint'] ?? null,
                'jwks_uri' => $discovery['jwks_uri'] ?? null,
            ];
        } catch (RuntimeException $e) {
            return IdpHealthCheckResult::failed('Failed to download OIDC discovery metadata.', $details + [
                'error' => $e->getMessage(),
            ]);
        }

        $tokenEndpoint = isset($discovery['token_endpoint']) && is_string($discovery['token_endpoint'])
            ? trim($discovery['token_endpoint'])
            : null;

        if ($tokenEndpoint === null || $tokenEndpoint === '') {
            return IdpHealthCheckResult::warning('Discovery metadata loaded, but the token endpoint was not provided.', $details);
        }

        $clientId = $normalized['client_id'] ?? null;
        if (! is_string($clientId)) {
            throw new RuntimeException('Client ID is missing from normalized configuration.');
        }

        $clientSecret = $normalized['client_secret'] ?? null;
        if (! is_string($clientSecret)) {
            throw new RuntimeException('Client secret is missing from normalized configuration.');
        }

        $scopes = [];
        if (isset($normalized['scopes']) && is_array($normalized['scopes'])) {
            /** @var array<int|string, mixed> $rawScopes */
            $rawScopes = $normalized['scopes'];
            foreach ($rawScopes as $scope) {
                if (! is_string($scope)) {
                    continue;
                }

                $trimmed = trim($scope);
                if ($trimmed === '') {
                    continue;
                }

                $scopes[] = $trimmed;
            }
        }

        $probe = $this->probeClientCredentials(
            $tokenEndpoint,
            $clientId,
            $clientSecret,
            $scopes
        );

        $details['token_probe'] = $probe['details'];

        return match ($probe['status']) {
            'invalid' => IdpHealthCheckResult::failed($probe['message'], $details),
            'warning' => IdpHealthCheckResult::warning($probe['message'], $details),
            default => IdpHealthCheckResult::healthy($probe['message'], $details),
        };
    }

    /**
     * @return array<string,mixed>
     */
    private function fetchDiscoveryDocument(string $issuer): array
    {
        $endpoint = rtrim($issuer, '/').'/.well-known/openid-configuration';

        try {
            $response = $this->http->request('GET', $endpoint, [
                'headers' => ['Accept' => 'application/json'],
                'timeout' => 10,
                'connect_timeout' => 5,
            ]);
        } catch (GuzzleException $e) {
            $this->logger->warning('OIDC discovery request failed.', [
                'endpoint' => $endpoint,
                'error' => $e->getMessage(),
            ]);

            throw new RuntimeException('Unable to reach discovery endpoint.');
        }

        if ($response->getStatusCode() >= 400) {
            $this->logger->warning('OIDC discovery returned an error.', [
                'endpoint' => $endpoint,
                'status' => $response->getStatusCode(),
            ]);

            throw new RuntimeException(sprintf('Discovery endpoint returned HTTP %d.', $response->getStatusCode()));
        }

        /** @var mixed $decoded */
        $decoded = json_decode((string) $response->getBody(), true);
        if (! is_array($decoded)) {
            throw new RuntimeException('Discovery document response was not valid JSON.');
        }

        /** @var array<string,mixed> $document */
        $document = $decoded;

        return $document;
    }

    /**
     * @return array{status:'ok'|'warning'|'invalid',message:string,details:array<string,mixed>}
     *
     * @SuppressWarnings("PMD.ExcessiveMethodLength")
     */
    /**
     * @param  list<string>  $scopes
     * @return array{status:'ok'|'warning'|'invalid',message:string,details:array<string,mixed>}
     */
    private function probeClientCredentials(string $tokenEndpoint, string $clientId, string $clientSecret, array $scopes): array
    {
        $probeScope = 'openid';
        foreach ($scopes as $candidate) {
            if (str_contains($candidate, '.default')) {
                $probeScope = $candidate;
                break;
            }
        }

        if ($clientId === '' || $clientSecret === '') {
            return [
                'status' => 'warning',
                'message' => 'Client credentials were not provided; skipping token endpoint verification.',
                'details' => ['skipped' => true],
            ];
        }

        $attempts = [
            [
                'mode' => 'basic',
                'options' => [
                    'headers' => [
                        'Accept' => 'application/json',
                        'Authorization' => 'Basic '.base64_encode(rawurlencode($clientId).':'.rawurlencode($clientSecret)),
                    ],
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'scope' => $probeScope,
                    ],
                ],
            ],
            [
                'mode' => 'post',
                'options' => [
                    'headers' => [
                        'Accept' => 'application/json',
                    ],
                    'form_params' => [
                        'grant_type' => 'client_credentials',
                        'client_id' => $clientId,
                        'client_secret' => $clientSecret,
                        'scope' => $probeScope,
                    ],
                ],
            ],
        ];

        foreach ($attempts as $attempt) {
            try {
                $response = $this->http->request('POST', $tokenEndpoint, $attempt['options'] + [
                    'timeout' => 10,
                    'connect_timeout' => 5,
                ]);
            } catch (GuzzleException $e) {
                $this->logger->warning('OIDC token endpoint probe failed.', [
                    'endpoint' => $tokenEndpoint,
                    'mode' => $attempt['mode'],
                    'error' => $e->getMessage(),
                ]);

                return [
                    'status' => 'warning',
                    'message' => 'Unable to reach the token endpoint during validation.',
                    'details' => [
                        'mode' => $attempt['mode'],
                        'error' => $e->getMessage(),
                    ],
                ];
            }

            $status = $response->getStatusCode();
            $body = (string) $response->getBody();
            /** @var mixed $decodedPayload */
            $decodedPayload = json_decode($body, true);
            $error = null;
            if (is_array($decodedPayload)) {
                /** @var array<array-key, mixed> $payload */
                $payload = $decodedPayload;
                /** @var mixed $errorValue */
                $errorValue = $payload['error'] ?? null;
                if (is_string($errorValue)) {
                    $error = $errorValue;
                }
            }

            if ($status === 401 || $error === 'invalid_client') {
                if ($attempt['mode'] === 'basic') {
                    continue; // try POST mode before failing
                }

                return [
                    'status' => 'invalid',
                    'message' => 'Token endpoint rejected the provided client credentials.',
                    'details' => [
                        'status' => $status,
                        'error' => $error ?? $body,
                    ],
                ];
            }

            if ($status >= 500) {
                return [
                    'status' => 'warning',
                    'message' => 'Token endpoint returned an unexpected server error.',
                    'details' => ['status' => $status],
                ];
            }

            if ($status === 400) {
                if ($error === 'invalid_scope') {
                    return [
                        'status' => 'ok',
                        'message' => 'Token endpoint rejected the client-credentials scope (invalid_scope) but interactive authorization_code flows are still valid.',
                        'details' => [
                            'status' => $status,
                            'error' => $error,
                            'mode' => $attempt['mode'],
                        ],
                    ];
                }

                if (in_array($error, ['unauthorized_client', 'unsupported_grant_type', 'invalid_request'], true)) {
                    return [
                        'status' => 'warning',
                        'message' => 'Token endpoint responded, but additional configuration may be required (check scopes or grant type).',
                        'details' => [
                            'status' => $status,
                            'error' => $error,
                            'mode' => $attempt['mode'],
                        ],
                    ];
                }

                return [
                    'status' => 'warning',
                    'message' => 'Token endpoint returned an unexpected response.',
                    'details' => [
                        'status' => $status,
                        'error' => $error !== null ? $error : $body,
                    ],
                ];
            }

            return [
                'status' => 'ok',
                'message' => 'OIDC metadata and client credentials validated.',
                'details' => [
                    'status' => $status,
                    'error' => $error,
                ],
            ];
        }

        return [
            'status' => 'invalid',
            'message' => 'Token endpoint rejected the provided client credentials.',
            'details' => ['mode' => 'post'],
        ];
    }

    /**
     * @param  array<string,list<string>>  $errors
     * @return array{create_users:bool,default_roles:list<string>,role_templates:list<array{claim:string,values:list<string>,roles:list<string>}>}
     *
     * @SuppressWarnings("PMD.ExcessiveMethodLength")
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
