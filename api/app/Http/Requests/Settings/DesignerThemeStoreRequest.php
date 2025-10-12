<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class DesignerThemeStoreRequest extends FormRequest
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
            'name' => ['required', 'string', 'max:160'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
            'variables' => ['required', 'array', 'min:1'],
            'variables.*' => ['string', 'max:160'],
        ];
    }
}
