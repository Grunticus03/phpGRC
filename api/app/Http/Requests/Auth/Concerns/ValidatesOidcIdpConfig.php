<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth\Concerns;

use Illuminate\Validation\Validator;

trait ValidatesOidcIdpConfig
{
    /**
     * @param  array<string,mixed>  $config
     */
    private function validateOidcProviderConfig(Validator $validator, array $config, string $driver): void
    {
        $contextLabel = $driver === 'entra' ? 'OIDC/Entra' : 'OIDC';
        $this->validateOidcIssuer($validator, $config, $driver, $contextLabel);
        $this->validateConfigStringField($validator, $config, 'client_id', $contextLabel);
        $this->validateConfigStringField($validator, $config, 'client_secret', $contextLabel);

        if ($driver === 'entra') {
            $this->validateTenantId($validator, $config);
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function validateOidcIssuer(Validator $validator, array $config, string $driver, string $contextLabel): void
    {
        if ($driver === 'entra' && ! array_key_exists('issuer', $config)) {
            return;
        }

        $this->validateConfigStringField($validator, $config, 'issuer', $contextLabel);
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function validateConfigStringField(Validator $validator, array $config, string $field, string $contextLabel): void
    {
        if (! array_key_exists($field, $config)) {
            $validator->errors()->add("config.$field", "This field is required for {$contextLabel} providers.");

            return;
        }

        /** @var mixed $raw */
        $raw = $config[$field];
        if (! is_string($raw)) {
            $validator->errors()->add("config.$field", 'This field must be a non-empty string.');

            return;
        }

        $value = trim($raw);
        if ($value === '') {
            $validator->errors()->add("config.$field", 'This field must be a non-empty string.');
        }
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function validateTenantId(Validator $validator, array $config): void
    {
        if (! array_key_exists('tenant_id', $config)) {
            $validator->errors()->add('config.tenant_id', 'Tenant ID is required for Entra providers.');

            return;
        }

        /** @var mixed $raw */
        $raw = $config['tenant_id'];
        if (! is_string($raw)) {
            $validator->errors()->add('config.tenant_id', 'Tenant ID is required for Entra providers.');

            return;
        }

        $value = trim($raw);
        if ($value === '') {
            $validator->errors()->add('config.tenant_id', 'Tenant ID is required for Entra providers.');
        }
    }
}
