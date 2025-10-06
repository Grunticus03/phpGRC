<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Services\Audit\AuditLogger;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\HttpFoundation\Response;

/**
 * Break-glass gate.
 * Returns 404 when disabled to reduce endpoint disclosure.
 */
final class BreakGlassGuard
{
    public function __construct(private AuditLogger $audit) {}

    /**
     * @param  \Closure(\Illuminate\Http\Request): \Symfony\Component\HttpFoundation\Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $enabled = (bool) config('core.auth.break_glass.enabled', false);

        if (! $enabled) {
            if ((bool) config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
                $aid = Auth::id();
                $actorId = is_int($aid) ? $aid : null;

                $this->audit->log([
                    'actor_id' => $actorId,
                    'action' => 'auth.break_glass.guard',
                    'category' => 'AUTH',
                    'entity_type' => 'core.auth',
                    'entity_id' => 'break_glass',
                    'ip' => $request->ip(),
                    'ua' => $request->userAgent(),
                    /** @var array<string,mixed> */
                    'meta' => ['reason' => 'disabled', 'applied' => false],
                ]);
            }

            return response()->json(['error' => 'BREAK_GLASS_DISABLED'], 404);
        }

        /** @var Response $resp */
        $resp = $next($request);

        return $resp;
    }
}
