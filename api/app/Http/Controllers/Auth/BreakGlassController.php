<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class BreakGlassController extends Controller
{
    public function __invoke(): JsonResponse
    {
        // TODO: gate by DB flag in later phase
        return response()->json(['accepted' => true], 202);
    }
}
