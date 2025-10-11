<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class ThemePackUpdateRequest extends FormRequest
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
            'name' => ['sometimes', 'string', 'max:160'],
            'author' => ['sometimes', 'nullable', 'string', 'max:160'],
            'version' => ['sometimes', 'nullable', 'string', 'max:64'],
            'enabled' => ['sometimes', 'boolean'],
        ];
    }
}
