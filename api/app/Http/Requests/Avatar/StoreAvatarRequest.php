<?php

declare(strict_types=1);

namespace App\Http\Requests\Avatar;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

final class StoreAvatarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // Auth elsewhere
    }

    /** @return array<string,mixed> */
    public function rules(): array
    {
        /** @var mixed $formatRaw */
        $formatRaw = config('core.avatars.format');
        $format = is_string($formatRaw) && $formatRaw !== '' ? strtolower($formatRaw) : 'webp';

        /** @var mixed $maxKbRaw */
        $maxKbRaw = config('core.avatars.max_kb');
        $maxKb = is_int($maxKbRaw) ? $maxKbRaw : (is_numeric($maxKbRaw) ? (int) $maxKbRaw : 1024);
        if ($maxKb < 1) {
            $maxKb = 1;
        }

        return [
            'file' => [
                'required',
                'file',
                'mimes:' . $format,
                'max:' . $maxKb,
            ],
        ];
    }

    /** @return array<string,string> */
    #[\Override]
    public function messages(): array
    {
        /** @var mixed $formatRaw */
        $formatRaw = config('core.avatars.format');
        $format = is_string($formatRaw) && $formatRaw !== '' ? strtolower($formatRaw) : 'webp';

        return [
            'file.required' => 'Avatar file is required.',
            'file.file'     => 'Invalid upload payload.',
            'file.mimes'    => "Only .$format is accepted in Phase 4.",
            'file.max'      => 'Avatar exceeds the allowed size.',
        ];
    }

    #[\Override]
    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json([
                'ok'     => false,
                'code'   => 'AVATAR_VALIDATION_FAILED',
                'note'   => 'stub-only',
                'errors' => $validator->errors(),
            ], 422)
        );
    }
}

