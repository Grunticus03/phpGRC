<?php
declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

final class SetupGuard
{
    // STUB ONLY: No implementation per CORE-001 Phase 1
    public function handle(Request $request, Closure $next)
    {
        return $next($request);
    }
}
