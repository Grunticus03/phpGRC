# @phpgrc:/api/app/Http/Middleware/BreakGlassGuard.php
# Purpose: Guard middleware for break-glass route enablement (placeholder)
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class BreakGlassGuard
{
    public function handle(Request $request, Closure $next): Response
    {
        // TODO: check DB flag later
        return $next($request);
    }
}
