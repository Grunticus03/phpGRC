<?php

declare(strict_types=1);

namespace App\Http\Controllers\Auth;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class TotpController extends Controller
{
    /** Placeholder enroll. Emits audit event. */
    public function enroll(Request $request, AuditLogger $audit): JsonResponse
    {
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'auth.totp.enroll',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'totp',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => ['applied' => false, 'note' => 'stub-only'],
            ]);
        }

        return response()->json([
            'otpauthUri' => 'otpauth://totp/phpGRC:placeholder?secret=PLACEHOLDER&issuer=phpGRC&digits=6&period=30&algorithm=SHA1',
            'secret'     => 'PLACEHOLDER',
        ]);
    }

    /** Placeholder verify. Emits audit event. Always returns ok. */
    public function verify(Request $request, AuditLogger $audit): JsonResponse
    {
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'auth.totp.verify',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'totp',
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => ['applied' => false, 'note' => 'stub-only'],
            ]);
        }

        return response()->json(['ok' => true]);
    }
}
