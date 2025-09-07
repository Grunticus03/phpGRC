<?php

declare(strict_types=1);

namespace App\Http\Controllers\Rbac;

use App\Http\Requests\Rbac\StoreRoleRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Phase 4 stub controller for RBAC roles.
 * GET  /api/rbac/roles → list scaffold roles from config.
 * POST /api/rbac/roles → validate payload, no persistence, echo stub.
 */
final class RolesController extends Controller
{
    public function index(): JsonResponse
    {
        $roles = (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']);

        return response()->json([
            'ok'    => true,
            'roles' => array_values($roles),
        ]);
    }

    public function store(StoreRoleRequest $request): JsonResponse
    {
        return response()->json([
            'ok'   => false,
            'note' => 'stub-only',
            'accepted' => [
                'name' => $request->string('name')->toString(),
            ],
        ], 202);
    }
}
