<?php

declare(strict_types=1);

namespace App\Http\Requests\Avatar;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC deferred in Phase 4
    }

    public function rules(): array
    {
        // Spec lock: WEBP only
        $allowed = ['image/webp'];

        // Soft cap for stub validation (2 MB)
        $maxKb = 2048;

        return [
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimetypes:' . implode(',', $allowed)],
        ];
    }

    public function messages(): array
    {
        return [
            'file.required'  => 'File is required.',
            'file.file'      => 'Invalid upload.',
            'file.max'       => 'File exceeds the temporary upload size limit.',
            'file.mimetypes' => 'Only WEBP is allowed.',
        ];
    }
}
