<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\In;
use InvalidArgumentException;

final class LdapDirectoryBrowseRequest extends FormRequest
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
        return [
            'driver' => ['required', 'string', new In(['ldap'])],
            'config' => ['required', 'array'],
            'base_dn' => ['sometimes', 'nullable', 'string'],
        ];
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

        return $data;
    }
}
