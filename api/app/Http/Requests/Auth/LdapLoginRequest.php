<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use InvalidArgumentException;

final class LdapLoginRequest extends FormRequest
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
            'provider' => ['required', 'string', 'min:1', 'max:160'],
            'username' => ['required', 'string', 'min:1', 'max:255'],
            'password' => ['required', 'string', 'min:1'],
        ];
    }

    /**
     * @param  array<array-key,mixed>|int|string|null  $key
     * @param  mixed  $default
     * @return array{provider:string,username:string,password:string}
     */
    #[\Override]
    public function validated($key = null, $default = null): array
    {
        if (is_array($key)) {
            $key = array_map(static function (mixed $value): string {
                if (! is_string($value) && ! is_int($value)) {
                    throw new InvalidArgumentException('Validation key must be string or int.');
                }

                return (string) $value;
            }, array_values($key));
        }

        /** @var array{provider:string,username:string,password:string} $validated */
        $validated = parent::validated($key, $default);

        return $validated;
    }
}
