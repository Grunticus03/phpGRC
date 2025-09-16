<?php

declare(strict_types=1);

namespace App\Http\Requests\Evidence;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreEvidenceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        $maxMb   = (int) config('core.evidence.max_mb', 25);
        $allowed = (array) config('core.evidence.allowed_mime', [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain',
        ]);

        $maxKb = max(1, $maxMb) * 1024;

        return [
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimetypes:' . implode(',', $allowed)],
        ];
    }

    /** @return array<string,string> */
    #[\Override]
    public function messages(): array
    {
        return [
            'file.required'  => 'File is required.',
            'file.file'      => 'Invalid upload.',
            'file.max'       => 'File exceeds the configured size limit.',
            'file.mimetypes' => 'File type is not allowed.',
        ];
    }

    #[\Override]
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(response()->json([
            'ok'     => false,
            'code'   => 'VALIDATION_FAILED',
            'errors' => $validator->errors(),
        ], 422));
    }

    #[\Override]
    protected function failedAuthorization(): void
    {
        throw new HttpResponseException(response()->json([
            'ok'   => false,
            'code' => 'FORBIDDEN',
        ], 403));
    }
}

