<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

/**
 * Phase 4: validate core.* settings and emit audit events.
 * No settings persistence. Returns note:"stub-only".
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
     * Validate only; do not persist. Emits audit events per section.
     */
    public function update(Request $request, AuditLogger $audit): JsonResponse
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
            'rbac'                    => ['sometimes','array'],
            'rbac.enabled'            => ['sometimes','boolean'],
            'rbac.roles'              => ['sometimes','array','min:1'],
            'rbac.roles.*'            => ['string','min:1','max:64'],

            'audit'                   => ['sometimes','array'],
            'audit.enabled'           => ['sometimes','boolean'],
            'audit.retention_days'    => ['sometimes','integer','min:1','max:730'],

            'evidence'                => ['sometimes','array'],
            'evidence.enabled'        => ['sometimes','boolean'],
            'evidence.max_mb'         => ['sometimes','integer','min:1','max:500'],
            'evidence.allowed_mime'   => ['sometimes','array','min:1'],
            'evidence.allowed_mime.*' => [Rule::in($allowedMime)],

            'avatars'                 => ['sometimes','array'],
            'avatars.enabled'         => ['sometimes','boolean'],
            'avatars.size_px'         => ['sometimes','integer','in:128'], // spec lock
            'avatars.format'          => ['sometimes', Rule::in(['webp'])], // spec lock
        ];

        $v = Validator::make($sections, $rules);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $v->errors(),
            ], 422);
        }

        $accepted = $v->validated();

        // Write audit events per section if table exists and audit enabled.
        $auditLogged = 0;
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $actorId = $request->user()?->id ?? null;
            $ip      = $request->ip();
            $ua      = $request->userAgent();

            foreach ($accepted as $section => $changes) {
                $audit->log([
                    'actor_id'    => $actorId,
                    'action'      => 'settings.update',
                    'category'    => 'SETTINGS',
                    'entity_type' => 'core.config',
                    'entity_id'   => (string) $section,
                    'ip'          => $ip,
                    'ua'          => $ua,
                    'meta'        => ['changes' => (array) $changes, 'applied' => false],
                ]);
                $auditLogged++;
            }
        }

        return response()->json([
            'ok'           => true,
            'applied'      => false,
            'note'         => 'stub-only',
            'accepted'     => $accepted,
            'audit_logged' => $auditLogged,
        ], 200);
    }
}
