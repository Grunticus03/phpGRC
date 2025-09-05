<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder MFA enforcement middleware.
 * Phase 2: does nothing. Wiring and checks land in a later task.
 *
 * Intended future logic:
 * - If config('core.auth.mfa.totp.required_for_admin') === true and user is admin,
 *   then require verified TOTP before proceeding.
 */
final class MfaRequired
{
    public function handle(Request $request, Closure $next): Response
    {
        // TODO(Phase 2+): enforce MFA per role and route policy
        return $next($request);
    }
}
