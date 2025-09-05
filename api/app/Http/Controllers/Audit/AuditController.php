<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class AuditController extends Controller
{
    /**
     * Placeholder: returns empty audit list.
     */
    public function index(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => true,
            'items' => [],
            'nextCursor' => null,
            'note' => 'stub-only, no writes in Phase 4',
        ]);
    }
}
