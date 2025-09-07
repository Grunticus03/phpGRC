<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Type validation only; bounds are clamped later.
        $v = Validator::make($request->query(), [
            'limit'  => ['sometimes', 'integer'],
            'cursor' => ['sometimes', 'string', 'max:400'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'errors'  => $v->errors(),
            ], 422);
        }

        $limit = (int) $request->query('limit', 25);
        if ($limit < 1) {
            $limit = 1;
        } elseif ($limit > 100) {
            $limit = 100;
        }
        $cursor = (string) $request->query('cursor', '');

        // If a cursor is present, ensure it uses only base64url-safe chars.
        if ($request->has('cursor') && $cursor !== '' && !preg_match('/^[A-Za-z0-9_-]*$/', $cursor)) {
            return response()->json([
                'ok'      => false,
                'code'    => 'VALIDATION_FAILED',
                'message' => 'Validation failed.',
                'errors'  => ['cursor' => ['Invalid cursor.']],
            ], 422);
        }

        // Table missing ⇒ stub fallback.
        if (!Schema::hasTable('audit_events')) {
            return $this->stubResponse($limit, $cursor);
        }

        // Lenient decode (non-fatal). Unknown tokens just don't page.
        [$afterTs, $afterId] = $this->decodeCursorLenient($cursor);

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
            '_retention_days' => (int) config('core.audit.retention_days', 365),
        ], 200);
    }

    private function encodeCursor(string $isoTs, string $id): string
    {
        $j = json_encode(['ts' => $isoTs, 'id' => $id], JSON_THROW_ON_ERROR);
        return rtrim(strtr(base64_encode($j), '+/', '-_'), '=');
    }

    /**
     * Lenient decode; returns [CarbonImmutable|null, string|null]
     *
     * @return array{0: \Carbon\CarbonImmutable|null, 1: string|null}
     */
    private function decodeCursorLenient(string $cursor): array
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

        $slice = array_slice($events, 0, max(1, min(100, $limit)));

        return response()->json([
            'ok'              => true,
            'items'           => $slice,
            'nextCursor'      => null,
            'note'            => 'stub-only',
            '_retention_days' => (int) config('core.audit.retention_days', 365),
        ], 200);
    }
}
