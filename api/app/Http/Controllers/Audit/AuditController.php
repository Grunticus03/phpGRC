<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

/**
 * Phase 4 stub: returns sample audit events.
 * No DB reads/writes. Accepts ?limit (1..100) and ?cursor (opaque).
 */
final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $limit = (int) $request->query('limit', 20);
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 100) {
            $limit = 100;
        }

        $cursor = (string) $request->query('cursor', '');

        // Static sample events (shape per spec)
        $events = [
            [
                'id'          => 'ae_0001',
                'occurred_at' => '2025-09-05T12:00:00Z',
                'actor_id'    => 1,
                'action'      => 'settings.update',
                'entity_type' => 'core.settings',
                'entity_id'   => 'core.rbac.enabled',
                'ip'          => '203.0.113.10',
                'ua'          => 'Mozilla/5.0',
                'meta'        => ['old' => true, 'new' => true, 'note' => 'stub-only'],
            ],
            [
                'id'          => 'ae_0002',
                'occurred_at' => '2025-09-05T12:05:00Z',
                'actor_id'    => 1,
                'action'      => 'auth.break_glass.guard',
                'entity_type' => 'core.auth',
                'entity_id'   => 'break_glass',
                'ip'          => '203.0.113.11',
                'ua'          => 'Mozilla/5.0',
                'meta'        => ['enabled' => false],
            ],
        ];

        // Cursoring is fake in this phase.
        $slice = array_slice($events, 0, $limit);
        $nextCursor = null; // always end in stub

        return response()->json([
            'ok'         => true,
            'items'      => $slice,
            'nextCursor' => $nextCursor,
            'note'       => 'stub-only',
        ]);
    }
}
