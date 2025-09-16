<?php
declare(strict_types=1);

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class AdminCreateRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        $pw = Password::min(12)->mixedCase()->numbers()->symbols();

        return [
            'name'                  => ['required', 'string', 'min:1', 'max:120'],
            'email'                 => ['required', 'email:rfc', 'max:190'],
            'password'              => ['required', $pw],
            'password_confirmation' => ['required', 'same:password'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}

