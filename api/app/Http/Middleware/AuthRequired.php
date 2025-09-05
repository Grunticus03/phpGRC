<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder middleware.
 * Does not enforce authentication yet.
 */
final class AuthRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        // TODO: Enforce auth in Phase 2
        return $next($request);
    }
}
