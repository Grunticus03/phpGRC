<?php
declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class BreakGlassController extends Controller
{
    /** Placeholder only. Should be gated by DB flag in future. */
    public function invoke(): JsonResponse
    {
        // TODO: Enforce DB flag, rate limit, and full audit in later phases
        return response()->json(['accepted' => true], 202);
    }
}
