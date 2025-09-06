<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Support\Audit\AuditCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Phase 4 stub: returns sample audit events per spec.
 * No DB I/O. Accepts ?limit (1..100) and ?cursor (opaque).
 */
final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit'  => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:200'],
        ]);

        $limit  = (int) ($validated['limit'] ?? 25);
        $cursor = (string) ($validated['cursor'] ?? '');

        $events = [
            [
                'occurred_at' => '2025-09-05T12:00:00Z',
                'actor_id'    => 1,
                'action'      => 'settings.update',
                'category'    => 'SETTINGS',
                'entity_type' => 'core.config',
                'entity_id'   => 'rbac',
                'ip'          => '203.0.113.10',
                'ua'          => 'Mozilla/5.0',
                'meta'        => (object)[],
            ],
            [
                'occurred_at' => '2025-09-05T12:05:00Z',
                'actor_id'    => 1,
                'action'      => 'auth.break_glass.guard',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'break_glass',
                'ip'          => '203.0.113.11',
                'ua'          => 'Mozilla/5.0',
                'meta'        => (object)['enabled' => false],
            ],
        ];

        $slice = array_slice($events, 0, $limit);

        return response()->json([
            'ok'              => true,
            'items'           => $slice,
            'nextCursor'      => null,
            'note'            => 'stub-only',
            '_categories'     => AuditCategories::ALL,
            '_retention_days' => (int) config('core.audit.retention_days', 365),
            '_cursor_echo'    => $cursor,
        ]);
    }
}
