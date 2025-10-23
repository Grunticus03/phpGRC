<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class OidcLoginRequest extends FormRequest
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
            'code' => ['sometimes', 'string', 'min:3'],
            'id_token' => ['sometimes', 'string', 'min:10'],
            'redirect_uri' => ['required_with:code', 'sometimes', 'string', 'url'],
            'code_verifier' => ['sometimes', 'string'],
            'nonce' => ['sometimes', 'string'],
            'state' => ['sometimes', 'string'],
        ];
    }

    /**
     * @param  array<array-key, mixed>|int|string|null  $key
     * @param  mixed  $default
     *
     * @phpstan-param array<string>|int|string|null $key
     *
     * @psalm-param array<array-key, mixed>|int|string|null $key
     *
     * @return array{provider:string,code?:string,id_token?:string,redirect_uri?:string,code_verifier?:string,nonce?:string,state?:string}
     */
    #[\Override]
    public function validated($key = null, $default = null): array
    {
        /** @var array{provider:string,code?:string,id_token?:string,redirect_uri?:string,code_verifier?:string,nonce?:string} $validated */
        $validated = parent::validated($key, $default);

        return $validated;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $hasCode = is_string($this->input('code')) && trim((string) $this->input('code')) !== '';
            $hasIdToken = is_string($this->input('id_token')) && trim((string) $this->input('id_token')) !== '';

            if (! $hasCode && ! $hasIdToken) {
                $validator->errors()->add('code', 'Either authorization code or id_token must be provided.');
            }
        });
    }
}
