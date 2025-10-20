<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SamlMetadataPreviewRequest extends FormRequest
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
            'metadata' => ['required_without:url', 'string', 'min:20'],
            'url' => ['required_without:metadata', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'metadata.required_without' => 'Provide metadata XML or a URL to download it.',
            'url.required_without' => 'Provide metadata XML or a URL to download it.',
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
     * @return array{metadata?:string,url?:string}
     */
    #[\Override]
    public function validated($key = null, $default = null): array
    {
        /** @var array{metadata?:string,url?:string} $data */
        $data = parent::validated($key, $default);

        if (array_key_exists('metadata', $data)) {
            $trimmed = trim($data['metadata']);
            if ($trimmed === '') {
                unset($data['metadata']);
            } else {
                $data['metadata'] = $trimmed;
            }
        }

        if (array_key_exists('url', $data)) {
            $trimmedUrl = trim($data['url']);
            if ($trimmedUrl === '') {
                unset($data['url']);
            } else {
                $data['url'] = $trimmedUrl;
            }
        }

        return $data;
    }
}
