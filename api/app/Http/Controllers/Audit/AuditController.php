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
        $v = $request->validate([
            'category'    => ['sometimes', 'string', 'max:64'],
            'action'      => ['sometimes', 'string', 'max:64'],
            'entity_type' => ['sometimes', 'string', 'max:128'],
            'entity_id'   => ['sometimes', 'string', 'max:191'],
            'actor_id'    => ['sometimes', 'integer', 'min:1'],
            'since'       => ['sometimes', 'date'],
            'until'       => ['sometimes', 'date'],
            'order'       => ['sometimes', 'in:asc,desc'],
            'limit'       => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor'      => ['sometimes', 'string', 'regex:/^[A-Za-z0-9:_-]{1,100}$/'],
        ]);

        $limit = (int) ($v['limit'] ?? 25);
        $order = $v['order'] ?? 'desc';
        $cursor = (string) ($v['cursor'] ?? '');

        // Stub path when audit is disabled or table missing
        if (!config('core.audit.enabled', true) || !Schema::hasTable('audit_events')) {
            $items = $this->makeStubItems($limit);
            $next  = $items !== [] ? $this->encodeCursor($items[array_key_last($items)]['occurred_at'], $items[array_key_last($items)]['id']) : null;

            return response()->json([
                'ok'      => true,
                'note'    => 'stub-only',
                'filters' => $this->filterPublic($v),
                'items'   => $items,
                'next'    => $next,
            ], 200);
        }

        // Real path
        $q = AuditEvent::query();

        if (isset($v['category'])) {
            $q->where('category', $v['category']);
        }
        if (isset($v['action'])) {
            $q->where('action', $v['action']);
        }
        if (isset($v['entity_type'])) {
            $q->where('entity_type', $v['entity_type']);
        }
        if (isset($v['entity_id'])) {
            $q->where('entity_id', $v['entity_id']);
        }
        if (isset($v['actor_id'])) {
            $q->where('actor_id', (int) $v['actor_id']);
        }
        if (isset($v['since'])) {
            $q->where('occurred_at', '>=', Carbon::parse($v['since'])->utc());
        }
        if (isset($v['until'])) {
            $q->where('occurred_at', '<=', Carbon::parse($v['until'])->utc());
        }

        // Best-effort cursor support; format ts|id (ISO8601|ULID), base64url or plain "ts:id"
        if ($cursor !== '') {
            $decoded = $this->decodeCursor($cursor);
            if ($decoded !== null) {
                [$ts, $id] = $decoded;
                $q->where(function ($qq) use ($ts, $id, $order) {
                    // Seek pagination by (occurred_at, id)
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
        }

        $rows = $q->orderBy('occurred_at', $order)
                  ->orderBy('id', $order)
                  ->limit($limit + 1) // read one extra for next cursor
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
            'ok'      => true,
            'filters' => $this->filterPublic($v),
            'items'   => $items,
            'next'    => $next,
        ], 200);
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
     * @return array{0:\Carbon\CarbonImmutable,1:string}|null
     */
    private function decodeCursor(string $cursor): ?array
    {
        // Accept base64url token or plain "ts:id"
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
            $ts = Carbon::parse($tsRaw)->utc()->toImmutable();
        } catch (\Throwable) {
            return null;
        }
        return [$ts, $id];
    }

    /** Generate a ULID-like string for stubs */
    private function ulid(): string
    {
        // 26-char Crockford Base32 ULID; simplified stub
        $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';
        $s = '';
        for ($i = 0; $i < 26; $i++) {
            $s .= $alphabet[random_int(0, 31)];
        }
        return $s;
    }

    /** Remove non-public keys from filters payload */
    private function filterPublic(array $v): array
    {
        unset($v['per_page'], $v['page']);
        return $v;
    }
}

