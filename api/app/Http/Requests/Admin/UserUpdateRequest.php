<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Models\User;
use Illuminate\Contracts\Validation\Validator as ContractsValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class UserUpdateRequest extends FormRequest
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
        /** @var mixed $routeUser */
        $routeUser = $this->route('user'); // int|string|User|null

        $uniqueEmail = Rule::unique('users', 'email');

        if ($routeUser instanceof User) {
            $uniqueEmail = $uniqueEmail->ignoreModel($routeUser);
        } elseif (is_int($routeUser) || (is_string($routeUser) && $routeUser !== '')) {
            $uniqueEmail = $uniqueEmail->ignore($routeUser);
        }

        return [
            'name' => ['sometimes', 'string', 'min:2', 'max:255'],
            'email' => ['sometimes', 'string', 'email:rfc,dns', 'max:255', $uniqueEmail],
            'password' => ['sometimes', 'string', 'min:8'],
            'roles' => ['sometimes', 'array'],
            'roles.*' => ['string', 'min:2', 'max:64'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'email.email' => 'Email must be valid.',
            'email.unique' => 'Email is already taken.',
            'password.min' => 'Password must be at least 8 characters.',
        ];
    }

    #[\Override]
    protected function failedValidation(ContractsValidator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok' => false,
            'code' => 'VALIDATION_FAILED',
            'errors' => $validator->errors(),
        ], 422));
    }
}
