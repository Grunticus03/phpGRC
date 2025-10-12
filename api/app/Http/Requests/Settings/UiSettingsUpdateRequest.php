<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class UiSettingsUpdateRequest extends FormRequest
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
            'ui' => ['required', 'array'],
            'ui.theme' => ['sometimes', 'array'],
            'ui.theme.default' => ['sometimes', 'string', 'max:64'],
            'ui.theme.allow_user_override' => ['sometimes', 'boolean'],
            'ui.theme.force_global' => ['sometimes', 'boolean'],
            'ui.theme.overrides' => ['sometimes', 'array'],
            'ui.theme.overrides.*' => ['nullable', 'string', 'max:120'],
            'ui.theme.designer' => ['sometimes', 'array'],
            'ui.theme.designer.storage' => ['sometimes', 'string', 'in:browser,filesystem'],
            'ui.theme.designer.filesystem_path' => ['sometimes', 'string', 'max:255'],

            'ui.nav' => ['sometimes', 'array'],
            'ui.nav.sidebar' => ['sometimes', 'array'],
            'ui.nav.sidebar.default_order' => ['sometimes', 'array'],
            'ui.nav.sidebar.default_order.*' => ['string', 'max:64'],

            'ui.brand' => ['sometimes', 'array'],
            'ui.brand.title_text' => ['sometimes', 'string', 'max:120'],
            'ui.brand.favicon_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ui.brand.primary_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ui.brand.secondary_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ui.brand.header_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ui.brand.footer_logo_asset_id' => ['sometimes', 'nullable', 'string', 'max:64'],
            'ui.brand.footer_logo_disabled' => ['sometimes', 'boolean'],
        ];
    }
}
