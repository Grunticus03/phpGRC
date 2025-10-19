<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use Illuminate\Validation\ValidationException;

final class LdapIdpDriver extends AbstractIdpDriver
{
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
        $errors = [];

        $host = $this->requireString($config, 'host', $errors, 'Host is required.');
        $port = $this->coercePort($config, 'port', $errors);
        $baseDn = $this->requireString($config, 'base_dn', $errors, 'Base DN is required.');
        $bindDn = $this->requireString($config, 'bind_dn', $errors, 'Bind DN is required.');
        $bindPassword = $this->requireString($config, 'bind_password', $errors, 'Bind password is required.');

        if (array_key_exists('use_ssl', $config)) {
            $config['use_ssl'] = filter_var($config['use_ssl'], FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? false;
        }

        if (array_key_exists('timeout', $config)) {
            /** @var mixed $timeout */
            $timeout = $config['timeout'];
            if (is_string($timeout) && is_numeric($timeout)) {
                $timeout = (int) $timeout;
            }
            if (! is_int($timeout) || $timeout < 1 || $timeout > 120) {
                $this->addError($errors, 'config.timeout', 'Timeout must be an integer between 1 and 120 seconds.');
            } else {
                $config['timeout'] = $timeout;
            }
        }

        $this->throwIfErrors($errors);

        $config['host'] = $host;
        $config['port'] = $port;
        $config['base_dn'] = $baseDn;
        $config['bind_dn'] = $bindDn;
        $config['bind_password'] = $bindPassword;

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
            return IdpHealthCheckResult::failed('LDAP configuration invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        return IdpHealthCheckResult::healthy('LDAP configuration validated.', [
            'host' => $normalized['host'] ?? null,
            'port' => $normalized['port'] ?? null,
            'use_ssl' => $normalized['use_ssl'] ?? false,
        ]);
    }
}
