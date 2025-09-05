<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class RolesController extends Controller
{
    /**
     * Placeholder: list scaffold roles.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'roles' => ['Admin', 'Auditor', 'Risk Manager', 'User'],
            'note' => 'stub-only, no persistence in Phase 4',
        ]);
    }

    /**
     * Placeholder: create role (no-op).
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'note' => 'stub-only, no persistence in Phase 4',
        ], 202);
    }
}
