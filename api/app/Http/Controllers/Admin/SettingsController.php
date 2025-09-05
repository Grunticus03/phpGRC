<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Placeholder Admin Settings controller (no DB I/O, no auth).
 * Returns config-backed placeholders and accepts no-op updates.
 */
final class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        return response()->json([
            'settings' => [
                // Auth feature flags
                'core.auth.local.enabled'                    => (bool) config('core.auth.local.enabled', true),
                'core.auth.break_glass.enabled'              => (bool) config('core.auth.break_glass.enabled', false),

                // MFA TOTP defaults
                'core.auth.mfa.totp.required_for_admin'      => (bool) config('core.auth.mfa.totp.required_for_admin', true),
                'core.auth.mfa.totp.issuer'                  => (string) config('core.auth.mfa.totp.issuer', 'phpGRC'),
                'core.auth.mfa.totp.digits'                  => (int) config('core.auth.mfa.totp.digits', 6),
                'core.auth.mfa.totp.period'                  => (int) config('core.auth.mfa.totp.period', 30),
                'core.auth.mfa.totp.algorithm'               => (string) config('core.auth.mfa.totp.algorithm', 'SHA1'),
            ],
            'note' => 'placeholders only; DB-backed settings land in CORE-003 later',
        ]);
    }

    public function update(Request $request): JsonResponse
    {
        // Placeholder: accept payload but do not persist or validate
        return response()->json([
            'accepted' => true,
            'note'     => 'placeholder; persistence deferred',
        ], 202);
    }
}
