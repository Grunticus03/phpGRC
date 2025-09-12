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
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        // Controller applies a stricter envelope, so keep rules permissive here.
        return [
            'order'          => ['sometimes', 'in:asc,desc'],
            'limit'          => ['sometimes', 'integer', 'between:1,100'],
            'cursor'         => ['sometimes', 'string'],
            'category'       => ['sometimes', 'string'],
            'action'         => ['sometimes', 'string'],
            'occurred_from'  => ['sometimes', 'date'],
            'occurred_to'    => ['sometimes', 'date'],
            'actor_id'       => ['sometimes', 'integer'],
            'entity_type'    => ['sometimes', 'string'],
            'entity_id'      => ['sometimes', 'string'],
            'ip'             => ['sometimes', 'string'],
        ];
    }
}

