<?php

declare(strict_types=1);

namespace App\Http\Requests\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class SamlServiceProviderUpdateRequest extends FormRequest
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
            'sign_authn_requests' => ['required', 'boolean'],
            'want_assertions_signed' => ['required', 'boolean'],
            'want_assertions_encrypted' => ['required', 'boolean'],
        ];
    }
}
