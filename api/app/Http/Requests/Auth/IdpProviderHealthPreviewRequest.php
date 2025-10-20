<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use App\Http\Requests\Auth\Concerns\ValidatesOidcIdpConfig;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;
use Illuminate\Validation\Validator;
use InvalidArgumentException;

final class IdpProviderHealthPreviewRequest extends FormRequest
{
    use ValidatesOidcIdpConfig;

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
            'driver' => ['required', 'string', new In(IdpProviderStoreRequest::SUPPORTED_DRIVERS)],
            'config' => ['required', 'array'],
            'meta' => ['sometimes', 'nullable', 'array'],
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
            $configInput = $this->input('config', []);
            $objectMessage = $driver === 'entra'
                ? 'OIDC/Entra configuration must be an object.'
                : 'OIDC configuration must be an object.';
            if (! is_array($configInput)) {
                $validator->errors()->add('config', $objectMessage);

                return;
            }

            /** @var array<string,mixed> $config */
            $config = $configInput;
            $this->validateOidcProviderConfig($validator, $config, $driver);
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

        if (array_key_exists('meta', $data)) {
            /** @var mixed $meta */
            $meta = $data['meta'];
            $data['meta'] = $meta === null ? null : (array) $meta;
        }

        return $data;
    }
}
