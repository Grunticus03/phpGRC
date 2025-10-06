<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;

final class DbConfigRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'driver' => ['required', 'in:mysql'],
            'host' => ['required', 'string', 'min:1', 'max:255', 'not_regex:/\s/'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'database' => ['required', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
            'username' => ['required', 'string', 'min:1', 'max:128', 'not_regex:/\s/'],
            'password' => ['nullable', 'string', 'max:256'],
            'charset' => ['nullable', 'string'],
            'collation' => ['nullable', 'string'],
            'options' => ['nullable', 'array'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
