<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        // TODO: integrate Sanctum later
        return $next($request);
    }
}
