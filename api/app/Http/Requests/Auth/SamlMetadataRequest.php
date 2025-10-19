<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SamlMetadataRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<mixed>>
     */
    public function rules(): array
    {
        return [
            'metadata' => ['required', 'string', 'min:20'],
        ];
    }

    /**
     * @param  array<string>|int|string|null  $key
     * @param  mixed  $default
     *
     * @phpstan-param array<string>|int|string|null $key
     *
     * @psalm-param array<array-key,mixed>|int|string|null $key
     *
     * @return array{metadata:string}
     */
    #[\Override]
    public function validated($key = null, $default = null): array
    {
        /** @var array{metadata:string} $data */
        $data = parent::validated($key, $default);
        $data['metadata'] = trim($data['metadata']);

        return $data;
    }
}
