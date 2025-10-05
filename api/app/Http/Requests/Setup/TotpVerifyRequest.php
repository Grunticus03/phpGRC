<?php
declare(strict_types=1);

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

final class TotpVerifyRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'code' => ['required', 'regex:/^[0-9]{6}$/'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

