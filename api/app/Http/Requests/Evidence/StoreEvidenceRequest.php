<?php

declare(strict_types=1);

namespace App\Http\Requests\Evidence;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreEvidenceRequest extends FormRequest
{
    #[\Override]
    public function authorize(): bool
    {
        // RBAC enforcement deferred in Phase 4.
        return true;
    }

    #[\Override]
    public function rules(): array
    {
        $maxMb   = (int) config('core.evidence.max_mb', 25);
        $allowed = (array) config('core.evidence.allowed_mime', [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain',
        ]);

        // Laravel's "max" for files is in kilobytes.
        $maxKb = max(1, $maxMb) * 1024;

        return [
            'file' => ['required', 'file', 'max:' . $maxKb, 'mimetypes:' . implode(',', $allowed)],
        ];
    }

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

