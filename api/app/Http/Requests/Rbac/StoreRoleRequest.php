<?php

declare(strict_types=1);

namespace App\Http\Requests\Rbac;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class StoreRoleRequest extends FormRequest
{
    #[\Override]
    public function authorize(): bool
    {
        // RBAC enforcement lands later; allow in Phase 4.
        return true;
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        $name = $this->input('name');
        if (is_string($name)) {
            $this->merge(['name' => trim($name)]);
        }
    }

    private function persistenceEnabled(): bool
    {
        /** @var bool $flag */
        $flag = (bool) config('core.rbac.persistence', false);
        /** @var mixed $mode */
        $mode = config('core.rbac.mode');
        return $flag || $mode === 'persist';
    }

    #[\Override]
    public function rules(): array
    {
        $base = [
            'name' => ['required', 'string', 'min:2', 'max:64'],
        ];

        if (!$this->persistenceEnabled()) {
            /** @var array<int,string> $existing */
            $existing = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);
            $base['name'][] = Rule::notIn($existing); // pretend-unique against config list
            return $base;
        }

        if (Schema::hasTable('roles')) {
            $base['name'][] = Rule::unique('roles', 'name');
        }

        return $base;
    }

    #[\Override]
    public function messages(): array
    {
        return [
            'name.required' => 'Role name is required.',
            'name.string'   => 'Role name must be a string.',
            'name.min'      => 'Role name must be at least 2 characters.',
            'name.max'      => 'Role name must be at most 64 characters.',
            'name.unique'   => 'Role already exists.',
            'name.not_in'   => 'Role already exists.',
        ];
    }

    #[\Override]
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

