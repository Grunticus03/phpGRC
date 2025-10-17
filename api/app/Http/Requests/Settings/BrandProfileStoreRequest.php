<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class BrandProfileStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:120'],
            'source_profile_id' => ['sometimes', 'string', 'max:64', 'exists:brand_profiles,id'],
            'brand' => ['sometimes', 'array'],
            'brand.title_text' => ['sometimes', 'string', 'max:120'],
            'brand.favicon_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.primary_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.secondary_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.header_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.footer_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.background_login_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.background_main_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'brand.footer_logo_disabled' => ['sometimes', 'boolean'],
        ];
    }
}
