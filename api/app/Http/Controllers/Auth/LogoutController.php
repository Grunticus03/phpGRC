<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class LogoutController extends Controller
{
    /** Placeholder only. Emits audit event. No session/token logic. */
    public function logout(Request $request, AuditLogger $audit): Response
    {
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'auth.logout',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'logout',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => ['applied' => false, 'note' => 'stub-only'],
            ]);
        }

        return response()->noContent(); // 204
    }
}
