<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 4 stub: echo core.* settings and validate payloads.
 * No persistence. Returns note:"stub-only".
 */
final class SettingsController extends Controller
{
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
     * POST/PUT /api/admin/settings
     * Accepts either top-level sections or legacy { core: { ... } }.
     * Validate only; do not persist.
     */
    public function update(Request $request): JsonResponse
    {
        $payload = $request->all();

        // Normalize to top-level sections
        $sections = Arr::has($payload, 'core') && is_array($payload['core'])
            ? (array) $payload['core']
            : Arr::only($payload, ['rbac','audit','evidence','avatars']);

        $allowedMime = (array) config('core.evidence.allowed_mime', [
            'application/pdf','image/png','image/jpeg','text/plain',
        ]);

        $rules = [
            'rbac'                  => ['sometimes','array'],
            'rbac.enabled'          => ['sometimes','boolean'],
            'rbac.roles'            => ['sometimes','array','min:1'],
            'rbac.roles.*'          => ['string','min:1','max:64'],

            'audit'                 => ['sometimes','array'],
            'audit.enabled'         => ['sometimes','boolean'],
            'audit.retention_days'  => ['sometimes','integer','min:1','max:730'],

            'evidence'              => ['sometimes','array'],
            'evidence.enabled'      => ['sometimes','boolean'],
            'evidence.max_mb'       => ['sometimes','integer','min:1','max:500'],
            'evidence.allowed_mime' => ['sometimes','array','min:1'],
            'evidence.allowed_mime.*' => [Rule::in($allowedMime)],

            'avatars'               => ['sometimes','array'],
            'avatars.enabled'       => ['sometimes','boolean'],
            'avatars.size_px'       => ['sometimes','integer','in:128'], // spec lock
            'avatars.format'        => ['sometimes', Rule::in(['webp'])], // spec lock
        ];

        $v = Validator::make($sections, $rules);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $v->errors(),
            ], 422);
        }

        return response()->json([
            'ok'       => true,
            'applied'  => false,
            'note'     => 'stub-only',
            'accepted' => $v->validated(),
        ], 200);
    }
}
