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
 * Phase 4: validate core.* settings and echo normalized payloads.
 * No persistence. No authorization gating. Deterministic responses.
 */
final class SettingsController extends Controller
{
    public function index(): JsonResponse
    {
        $config = [
            'core' => [
                'rbac' => [
                    'enabled' => (bool) config('core.rbac.enabled', true),
                    'roles'   => (array) config('core.rbac.roles', ['Admin', 'Auditor', 'Risk Manager', 'User']),
                ],
                'audit' => [
                    'enabled'        => (bool) config('core.audit.enabled', true),
                    'retention_days' => (int) config('core.audit.retention_days', 365),
                ],
                'evidence' => [
                    'enabled'      => (bool) config('core.evidence.enabled', true),
                    'max_mb'       => (int) config('core.evidence.max_mb', 25),
                    'allowed_mime' => (array) config('core.evidence.allowed_mime', [
                        'application/pdf', 'image/png', 'image/jpeg', 'text/plain',
                    ]),
                ],
                'avatars' => [
                    'enabled' => (bool) config('core.avatars.enabled', true),
                    'size_px' => (int) config('core.avatars.size_px', 128),
                    'format'  => (string) config('core.avatars.format', 'webp'),
                ],
            ],
        ];

        return response()->json(['ok' => true, 'config' => $config], 200);
    }

    /**
     * POST/PUT /api/admin/settings
     * Accepts either top-level sections or legacy { core: { ... } }.
     * Validate only; do not persist. No audit emissions in Phase 4.
     */
    public function update(Request $request): JsonResponse
    {
        // Normalize to top-level sections
        $payload  = $request->all();
        $sections = Arr::has($payload, 'core') && is_array($payload['core'])
            ? (array) $payload['core']
            : Arr::only($payload, ['rbac', 'audit', 'evidence', 'avatars']);

        $allowedMime = (array) config('core.evidence.allowed_mime', [
            'application/pdf', 'image/png', 'image/jpeg', 'text/plain',
        ]);

        $rules = [
            'rbac'                    => ['sometimes', 'array'],
            'rbac.enabled'            => ['sometimes', 'boolean'],
            'rbac.roles'              => ['sometimes', 'array', 'min:1'],
            'rbac.roles.*'            => ['string', 'min:1', 'max:64'],

            'audit'                   => ['sometimes', 'array'],
            'audit.enabled'           => ['sometimes', 'boolean'],
            'audit.retention_days'    => ['sometimes', 'integer', 'min:1', 'max:730'],

            'evidence'                => ['sometimes', 'array'],
            'evidence.enabled'        => ['sometimes', 'boolean'],
            'evidence.max_mb'         => ['sometimes', 'integer', 'min:1'],
            'evidence.allowed_mime'   => ['sometimes', 'array', 'min:1'],
            'evidence.allowed_mime.*' => [Rule::in($allowedMime)],

            'avatars'                 => ['sometimes', 'array'],
            'avatars.enabled'         => ['sometimes', 'boolean'],
            'avatars.size_px'         => ['sometimes', 'integer', 'in:128'], // spec lock
            'avatars.format'          => ['sometimes', Rule::in(['webp'])],  // spec lock
        ];

        $v = Validator::make($sections, $rules);

        if ($v->fails()) {
            return response()->json([
                'ok'     => false,
                'code'   => 'VALIDATION_FAILED',
                'errors' => $this->nestErrors($v->errors()->toArray()),
            ], 422);
        }

        $accepted = $v->validated();

        return response()->json([
            'ok'       => true,
            'applied'  => false,
            'note'     => 'stub-only',
            'accepted' => $accepted,
        ], 200);
    }

    /**
     * Build nested error arrays using dot-keys, e.g. "avatars.size_px" -> ["avatars"=>["size_px"=>["msg"]]]
     *
     * @param array<string, array<int, string>> $flat
     * @return array<string, mixed>
     */
    private function nestErrors(array $flat): array
    {
        $nested = [];

        foreach ($flat as $key => $messages) {
            Arr::set($nested, $key, array_values($messages));
        }

        return $nested;
    }
}
