<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class MeController extends Controller
{
    /** Placeholder only. Always returns a static user stub. */
    public function me(): JsonResponse
    {
        // TODO: Wire to real auth in Phase 2
        return response()->json([
            'user' => ['id' => 0, 'email' => 'placeholder@example.com', 'roles' => []],
        ]);
    }
}
