<?php

declare(strict_types=1);

namespace App\Http\Controllers\Avatar;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

final class AvatarController extends Controller
{
    /**
     * Placeholder: avatar upload no-op.
     */
    public function store(Request $request): JsonResponse
    {
        return response()->json([
            'ok' => false,
            'note' => 'stub-only, image processing deferred',
        ], 202);
    }
}
