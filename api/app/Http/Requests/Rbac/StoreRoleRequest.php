<?php

declare(strict_types=1);

namespace App\Http\Requests\Rbac;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

final class StoreRoleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC enforcement comes later; always allow in Phase 4.
        return true;
    }

    public function rules(): array
    {
        $existing = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:64',
                Rule::notIn($existing), // pretend-unique against current config list
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Role name is required.',
            'name.string'   => 'Role name must be a string.',
            'name.min'      => 'Role name must be at least 2 characters.',
            'name.max'      => 'Role name must be at most 64 characters.',
            'name.not_in'   => 'Role already exists.',
        ];
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}
