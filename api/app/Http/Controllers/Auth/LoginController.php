<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class LoginController extends Controller
{
    /** Placeholder only. Emits audit event. No auth logic. */
    public function login(Request $request, AuditLogger $audit): JsonResponse
    {
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'auth.login',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'login',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => ['applied' => false, 'note' => 'stub-only'],
            ]);
        }

        return response()->json(['ok' => true, 'note' => 'placeholder'], 200);
    }
}
