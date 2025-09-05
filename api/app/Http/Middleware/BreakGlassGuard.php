<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Break-glass gate.
 * Phase 2: returns 404 when disabled to reduce endpoint disclosure.
 * Enable via config('core.auth.break_glass.enabled') later.
 */
final class BreakGlassGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('core.auth.break_glass.enabled', false)) {
            return response()->json(['error' => 'BREAK_GLASS_DISABLED'], 404);
        }

        // TODO: add rate limit, audit hook, and MFA requirement in later phases
        return $next($request);
    }
}
