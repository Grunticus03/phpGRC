<?php

declare(strict_types=1);

namespace App\Http\Requests\Evidence;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreEvidenceRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        /** @var array<int,string> $allowedMimes */
        $allowedMimes = array_values(array_filter(
            (array) data_get(config('core'), 'evidence.allowed_mime', ['application/pdf']),
            'is_string'
        ));

        /** @var mixed $maxMbRaw */
        $maxMbRaw = data_get(config('core'), 'evidence.max_mb', 10);
        $maxMb = is_int($maxMbRaw) ? $maxMbRaw : (is_string($maxMbRaw) && ctype_digit($maxMbRaw) ? (int) $maxMbRaw : 10);
        /** @var int $maxKb */
        $maxKb = max(1, $maxMb) * 1024;

        $mimeRule = 'mimetypes:' . implode(',', $allowedMimes);
        $maxRule  = 'max:' . (string) $maxKb;

        return [
            'file'     => ['required', 'file', $mimeRule, $maxRule],
            'filename' => ['sometimes', 'string', 'min:1', 'max:255'],
        ];
    }

    /** @return array<string,string> */
    #[\Override]
    public function messages(): array
    {
        return [
            'file.required'   => 'Evidence file is required.',
            'file.file'       => 'Evidence must be an uploaded file.',
            'file.mimetypes'  => 'Unsupported MIME type.',
            'file.max'        => 'File exceeds the configured size limit.',
            'filename.string' => 'Filename must be a string.',
            'filename.min'    => 'Filename must be at least 1 character.',
            'filename.max'    => 'Filename must be at most 255 characters.',
        ];
    }

    /**
     * @override
     */
    #[\Override]
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}

