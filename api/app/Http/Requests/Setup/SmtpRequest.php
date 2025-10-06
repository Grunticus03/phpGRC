<?php

declare(strict_types=1);

namespace App\Http\Requests\Setup;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class SmtpRequest extends FormRequest
{
    /** @return array<string,mixed> */
    public function rules(): array
    {
        return [
            'host' => ['required', 'string', 'min:1', 'max:255'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'secure' => ['required', Rule::in(['none', 'starttls', 'tls'])],
            'username' => ['nullable', 'string', 'max:190'],
            'password' => ['nullable', 'string', 'max:256'],
            'fromEmail' => ['required', 'email:rfc'],
            'fromName' => ['nullable', 'string', 'max:120'],
            'timeoutSec' => ['nullable', 'integer', 'min:1', 'max:60'],
            'allowInvalidTLS' => ['nullable', 'boolean'],
            'authMethod' => ['nullable', Rule::in(['auto', 'login', 'plain', 'cram-md5'])],
            'testRecipient' => ['nullable', 'email:rfc'],
        ];
    }

    public function authorize(): bool
    {
        return true;
    }
}
