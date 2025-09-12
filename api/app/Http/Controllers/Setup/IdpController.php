<?php
declare(strict_types=1);

namespace App\Http\Controllers\Setup;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * External IdP config is out of Phase-4 scope. Return IDP_UNSUPPORTED.
 */
final class IdpController extends Controller
{
    public function store(Request $request): JsonResponse
    {
        return response()->json(['ok' => false, 'code' => 'IDP_UNSUPPORTED'], 400);
    }
}

