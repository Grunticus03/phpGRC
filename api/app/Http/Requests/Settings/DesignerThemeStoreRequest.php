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

    #[\Override]
    protected function prepareForValidation(): void
    {
        /** @var mixed $variables */
        $variables = $this->input('variables');
        if (! is_array($variables)) {
            return;
        }

        /** @var array<string,mixed> $normalized */
        $normalized = [];
        foreach ($variables as $key => $value) {
            if (! is_scalar($value) && $value !== null) {
                continue;
            }

            $normalized[(string) $key] = (string) $value;
        }

        $this->merge([
            'variables' => $normalized,
        ]);
    }
}
