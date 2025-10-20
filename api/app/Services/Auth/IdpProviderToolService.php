<?php

declare(strict_types=1);

namespace App\Services\Auth;

use App\Auth\Idp\Drivers\LdapIdpDriver;
use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Auth\Idp\IdpDriverRegistry;
use App\Services\Auth\Ldap\LdapClientInterface;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

final class IdpProviderToolService
{
    public function __construct(
        private readonly IdpDriverRegistry $drivers,
        private readonly LdapClientInterface $ldapClient
    ) {}

    /**
     * @param  array<string,mixed>  $attributes
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function previewHealth(array $attributes): IdpHealthCheckResult
    {
        $driverKey = $attributes['driver'] ?? null;
        if (! is_string($driverKey) || $driverKey === '') {
            throw new InvalidArgumentException('Provider driver is required.');
        }

        if (! $this->drivers->has($driverKey)) {
            throw ValidationException::withMessages([
                'driver' => ['Unsupported provider driver.'],
            ]);
        }

        $config = $attributes['config'] ?? null;
        if (! is_array($config) || array_is_list($config)) {
            throw ValidationException::withMessages([
                'config' => ['Provider configuration is required.'],
            ]);
        }

        /** @var array<string,mixed> $normalized */
        $normalized = $config;
        $driver = $this->drivers->get($driverKey);

        return $driver->checkHealth($normalized);
    }

    /**
     * @param  array<string,mixed>  $attributes
     * @return array<string,mixed>
     *
     * @SuppressWarnings("PMD.StaticAccess")
     */
    public function browseLdap(array $attributes, ?string $baseDn = null): array
    {
        $config = $attributes['config'] ?? null;
        if (! is_array($config) || array_is_list($config)) {
            throw ValidationException::withMessages([
                'config' => ['Provider configuration is required.'],
            ]);
        }

        $driver = $this->drivers->get('ldap');
        if (! $driver instanceof LdapIdpDriver) {
            throw new InvalidArgumentException('LDAP driver is not registered.');
        }

        /** @var array<string,mixed> $input */
        $input = $config;
        $normalized = $driver->normalizeConfig($input);

        return $this->ldapClient->browse($normalized, $baseDn);
    }
}
