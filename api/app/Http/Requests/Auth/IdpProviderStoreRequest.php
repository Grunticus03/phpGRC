<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

final class IdpProviderStoreRequest extends FormRequest
{
    public const SUPPORTED_DRIVERS = [
        'oidc',
        'saml',
        'ldap',
        'entra',
    ];

    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'key' => [
                'required',
                'string',
                'min:3',
                'max:64',
                'regex:/^[a-z0-9]+(?:-[a-z0-9]+)*$/',
                Rule::unique('idp_providers', 'key'),
            ],
            'name' => ['required', 'string', 'min:3', 'max:160'],
            'driver' => ['required', 'string', Rule::in(self::SUPPORTED_DRIVERS)],
            'enabled' => ['sometimes', 'boolean'],
            'evaluation_order' => ['sometimes', 'integer', 'min:1'],
            'config' => ['required', 'array'],
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
            if ($driver === 'oidc') {
                /** @var mixed $configInput */
                $configInput = $this->input('config', []);
                if (! is_array($configInput)) {
                    $validator->errors()->add('config', 'OIDC configuration must be an object.');

                    return;
                }

                /** @var array<string,mixed> $configArray */
                $configArray = $configInput;

                foreach (['issuer', 'client_id', 'client_secret'] as $field) {
                    if (
                        ! array_key_exists($field, $configArray)
                        || ! is_string($configArray[$field])
                        || trim($configArray[$field]) === ''
                    ) {
                        $validator->errors()->add("config.$field", 'This field is required for OIDC providers.');
                    }
                }
            }
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
                    throw new \InvalidArgumentException('Validation key must be string or int.');
                }

                return (string) $value;
            }, array_values($key));
        }

        /** @var array<string,mixed> $data */
        $data = parent::validated($key, $default);

        if (isset($data['enabled'])) {
            $data['enabled'] = (bool) $data['enabled'];
        }

        if (array_key_exists('meta', $data)) {
            /** @var mixed $meta */
            $meta = $data['meta'];
            $data['meta'] = $meta === null ? null : (array) $meta;
        }

        return $data;
    }
}
