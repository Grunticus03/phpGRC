<?php

declare(strict_types=1);

namespace App\Http\Requests\Audit;

use Illuminate\Foundation\Http\FormRequest;

final class ListAuditRequest extends FormRequest
{
    public function authorize(): bool
    {
        // RBAC middleware guards access
        return true;
    }

    /**
     * Controller performs validation with a custom envelope.
     * Keep this empty to avoid default 422 responses.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [];
    }
}

