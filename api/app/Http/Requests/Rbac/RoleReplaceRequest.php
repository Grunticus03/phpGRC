<?php

declare(strict_types=1);

namespace App\Http\Requests\Rbac;

use Illuminate\Contracts\Validation\Validator as ContractsValidator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Validator as LaravelValidator;

final class RoleReplaceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        // No mutation needed; normalization and duplicate checks run post-validate.
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'roles'   => ['present', 'array'],
            // Names or ids, 2..64, allowed chars only, no whitespace
            'roles.*' => ['string', 'min:2', 'max:64', 'regex:/^[\p{L}\p{N}_-]{2,64}$/u'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'roles.present'  => 'Roles array is required.',
            'roles.array'    => 'Roles must be an array.',
            'roles.*.string' => 'Each role must be a string.',
            'roles.*.min'    => 'Each role must be at least 2 characters.',
            'roles.*.max'    => 'Each role must be at most 64 characters.',
            'roles.*.regex'  => 'Roles may contain only letters, numbers, underscores, and hyphens.',
        ];
    }

    /**
     * Attach duplicate-after-normalization check.
     * @return ContractsValidator
     */
    #[\Override]
    protected function getValidatorInstance(): ContractsValidator
    {
        /** @var ContractsValidator $validator */
        $validator = parent::getValidatorInstance();

        if ($validator instanceof LaravelValidator) {
            $validator->after(function () use ($validator): void {
                /** @var mixed $roles */
                $roles = $this->input('roles', []);
                if (!is_array($roles)) {
                    return;
                }
                /** @var array<string,true> $seen */
                $seen = [];
                foreach ($roles as $r) {
                    if (!is_string($r)) {
                        continue;
                    }
                    /** @var string $collapsed */
                    $collapsed = (string) preg_replace('/\s+/u', ' ', trim($r));
                    $key = mb_strtolower($collapsed, 'UTF-8');
                    if (isset($seen[$key])) {
                        $validator->errors()->add('roles', 'Duplicate roles after normalization.');
                        break;
                    }
                    $seen[$key] = true;
                }
            });
        }

        return $validator;
    }

    #[\Override]
    protected function failedValidation(ContractsValidator $validator): void
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

