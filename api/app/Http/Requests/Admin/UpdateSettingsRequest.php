<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Arr;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize legacy shape: { core: { ... } }  ->  top-level keys.
     */
    protected function prepareForValidation(): void
    {
        $core = $this->input('core');
        if (is_array($core)) {
            $this->merge(Arr::only($core, ['rbac', 'audit', 'evidence', 'avatars']));
        }
    }

    public function rules(): array
    {
        $allowedMimes = (array) data_get(config('core'), 'evidence.allowed_mime', ['application/pdf']);

        return [
            // RBAC
            'rbac'                => ['sometimes', 'array'],
            'rbac.enabled'        => ['sometimes', 'boolean'],
            'rbac.roles'          => ['sometimes', 'array', 'min:1'],
            'rbac.roles.*'        => ['string', 'min:1', 'max:64'],

            // Audit
            'audit'                => ['sometimes', 'array'],
            'audit.enabled'        => ['sometimes', 'boolean'],
            'audit.retention_days' => ['sometimes', 'integer', 'min:1', 'max:730'],

            // Evidence
            'evidence'                => ['sometimes', 'array'],
            'evidence.enabled'        => ['sometimes', 'boolean'],
            'evidence.max_mb'         => ['sometimes', 'integer', 'min:1'],
            'evidence.allowed_mime'   => ['sometimes', 'array'],
            'evidence.allowed_mime.*' => ['string', Rule::in($allowedMimes)],

            // Avatars
            'avatars'         => ['sometimes', 'array'],
            'avatars.enabled' => ['sometimes', 'boolean'],
            'avatars.size_px' => ['sometimes', Rule::in([128])],
            'avatars.format'  => ['sometimes', Rule::in(['webp'])],

            // Optional switch to apply changes (default false)
            'apply'           => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Return API-shaped validation errors expected by tests.
     *  - code: VALIDATION_FAILED
     *  - errors grouped by top-level section (rbac/audit/evidence/avatars)
     */
    protected function failedValidation(Validator $validator)
    {
        $fieldErrors = $validator->errors()->toArray();

        // Group messages by the first segment (e.g. "avatars" from "avatars.size_px")
        $grouped = [];
        foreach ($fieldErrors as $key => $messages) {
            $top = explode('.', $key, 2)[0];
            $grouped[$top] = array_values(array_unique(array_merge($grouped[$top] ?? [], $messages)));
        }

        throw new HttpResponseException(response()->json([
            'code'    => 'VALIDATION_FAILED',
            'errors'  => $grouped,
            'message' => $validator->errors()->first(),
        ], 422));
    }
}

