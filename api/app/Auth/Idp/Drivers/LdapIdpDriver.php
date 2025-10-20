<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Services\Auth\Ldap\LdapClientInterface;
use App\Services\Auth\Ldap\LdapException;
use Illuminate\Validation\ValidationException;

/**
 * @SuppressWarnings("PMD.ExcessiveClassComplexity")
 */
final class LdapIdpDriver extends AbstractIdpDriver
{
    private const USERNAME_PLACEHOLDER = '{{username}}';

    /**
     * Given our limited bind variants we only allow service account binds (search + rebind)
     * or direct user DN binds.
     */
    private const SUPPORTED_BIND_STRATEGIES = ['service', 'direct'];

    public function __construct(private readonly LdapClientInterface $client) {}

    #[\Override]
    public function key(): string
    {
        return 'ldap';
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
        /** @var array<string, list<string>> $errors */
        $errors = [];

        $config = $this->normalizeFlags($config);
        $config = $this->normalizeCore($config, $errors);
        $config = $this->normalizeBindStrategy($config, $errors);
        $config = $this->normalizeTimeout($config, $errors);
        $config = $this->normalizeUserDiscovery($config, $errors);
        $config = $this->normalizeAttributeMapping($config, $errors);

        $this->validateTlsRequirement($config, $errors);
        $this->throwIfErrors($errors);

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    #[\Override]
    public function checkHealth(array $config): IdpHealthCheckResult
    {
        try {
            $normalized = $this->normalizeConfig($config);
        } catch (ValidationException $e) {
            return IdpHealthCheckResult::failed('LDAP configuration invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        try {
            $this->client->checkConnection($normalized);
        } catch (LdapException $e) {
            return IdpHealthCheckResult::failed('LDAP connection failed.', [
                'error' => $e->getMessage(),
            ]);
        }

        return IdpHealthCheckResult::healthy('LDAP connection succeeded.', [
            'host' => $normalized['host'] ?? null,
            'port' => $normalized['port'] ?? null,
            'bind_strategy' => $normalized['bind_strategy'] ?? null,
            'tls' => [
                'use_ssl' => $normalized['use_ssl'] ?? false,
                'start_tls' => $normalized['start_tls'] ?? false,
                'require_tls' => $normalized['require_tls'] ?? false,
            ],
        ]);
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    private function normalizeFlags(array $config): array
    {
        $config['use_ssl'] = $this->coerceBoolean($config['use_ssl'] ?? null) ?? false;
        $config['start_tls'] = $this->coerceBoolean($config['start_tls'] ?? null) ?? false;
        $config['require_tls'] = $this->coerceBoolean($config['require_tls'] ?? null) ?? false;

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     */
    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeCore(array $config, array &$errors): array
    {
        $this->requireString($config, 'host', $errors, 'Host is required.');

        if (! array_key_exists('port', $config) || $config['port'] === null || $config['port'] === '') {
            $config['port'] = $config['use_ssl'] ? 636 : 389;
        }

        $this->coercePort($config, 'port', $errors);
        $this->requireString($config, 'base_dn', $errors, 'Base DN is required.');

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeBindStrategy(array $config, array &$errors): array
    {
        $strategy = $this->resolveBindStrategy($config, $errors);
        $config['bind_strategy'] = $strategy;

        if ($strategy === 'service') {
            $this->requireString($config, 'bind_dn', $errors, 'Bind DN is required when using service bind strategy.');
            $this->requireString($config, 'bind_password', $errors, 'Bind password is required when using service bind strategy.');

            return $config;
        }

        unset($config['bind_dn'], $config['bind_password']);

        if (! array_key_exists('user_dn_template', $config)) {
            $this->addError($errors, 'config.user_dn_template', 'User DN template is required for direct bind strategy.');

            return $config;
        }

        $templateRaw = $config['user_dn_template'];
        if (! is_string($templateRaw) || trim($templateRaw) === '') {
            $this->addError($errors, 'config.user_dn_template', 'User DN template must be a non-empty string.');

            return $config;
        }

        if (! str_contains($templateRaw, self::USERNAME_PLACEHOLDER)) {
            $this->addError($errors, 'config.user_dn_template', 'User DN template must include the placeholder "{{username}}".');

            return $config;
        }

        $config['user_dn_template'] = trim($templateRaw);

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeTimeout(array $config, array &$errors): array
    {
        if (! array_key_exists('timeout', $config)) {
            unset($config['timeout']);

            return $config;
        }

        $timeout = $config['timeout'];
        if (is_string($timeout) && is_numeric($timeout)) {
            $timeout = (int) $timeout;
        }

        if (! is_int($timeout) || $timeout < 1 || $timeout > 120) {
            $this->addError($errors, 'config.timeout', 'Timeout must be an integer between 1 and 120 seconds.');

            return $config;
        }

        $config['timeout'] = $timeout;

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeUserDiscovery(array $config, array &$errors): array
    {
        $filter = '(&(objectClass=person)(uid='.self::USERNAME_PLACEHOLDER.'))';
        if (isset($config['user_filter']) && is_string($config['user_filter'])) {
            $candidate = trim($config['user_filter']);
            if ($candidate !== '') {
                $filter = $candidate;
            }
        }

        if (! str_contains($filter, self::USERNAME_PLACEHOLDER)) {
            $this->addError($errors, 'config.user_filter', 'User filter must include the placeholder "{{username}}".');
        }

        $config['user_filter'] = trim($filter);

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     * @return array<string,mixed>
     */
    private function normalizeAttributeMapping(array $config, array &$errors): array
    {
        $config['email_attribute'] = $this->normalizeAttributeKey(
            $config,
            'email_attribute',
            'mail',
            'Email attribute is required.',
            $errors
        );

        $config['name_attribute'] = $this->normalizeAttributeKey(
            $config,
            'name_attribute',
            'cn',
            'Display name attribute is required.',
            $errors
        );

        $config['username_attribute'] = $this->normalizeAttributeKey(
            $config,
            'username_attribute',
            'uid',
            'Username attribute must be a non-empty string.',
            $errors
        );

        $photoAttribute = $this->normalizeOptionalAttributeKey($config, 'photo_attribute');
        if ($photoAttribute === null) {
            unset($config['photo_attribute']);
        }
        if ($photoAttribute !== null) {
            $config['photo_attribute'] = $photoAttribute;
        }

        return $config;
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    private function validateTlsRequirement(array $config, array &$errors): void
    {
        $requireTls = (bool) ($config['require_tls'] ?? false);
        if (! $requireTls) {
            return;
        }

        $useSsl = (bool) ($config['use_ssl'] ?? false);
        $startTls = (bool) ($config['start_tls'] ?? false);
        $tlsActive = $useSsl || $startTls;
        if ($tlsActive) {
            return;
        }

        $this->addError($errors, 'config.require_tls', 'TLS is required; enable either use_ssl or start_tls.');
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    private function resolveBindStrategy(array $config, array &$errors): string
    {
        if (! array_key_exists('bind_strategy', $config)) {
            return 'service';
        }

        $raw = $config['bind_strategy'];
        if (! is_string($raw) || trim($raw) === '') {
            $this->addError($errors, 'config.bind_strategy', 'Bind strategy must be a non-empty string.');

            return 'service';
        }

        $strategy = strtolower(trim($raw));
        if (in_array($strategy, self::SUPPORTED_BIND_STRATEGIES, true)) {
            return $strategy;
        }

        $this->addError($errors, 'config.bind_strategy', 'Bind strategy must be one of: '.implode(', ', self::SUPPORTED_BIND_STRATEGIES).'.');

        return 'service';
    }

    /**
     * @param  array<string,mixed>  $config
     * @param  array<string,list<string>>  $errors
     */
    private function normalizeAttributeKey(array $config, string $key, string $default, string $message, array &$errors): string
    {
        $value = $config[$key] ?? $default;
        if (! is_string($value) || trim($value) === '') {
            $this->addError($errors, "config.$key", $message);

            return $default;
        }

        return strtolower(trim($value));
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function normalizeOptionalAttributeKey(array $config, string $key): ?string
    {
        if (! array_key_exists($key, $config)) {
            return null;
        }

        $value = $config[$key];
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        return strtolower($trimmed);
    }

    private function coerceBoolean(mixed $value): ?bool
    {
        if (is_bool($value)) {
            return $value;
        }

        if (is_string($value) || is_int($value)) {
            $coerced = filter_var($value, FILTER_VALIDATE_BOOL, FILTER_NULL_ON_FAILURE);
            if ($coerced !== null) {
                return $coerced;
            }
        }

        return null;
    }
}
