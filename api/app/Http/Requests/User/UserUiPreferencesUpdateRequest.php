<?php

declare(strict_types=1);

namespace App\Http\Requests\User;

use Illuminate\Foundation\Http\FormRequest;

final class UserUiPreferencesUpdateRequest extends FormRequest
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
            'theme' => ['nullable', 'string', 'max:64'],
            'mode' => ['nullable', 'string', 'in:light,dark'],
            'overrides' => ['sometimes', 'array'],
            'overrides.*' => ['nullable', 'string', 'max:120'],
            'sidebar' => ['sometimes', 'array'],
            'sidebar.collapsed' => ['sometimes', 'boolean'],
            'sidebar.pinned' => ['sometimes', 'boolean'],
            'sidebar.width' => ['sometimes', 'numeric', 'min:50', 'max:4000'],
            'sidebar.order' => ['sometimes', 'array'],
            'sidebar.order.*' => ['string', 'max:64'],
            'sidebar.hidden' => ['sometimes', 'array'],
            'sidebar.hidden.*' => ['string', 'max:64'],
            'dashboard' => ['sometimes', 'array'],
            'dashboard.widgets' => ['sometimes', 'array'],
            'dashboard.widgets.*.id' => ['nullable', 'string', 'max:100'],
            'dashboard.widgets.*.type' => ['required_with:dashboard.widgets', 'string', 'in:auth-activity,evidence-types,admin-activity'],
            'dashboard.widgets.*.x' => ['required_with:dashboard.widgets', 'integer', 'min:0', 'max:100'],
            'dashboard.widgets.*.y' => ['required_with:dashboard.widgets', 'integer', 'min:0', 'max:100'],
            'dashboard.widgets.*.w' => ['required_with:dashboard.widgets', 'integer', 'min:1', 'max:100'],
            'dashboard.widgets.*.h' => ['required_with:dashboard.widgets', 'integer', 'min:1', 'max:100'],
        ];
    }
}
