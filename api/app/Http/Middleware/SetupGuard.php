<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;

/**
 * Blocks setup routes when disabled via config.
 * Controllers still perform finer checks and error taxonomy.
 */
final class SetupGuard
{
    public function handle(Request $request, Closure $next): JsonResponse
    {
        if (!Config::get('core.setup.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'SETUP_STEP_DISABLED'], 400);
        }
        return $next($request);
    }
}

