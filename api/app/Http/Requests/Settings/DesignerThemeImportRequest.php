<?php

declare(strict_types=1);

namespace App\Http\Requests\Settings;

use Illuminate\Foundation\Http\FormRequest;

final class DesignerThemeImportRequest extends FormRequest
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
            'file' => ['required', 'file', 'max:256'],
            'slug' => ['sometimes', 'nullable', 'string', 'max:64', 'regex:/^[a-z0-9-]+$/'],
        ];
    }

    #[\Override]
    protected function prepareForValidation(): void
    {
        if (! $this->has('slug')) {
            return;
        }

        /** @var mixed $slug */
        $slug = $this->input('slug');
        if (! is_string($slug)) {
            $this->merge(['slug' => null]);

            return;
        }

        $normalized = strtolower(trim($slug));
        $this->merge([
            'slug' => $normalized !== '' ? $normalized : null,
        ]);
    }
}
