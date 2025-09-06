<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use App\Support\Config\ConfigDefaults;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class UpdateSettingsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // wire RBAC later
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $allowedMime = ConfigDefaults::EVIDENCE_ALLOWED_MIME;

        return [
            'rbac'                  => ['sometimes', 'array'],
            'rbac.enabled'          => ['sometimes', 'boolean'],
            'rbac.roles'            => ['sometimes', 'array', 'min:1'],
            'rbac.roles.*'          => ['string', 'min:1', 'max:64'],

            'audit'                 => ['sometimes', 'array'],
            'audit.enabled'         => ['sometimes', 'boolean'],
            'audit.retention_days'  => ['sometimes', 'integer', 'min:1', 'max:730'],

            'evidence'              => ['sometimes', 'array'],
            'evidence.enabled'      => ['sometimes', 'boolean'],
            'evidence.max_mb'       => ['sometimes', 'integer', 'min:1'],
            'evidence.allowed_mime' => ['sometimes', 'array', 'min:1'],
            'evidence.allowed_mime.*' => [Rule::in($allowedMime)],

            'avatars'               => ['sometimes', 'array'],
            'avatars.enabled'       => ['sometimes', 'boolean'],
            // Contract locks canonical size/format this phase
            'avatars.size_px'       => ['sometimes', 'integer', 'in:' . ConfigDefaults::AVATARS_SIZE_PX],
            'avatars.format'        => ['sometimes', Rule::in(['webp'])],
        ];
    }
}
