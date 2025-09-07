<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 4: list audit events with optional keyset cursor.
 * Clamps limit to 1..100. Falls back to stub if table missing.
 */
final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Clamp instead of 422 to satisfy tests
        $limit = (int) $request->query('limit', 25);
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 100) {
            $limit = 100;
        }
        $cursor = (string) $request->query('cursor', '');

        if (!Schema::hasTable('audit_events')) {
            return $this->stubResponse($limit, $cursor);
        }

        [$afterTs, $afterId] = $this->decodeCursor($cursor);

        $q = AuditEvent::query()
            ->orderByDesc('occurred_at')
            ->orderByDesc('id');

        if ($afterTs !== null && $afterId !== null) {
            $q->where(function ($w) use ($afterTs, $afterId) {
                $w->where('occurred_at', '<', $afterTs)
                  ->orWhere(function ($w2) use ($afterTs, $afterId) {
                      $w2->where('occurred_at', '=', $afterTs)
                         ->where('id', '<', $afterId);
                  });
            });
        }

        $rows = $q->limit($limit + 1)->get();

        $items = $rows->take($limit)->map(static function (AuditEvent $e): array {
            return [
                'id'          => (string) $e->id,
                'occurred_at' => $e->occurred_at->toAtomString(),
                'actor_id'    => $e->actor_id,
                'action'      => $e->action,
                'category'    => $e->category,
                'entity_type' => $e->entity_type,
                'entity_id'   => $e->entity_id,
                'ip'          => $e->ip,
                'ua'          => $e->ua,
                'meta'        => $e->meta ?? (object) [],
            ];
        })->all();

        $hasMore = $rows->count() > $limit;
        $nextCursor = null;

        if ($hasMore) {
            $last = $rows->get($limit - 1);
            $nextCursor = $this->encodeCursor($last->occurred_at->toAtomString(), (string) $last->id);
        }

        return response()->json([
            'ok'              => true,
            'items'           => $items,
            'nextCursor'      => $nextCursor,
            '_categories'     => AuditCategories::ALL,
            '_retention_days' => (int) config('core.audit.retention_days', 365),
            '_cursor_echo'    => $cursor !== '' ? $cursor : null,
        ]);
    }

    private function encodeCursor(string $isoTs, string $id): string
    {
        $j = json_encode(['ts' => $isoTs, 'id' => $id], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($j), '+/', '-_'), '=');
    }

    /**
     * @return array{0: \Carbon\CarbonImmutable|null, 1: string|null}
     */
    private function decodeCursor(string $cursor): array
    {
        if ($cursor === '') {
            return [null, null];
        }
        try {
            $pad = strlen($cursor) % 4;
            if ($pad) {
                $cursor .= str_repeat('=', 4 - $pad);
            }
            $raw = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($raw === false) {
                return [null, null];
            }
            $obj = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            if (!isset($obj['ts'], $obj['id']) || !is_string($obj['ts']) || !is_string($obj['id'])) {
                return [null, null];
            }
            return [\Carbon\CarbonImmutable::parse($obj['ts']), $obj['id']];
        } catch (\Throwable) {
            return [null, null];
        }
    }

    private function stubResponse(int $limit, string $cursor): JsonResponse
    {
        $events = [
            [
                'id'          => 'ae_0001',
                'occurred_at' => '2025-09-05T12:00:00Z',
                'actor_id'    => 1,
                'action'      => 'settings.update',
                'category'    => 'SETTINGS',
                'entity_type' => 'core.config',
                'entity_id'   => 'rbac',
                'ip'          => '203.0.113.10',
                'ua'          => 'Mozilla/5.0',
                'meta'        => (object) [],
            ],
            [
                'id'          => 'ae_0002',
                'occurred_at' => '2025-09-05T12:05:00Z',
                'actor_id'    => 1,
                'action'      => 'auth.break_glass.guard',
                'category'    => 'AUTH',
                'entity_type' => 'core.auth',
                'entity_id'   => 'break_glass',
                'ip'          => '203.0.113.11',
                'ua'          => 'Mozilla/5.0',
                'meta'        => (object) ['enabled' => false],
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
            '_cursor_echo'    => $cursor !== '' ? $cursor : null,
        ]);
    }
}
