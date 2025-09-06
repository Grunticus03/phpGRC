<?php

declare(strict_types=1);

namespace App\Http\Requests\Evidence;

use Illuminate\Foundation\Http\FormRequest;

final class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC enforcement deferred in Phase 4.
        return true;
    }

    public function rules(): array
    {
        $maxMb   = (int) config('core.evidence.max_mb', 25);
        $allowed = (array) config('core.evidence.allowed_mime', [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain',
        ]);

        // Laravel's "max" for files is in kilobytes.
        $maxKb = max(1, $maxMb) * 1024;

        return [
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimetypes:' . implode(',', $allowed)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'  => 'File is required.',
            'file.file'      => 'Invalid upload.',
            'file.max'       => 'File exceeds the configured size limit.',
            'file.mimetypes' => 'File type is not allowed.',
        ];
    }
}
