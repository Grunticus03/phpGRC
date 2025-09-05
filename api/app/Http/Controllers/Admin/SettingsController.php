<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

/**
 * Phase 4 stub: echo core.* settings and validate payloads.
 * No persistence. Returns note:"stub-only".
 */
final class SettingsController extends Controller
{
    /**
     * GET /api/admin/settings
     */
    public function index(): JsonResponse
    {
        $config = [
            'core' => [
                'rbac' => [
                    'enabled' => (bool) config('core.rbac.enabled', true),
                    'roles'   => (array) config('core.rbac.roles', ['Admin','Auditor','Risk Manager','User']),
                ],
                'audit' => [
                    'enabled'        => (bool) config('core.audit.enabled', true),
                    'retention_days' => (int) config('core.audit.retention_days', 365),
                ],
                'evidence' => [
                    'enabled'      => (bool) config('core.evidence.enabled', true),
                    'max_mb'       => (int) config('core.evidence.max_mb', 25),
                    'allowed_mime' => (array) config('core.evidence.allowed_mime', [
                        'application/pdf','image/png','image/jpeg','text/plain',
                    ]),
                ],
                'avatars' => [
                    'enabled' => (bool) config('core.avatars.enabled', true),
                    'size_px' => (int) config('core.avatars.size_px', 128),
                    'format'  => (string) config('core.avatars.format', 'webp'),
                ],
            ],
        ];

        return response()->json(['ok' => true, 'config' => $config]);
    }

    /**
     * POST /api/admin/settings
     * Validate only; do not persist.
     */
    public function update(Request $request): JsonResponse
    {
        $rules = [
            'core.rbac.enabled'            => ['sometimes','boolean'],
            'core.rbac.roles'              => ['sometimes','array','min:1'],
            'core.rbac.roles.*'            => ['string','min:3','max:64'],

            'core.audit.enabled'           => ['sometimes','boolean'],
            'core.audit.retention_days'    => ['sometimes','integer','min:1','max:730'],

            'core.evidence.enabled'        => ['sometimes','boolean'],
            'core.evidence.max_mb'         => ['sometimes','integer','min:1','max:500'],
            'core.evidence.allowed_mime'   => ['sometimes','array','min:1'],
            'core.evidence.allowed_mime.*' => ['string','min:3','max:128'],

            'core.avatars.enabled'         => ['sometimes','boolean'],
            'core.avatars.size_px'         => ['sometimes','integer','min:32','max:1024'],
            'core.avatars.format'          => ['sometimes','in:webp,jpeg,png'],
        ];

        $v = Validator::make($request->all(), $rules);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $v->errors(),
            ], 422);
        }

        $accepted = (array) data_get($request->all(), 'core', []);

        return response()->json([
            'ok'       => true,
            'applied'  => false,
            'note'     => 'stub-only',
            'accepted' => ['core' => $accepted],
        ], 200);
    }
}
