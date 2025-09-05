<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder middleware for break-glass gating.
 * Will check a DB-backed flag in later phases.
 */
final class BreakGlassGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        // TODO: Check core.auth.break_glass.enabled and rate limit
        return $next($request);
    }
}
