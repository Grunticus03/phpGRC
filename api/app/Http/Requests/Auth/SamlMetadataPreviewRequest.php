<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

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
            'metadata' => ['sometimes', 'required_without_all:url,metadata_file', 'string', 'min:20'],
            'metadata_file' => [
                'sometimes',
                'required_without_all:metadata,url',
                'file',
                'mimetypes:application/xml,text/xml,application/samlmetadata+xml',
                'max:512', // 512 KB
            ],
            'url' => ['sometimes', 'required_without_all:metadata,metadata_file', 'string', 'url', 'max:2048'],
        ];
    }

    /**
     * @return array<string, string>
     */
    #[\Override]
    public function messages(): array
    {
        return [
            'metadata.required_without_all' => 'Provide metadata XML, an upload, or a URL to download it.',
            'metadata_file.required_without_all' => 'Provide metadata XML, an upload, or a URL to download it.',
            'url.required_without_all' => 'Provide metadata XML, an upload, or a URL to download it.',
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
            }

            if ($trimmed !== '') {
                $data['metadata'] = $trimmed;
            }
        }

        $file = $this->file('metadata_file');
        if ($file instanceof UploadedFile) {
            $contents = trim((string) $file->get());
            if ($contents !== '') {
                $data['metadata'] = $contents;
            }
        }

        if (array_key_exists('url', $data)) {
            $trimmedUrl = trim($data['url']);
            if ($trimmedUrl === '') {
                unset($data['url']);
            }

            if ($trimmedUrl !== '') {
                $data['url'] = $trimmedUrl;
            }
        }

        return $data;
    }
}
