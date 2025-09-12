<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // ---- Manual validation to satisfy tests (422 with {ok:false}) ----
        $limitParam  = $request->query('limit', $request->query('per_page', $request->query('perPage', $request->query('take'))));
        $cursorParam = $request->query('cursor', $request->query('nextCursor'));

        if ($limitParam !== null) {
            if (!is_numeric($limitParam)) {
                return $this->fail422(['limit' => ['must be an integer between 1 and 100']]);
            }
            $ival = (int) $limitParam;
            if ($ival < 1 || $ival > 100) {
                return $this->fail422(['limit' => ['must be between 1 and 100']]);
            }
        }
        $limit = (int) ($limitParam ?? 25);

        if ($cursorParam !== null) {
            // Only allow base64url or simple ts:id tokens (letters, digits, - _ : |)
            if (!preg_match('/^[A-Za-z0-9_\-:\|=]{1,200}$/', (string) $cursorParam)) {
                return $this->fail422(['cursor' => ['invalid characters']]);
            }
        }
        $order = (string) ($request->query('order', 'desc'));
        if (!in_array($order, ['asc', 'desc'], true)) {
            return $this->fail422(['order' => ['must be asc or desc']]);
        }

        // Optional filters (simple normalization)
        $filters = [
            'category'    => $this->qStr($request, 'category', 64),
            'action'      => $this->qStr($request, 'action', 64),
            'entity_type' => $this->qStr($request, 'entity_type', 128),
            'entity_id'   => $this->qStr($request, 'entity_id', 191),
            'actor_id'    => $this->qInt($request, 'actor_id'),
            'since'       => $this->qDate($request, 'since'),
            'until'       => $this->qDate($request, 'until'),
            'order'       => $order,
            'limit'       => $limit,
            'cursor'      => $cursorParam,
        ];

        // ---- Stub path when disabled or table missing ----
        if (!config('core.audit.enabled', true) || !Schema::hasTable('audit_events')) {
            $items = $this->makeStubItems($limit);
            $next  = $items !== [] ? $this->encodeCursor($items[array_key_last($items)]['occurred_at'], $items[array_key_last($items)]['id']) : null;

            return response()->json([
                'ok'         => true,
                'note'       => 'stub-only',
                'filters'    => $this->publicFilters($filters),
                'items'      => $items,
                'nextCursor' => $next,
            ], 200);
        }

        // ---- Real path ----
        $q = AuditEvent::query();

        if ($filters['category'] !== null) {
            $q->where('category', $filters['category']);
        }
        if ($filters['action'] !== null) {
            $q->where('action', $filters['action']);
        }
        if ($filters['entity_type'] !== null) {
            $q->where('entity_type', $filters['entity_type']);
        }
        if ($filters['entity_id'] !== null) {
            $q->where('entity_id', $filters['entity_id']);
        }
        if ($filters['actor_id'] !== null) {
            $q->where('actor_id', $filters['actor_id']);
        }
        if ($filters['since'] !== null) {
            $q->where('occurred_at', '>=', $filters['since']);
        }
        if ($filters['until'] !== null) {
            $q->where('occurred_at', '<=', $filters['until']);
        }

        // Cursor format: base64url(ts|id) or plain "ts:id"
        $cursor = $filters['cursor'];
        if ($cursor) {
            $decoded = $this->decodeCursor($cursor);
            if ($decoded === null) {
                return $this->fail422(['cursor' => ['invalid cursor']]);
            }
            [$ts, $id] = $decoded;
            $q->where(function ($qq) use ($ts, $id, $order) {
                if ($order === 'desc') {
                    $qq->where('occurred_at', '<', $ts)
                       ->orWhere(function ($qq2) use ($ts, $id) {
                           $qq2->where('occurred_at', '=', $ts)->where('id', '<', $id);
                       });
                } else {
                    $qq->where('occurred_at', '>', $ts)
                       ->orWhere(function ($qq2) use ($ts, $id) {
                           $qq2->where('occurred_at', '=', $ts)->where('id', '>', $id);
                       });
                }
            });
        }

        $rows = $q->orderBy('occurred_at', $order)
                  ->orderBy('id', $order)
                  ->limit($limit + 1)
                  ->get();

        $hasMore = $rows->count() > $limit;
        $slice   = $rows->take($limit);

        $items = $slice->map(static function (AuditEvent $e): array {
            return [
                'id'          => $e->id,
                'occurred_at' => $e->occurred_at->toIso8601String(),
                'actor_id'    => $e->actor_id,
                'action'      => $e->action,
                'category'    => $e->category,
                'entity_type' => $e->entity_type,
                'entity_id'   => $e->entity_id,
                'ip'          => $e->ip,
                'ua'          => $e->ua,
                'meta'        => $e->meta,
            ];
        })->values()->all();

        $next = null;
        if ($hasMore && $slice->isNotEmpty()) {
            /** @var AuditEvent $last */
            $last = $slice->last();
            $next = $this->encodeCursor($last->occurred_at->toIso8601String(), $last->id);
        }

        return response()->json([
            'ok'         => true,
            'filters'    => $this->publicFilters($filters),
            'items'      => $items,
            'nextCursor' => $next,
        ], 200);
    }

    private function qStr(Request $r, string $key, int $max): ?string
    {
        $v = $r->query($key);
        if ($v === null) {
            return null;
        }
        $s = (string) $v;
        if ($s === '' || mb_strlen($s) > $max) {
            return null;
        }
        return $s;
    }

    private function qInt(Request $r, string $key): ?int
    {
        $v = $r->query($key);
        if ($v === null || !is_numeric($v)) {
            return null;
        }
        $i = (int) $v;
        return $i > 0 ? $i : null;
    }

    private function qDate(Request $r, string $key): ?Carbon
    {
        $v = $r->query($key);
        if ($v === null) {
            return null;
        }
        try {
            return Carbon::parse((string) $v)->utc();
        } catch (\Throwable) {
            return null;
        }
    }

    private function fail422(array $errors): JsonResponse
    {
        return response()->json([
            'ok'     => false,
            'code'   => 'VALIDATION_FAILED',
            'errors' => $errors,
        ], 422);
    }

    /**
     * @return array<int, array{id:string,occurred_at:string,actor_id:int|null,action:string,category:string,entity_type:string,entity_id:string,ip:?string,ua:?string,meta:?array}>
     */
    private function makeStubItems(int $limit): array
    {
        $out = [];
        $now = Carbon::now('UTC');
        for ($i = 0; $i < $limit; $i++) {
            $ts = $now->copy()->subSeconds($i)->toIso8601String();
            $out[] = [
                'id'          => $this->ulid(),
                'occurred_at' => $ts,
                'actor_id'    => null,
                'action'      => 'stub.event',
                'category'    => 'SYSTEM',
                'entity_type' => 'stub',
                'entity_id'   => (string) $i,
                'ip'          => null,
                'ua'          => null,
                'meta'        => null,
            ];
        }
        return $out;
    }

    private function encodeCursor(string $isoTs, string $id): string
    {
        $raw = $isoTs . '|' . $id;
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array{0:\Illuminate\Support\Carbon,1:string}|null
     */
    private function decodeCursor(string $cursor): ?array
    {
        $plain = $cursor;
        if (!str_contains($cursor, '|') && !str_contains($cursor, ':')) {
            $decoded = base64_decode(strtr($cursor, '-_', '+/'), true);
            if ($decoded === false || (!str_contains($decoded, '|') && !str_contains($decoded, ':'))) {
                return null;
            }
            $plain = $decoded;
        }
        $sep = str_contains($plain, '|') ? '|' : ':';
        [$tsRaw, $id] = array_pad(explode($sep, $plain, 2), 2, null);
        if ($tsRaw === null || $id === null) {
            return null;
        }
        try {
            $ts = Carbon::parse($tsRaw)->utc();
        } catch (\Throwable) {
            return null;
        }
        return [$ts, $id];
    }

    private function ulid(): string
    {
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $s = '';
        for ($i = 0; $i < 26; $i++) {
            $s .= $alphabet[random_int(0, 31)];
        }
        return $s;
    }

    private function publicFilters(array $f): array
    {
        return [
            'category'    => $f['category'],
            'action'      => $f['action'],
            'entity_type' => $f['entity_type'],
            'entity_id'   => $f['entity_id'],
            'actor_id'    => $f['actor_id'],
            'since'       => $f['since']?->toIso8601String(),
            'until'       => $f['until']?->toIso8601String(),
            'order'       => $f['order'],
            'limit'       => $f['limit'],
            'cursor'      => $f['cursor'],
        ];
    }
}

