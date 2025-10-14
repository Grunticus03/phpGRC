<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class BrandAssetUploadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'kind' => ['required', 'string', 'in:primary_logo'],
            'profile_id' => ['required', 'string', 'max:64', 'exists:brand_profiles,id'],
            'file' => [
                'required',
                'file',
                'max:5120', // 5 MB
                'mimetypes:image/png,image/jpeg,image/webp',
            ],
        ];
    }
}
