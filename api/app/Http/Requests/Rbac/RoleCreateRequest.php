<?php

declare(strict_types=1);

namespace App\Http\Requests\Rbac;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

final class RoleCreateRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        /** @var mixed $name */
        $name = $this->input('name');
        if (is_string($name)) {
            /** @var string $collapsed */
            $collapsed = (string) preg_replace('/\s+/u', ' ', trim($name));
            $this->merge(['name' => $collapsed]);
        }
    }

    private function persistenceEnabled(): bool
    {
        /** @var bool $flag */
        $flag = (bool) config('core.rbac.persistence', false);
        /** @var mixed $mode */
        $mode = config('core.rbac.mode');
        return $flag || $mode === 'persist' || $mode === 'db';
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $base = [
            // 2..64 chars, Unicode letters/digits/_/-, no whitespace
            'name' => ['bail', 'required', 'string', 'min:2', 'max:64', 'regex:/^[\p{L}\p{N}_-]{2,64}$/u'],
        ];

        if (!$this->persistenceEnabled()) {
            /** @var array<int,string> $existing */
            $existing = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);
            $base['name'][] = Rule::notIn($existing);
            return $base;
        }

        if (Schema::hasTable('roles')) {
            $base['name'][] = Rule::unique('roles', 'name');
        }

        return $base;
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'name.required' => 'Role name is required.',
            'name.string'   => 'Role name must be a string.',
            'name.min'      => 'Role name must be at least 2 characters.',
            'name.max'      => 'Role name must be at most 64 characters.',
            'name.regex'    => 'Role name may contain only letters, numbers, underscores, and hyphens.',
            'name.unique'   => 'Role already exists.',
            'name.not_in'   => 'Role already exists.',
        ];
    }

    #[\Override]
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'ok'      => false,
                'code'    => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'errors'  => $validator->errors(),
            ], 422)
        );
    }
}

