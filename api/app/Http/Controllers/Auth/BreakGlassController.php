<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class BreakGlassController extends Controller
{
    /** Placeholder only. Emits audit event. Flag enforcement handled by middleware. */
    public function invoke(Request $request, AuditLogger $audit): JsonResponse
    {
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'auth.break_glass.invoke',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'break_glass',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => ['applied' => false, 'note' => 'stub-only'],
            ]);
        }

        return response()->json(['accepted' => true], 202);
    }
}
