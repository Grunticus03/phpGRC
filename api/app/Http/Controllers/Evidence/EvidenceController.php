<?php

declare(strict_types=1);

namespace App\Http\Controllers\Evidence;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class EvidenceController extends Controller
{
    /**
     * Placeholder: accept upload but do nothing.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'note' => 'stub-only, storage deferred; validate in Phase 5+',
        ], 202);
    }
}
