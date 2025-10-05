<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditLogger;
use App\Support\AuthTokenCookie;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\PersonalAccessToken;

final class LogoutController extends Controller
{
    /** Revoke current token, clear cookie, and emit audit event. */
    public function logout(Request $request, AuditLogger $audit): Response
    {
        $token = $request->user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken) {
            $token->delete();
        }

        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'auth.logout',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'logout',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => ['applied' => true],
            ]);
        }

        $response = response()->noContent(); // 204

        return $response->withCookie(AuthTokenCookie::forget($request));
    }
}

