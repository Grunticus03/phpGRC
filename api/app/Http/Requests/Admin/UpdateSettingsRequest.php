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
    private bool $legacyPayload = false;

    public function authorize(): bool
    {
        return true;
    }

    /**
     * Normalize legacy shape: { core: { ... } } -> top-level keys.
     */
    #[\Override]
    protected function prepareForValidation(): void
    {
        $core = $this->input('core');
        $this->legacyPayload = is_array($core);
        if ($this->legacyPayload) {
            $this->merge(Arr::only($core, ['rbac', 'audit', 'evidence', 'avatars']));
        }
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $allowedMimes = (array) data_get(config('core'), 'evidence.allowed_mime', ['application/pdf']);

        return [
            // RBAC
            'rbac'                 => ['sometimes', 'array'],
            'rbac.enabled'         => ['sometimes', 'boolean'],
            'rbac.require_auth'    => ['sometimes', 'boolean'],
            'rbac.roles'           => ['sometimes', 'array', 'min:1'],
            'rbac.roles.*'         => ['string', 'min:1', 'max:64'],

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
            'apply' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * Validation error envelopes:
     * - Legacy payloads: { errors: { <section>: { <field>: [...] } } }
     * - Spec payloads:   { ok:false, code:"VALIDATION_FAILED", errors:{...}, message:"..." }
     */
    #[\Override]
    protected function failedValidation(Validator $validator): void
    {
        $fieldErrors = $validator->errors()->toArray();

        // Build nested errors[section][field] so tests can assert errors.avatars.size_px, etc.
        $errors = [];
        foreach ($fieldErrors as $key => $messages) {
            $parts   = explode('.', $key);
            $section = $parts[0] ?? '_';
            $field   = $parts[1] ?? $section;
            if (isset($parts[2]) && is_numeric($parts[2])) {
                // keep $field from index 1
            }
            $existing = $errors[$section][$field] ?? [];
            $errors[$section][$field] = array_values(array_unique(array_merge($existing, $messages)));
        }

        if ($this->legacyPayload) {
            throw new HttpResponseException(response()->json([
                'errors' => $errors,
            ], 422));
        }

        throw new HttpResponseException(response()->json([
            'ok'      => false,
            'code'    => 'VALIDATION_FAILED',
            'errors'  => $errors,
            'message' => $validator->errors()->first(),
        ], 422));
    }
}

