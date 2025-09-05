<?php

declare(strict_types=1);

namespace App\Http\Requests\Avatar;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC enforcement deferred in Phase 4.
        return true;
    }

    public function rules(): array
    {
        // Allowed formats per Phase 4 defaults
        $allowed = ['image/webp', 'image/jpeg', 'image/png'];

        // File size: soft cap at 2 MB for stub. Real limit comes later with processing.
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
            'file.mimetypes' => 'Only WEBP, JPEG, or PNG are allowed.',
        ];
    }
}
