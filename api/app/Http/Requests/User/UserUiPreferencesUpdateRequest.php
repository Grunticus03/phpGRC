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
            'sidebar.width' => ['sometimes', 'numeric', 'min:50', 'max:480'],
            'sidebar.order' => ['sometimes', 'array'],
            'sidebar.order.*' => ['string', 'max:64'],
        ];
    }
}
