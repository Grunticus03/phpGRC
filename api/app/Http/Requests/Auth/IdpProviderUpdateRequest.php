<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class IdpProviderUpdateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        /** @var string|null $providerIdentifier */
        $providerIdentifier = $this->route('provider');
        $ignoreColumn = is_string($providerIdentifier) && $this->isUlid($providerIdentifier) ? 'id' : 'key';

        return [
            'key' => [
                'sometimes',
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                (new Unique('idp_providers', 'key'))->ignore($providerIdentifier, $ignoreColumn),
            ],
            'name' => ['sometimes', 'required', 'string', 'min:3', 'max:160'],
            'driver' => ['sometimes', 'required', 'string', new In(IdpProviderStoreRequest::SUPPORTED_DRIVERS)],
            'enabled' => ['sometimes', 'boolean'],
            'evaluation_order' => ['sometimes', 'integer', 'min:1'],
            'config' => ['sometimes', 'required', 'array'],
            'meta' => ['sometimes', 'nullable', 'array'],
            'last_health_at' => ['sometimes', 'nullable', 'date'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            /** @var mixed $driverInput */
            $driverInput = $this->input('driver');
            $driver = is_string($driverInput) ? $driverInput : '';

            if (! in_array($driver, ['oidc', 'entra'], true)) {
                return;
            }

            /** @var mixed $configInput */
            $configInput = $this->input('config', null);
            if ($configInput === null) {
                return;
            }

            $objectMessage = $driver === 'entra'
                ? 'OIDC/Entra configuration must be an object.'
                : 'OIDC configuration must be an object.';
            if (! is_array($configInput)) {
                $validator->errors()->add('config', $objectMessage);

                return;
            }

            /** @var array<string,mixed> $config */
            $config = $configInput;
            $this->validateOidcConfig($validator, $config, $driver);
        });
    }

    /**
     * @param  array<array-key,mixed>|int|string|null  $key
     * @param  mixed  $default
     * @return array<string,mixed>
     */
    #[\Override]
    public function validated($key = null, $default = null): array
    {
        if (is_array($key)) {
            /** @var list<string> $key */
            $key = array_map(static function (mixed $value): string {
                if (! is_string($value) && ! is_int($value)) {
                    throw new InvalidArgumentException('Validation key must be string or int.');
                }

                return (string) $value;
            }, array_values($key));
        }

        /** @var array<string,mixed> $data */
        $data = parent::validated($key, $default);

        if (array_key_exists('enabled', $data)) {
            $data['enabled'] = (bool) $data['enabled'];
        }

        if (array_key_exists('meta', $data)) {
            /** @var mixed $meta */
            $meta = $data['meta'];
            $data['meta'] = $meta === null ? null : (array) $meta;
        }

        return $data;
    }

    /**
     * @param  array<string,mixed>  $config
     */
    private function validateOidcConfig(Validator $validator, array $config, string $driver): void
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

    private function isUlid(string $value): bool
    {
        if (strlen($value) !== 26) {
            return false;
        }

        return preg_match('/^[0-7][0-9A-HJKMNP-TV-Z]{25}$/', $value) === 1;
    }
}
