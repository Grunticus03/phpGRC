<?php

declare(strict_types=1);

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

final class PurgeEvidenceRequest extends FormRequest
{
    /** @override */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, list<string>>
     *
     * @override
     */
    public function rules(): array
    {
        return [
            'confirm' => ['required', 'accepted'],
        ];
    }
}
