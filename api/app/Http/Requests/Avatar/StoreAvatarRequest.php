<?php

declare(strict_types=1);

namespace App\Http\Requests\Avatar;

use Illuminate\Foundation\Http\FormRequest;

final class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth handled elsewhere
    }

    public function rules(): array
    {
        $format = strtolower((string) config('core.avatars.format', 'webp'));
        $maxKb  = (int) config('core.avatars.max_kb', 1024); // soft cap

        return [
            'file' => [
                'required',
                'file',
                'mimes:' . $format, // Phase 4: extension gate only
                'max:' . $maxKb,    // kilobytes
            ],
        ];
    }

    public function messages(): array
    {
        $format = strtolower((string) config('core.avatars.format', 'webp'));

        return [
            'file.required' => 'Avatar file is required.',
            'file.file'     => 'Invalid upload payload.',
            'file.mimes'    => "Only .$format is accepted in Phase 4.",
            'file.max'      => 'Avatar exceeds the allowed size.',
        ];
    }
}
