<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

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
            'metadata' => ['sometimes', 'required_without:metadata_file', 'string', 'min:20'],
            'metadata_file' => [
                'sometimes',
                'required_without:metadata',
                'file',
                'mimetypes:application/xml,text/xml,application/samlmetadata+xml',
                'max:512',
            ],
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
        /** @var array{metadata?:string} $data */
        $data = parent::validated($key, $default);

        $metadata = null;
        if (array_key_exists('metadata', $data)) {
            $candidate = trim($data['metadata']);
            if ($candidate !== '') {
                $metadata = $candidate;
            }
        }

        $file = $this->file('metadata_file');
        if ($file instanceof UploadedFile) {
            $contents = trim((string) $file->get());
            if ($contents !== '') {
                $metadata = $contents;
            }
        }

        if ($metadata === null) {
            throw ValidationException::withMessages([
                'metadata' => ['Metadata XML is required.'],
            ])->status(422);
        }

        return ['metadata' => $metadata];
    }
}
