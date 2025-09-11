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
        // RBAC enforcement lands later; allow in Phase 4.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    public function rules(): array
    {
        return [
            'name' => [
                'required',
                'string',
                'min:2',
                'max:64',
                Rule::unique('roles', 'name'),
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
            'name.unique'   => 'Role already exists.',
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

