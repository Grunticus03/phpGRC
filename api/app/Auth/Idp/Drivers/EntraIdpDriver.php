<?php

declare(strict_types=1);

namespace App\Auth\Idp\Drivers;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use Illuminate\Validation\ValidationException;

final class EntraIdpDriver extends OidcIdpDriver
{
    #[\Override]
    public function key(): string
    {
        return 'entra';
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
        $tenantId = $this->requireString($config, 'tenant_id', $errors, 'Tenant ID is required.');

        if ($tenantId !== '' && ! preg_match('/^[0-9a-f-]{8,}$/i', $tenantId)) {
            $this->addError($errors, 'config.tenant_id', 'Tenant ID must be a valid GUID or identifier.');
        }

        if (($config['issuer'] ?? null) === null && $tenantId !== '') {
            $config['issuer'] = sprintf('https://login.microsoftonline.com/%s/v2.0', $tenantId);
        }

        $this->throwIfErrors($errors);

        $normalized = parent::normalizeConfig($config);
        $normalized['tenant_id'] = $tenantId;

        return $normalized;
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
            return IdpHealthCheckResult::failed('Entra configuration invalid.', [
                'errors' => $e->errors(),
            ]);
        }

        return IdpHealthCheckResult::healthy('Entra configuration validated.', [
            'tenant_id' => $normalized['tenant_id'] ?? null,
            'issuer' => $normalized['issuer'] ?? null,
        ]);
    }
}
