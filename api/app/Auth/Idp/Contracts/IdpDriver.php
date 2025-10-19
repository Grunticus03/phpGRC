<?php

declare(strict_types=1);

namespace App\Auth\Idp\Contracts;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use Illuminate\Validation\ValidationException;

/**
 * Contract implemented by Identity Provider driver adapters.
 */
interface IdpDriver
{
    /**
     * Unique driver identifier (e.g. oidc, saml, ldap, entra).
     */
    public function key(): string;

    /**
     * Validate and normalize the provided configuration payload.
     *
     * @param  array<string,mixed>  $config
     * @return array<string,mixed>
     *
     * @throws ValidationException when configuration is invalid
     */
    public function normalizeConfig(array $config): array;

    /**
     * Execute a health check against the stored configuration.
     *
     * @param  array<string,mixed>  $config
     */
    public function checkHealth(array $config): IdpHealthCheckResult;
}
