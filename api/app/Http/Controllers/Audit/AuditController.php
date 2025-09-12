<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Validator;

final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Param aliases the tests use
        $limitParam  = $request->query('limit', $request->query('per_page', $request->query('perPage', $request->query('take'))));
        $cursorParam = $request->query('cursor', $request->query('nextCursor'));
        $order       = (string) ($request->query('order', 'desc'));

        // Validation — match expected Laravel messages
        $data = [];
        if ($limitParam !== null)  { $data['limit']  = $limitParam; }
        if ($cursorParam !== null) { $data['cursor'] = $cursorParam; }

        $v = Validator::make($data, [
            'limit'  => ['integer', 'between:1,100'],
            // allow base64url or plain tokens with these chars; invalid chars trigger 422
            'cursor' => ['string', 'regex:/^[A-Za-z0-9_\-:\|=]{1,200}$/'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'The given data was invalid.',
                'code'    => 'VALIDATION_FAILED',
                'errors'  => $v->errors()->toArray(),
            ], 422);
        }

        $limit  = (int) ($limitParam ?? 25);
        $cursor = (string) ($cursorParam ?? '');

        // Stub path when disabled or table missing
        if (!config('core.audit.enabled', true) || !Schema::hasTable('audit_events')) {
            $items = $this->makeStubItems($limit, $order);
            $next  = $items !== [] ? $this->encodeCursor($items[array_key_last($items)]['occurred_at'], $items[array_key_last($items)]['id']) : null;

            return response()->json([
                'ok'          => true,
                'note'        => 'stub-only',
                '_categories' => AuditCategories::ALL,
                'filters'     => [
                    'order'  => $order,
                    'limit'  => $limit,
                    'cursor' => $cursor !== '' ? $cursor : null,
                ],
                'items'       => $items,
                'nextCursor'  => $next,
            ], 200);
        }

        // Real path
        $q = AuditEvent::query();

        if (($v = $request->query('category')) !== null)    { $q->where('category', (string) $v); }
        if (($v = $request->query('action')) !== null)      { $q->where('action', (string) $v); }
        if (($v = $request->query('entity_type')) !== null) { $q->where('entity_type', (string) $v); }
        if (($v = $request->query('entity_id')) !== null)   { $q->where('entity_id', (string) $v); }
        if (($v = $request->query('actor_id')) !== null && is_numeric($v)) { $q->where('actor_id', (int) $v); }
        if (($v = $request->query('since')) !== null)       { $q->where('occurred_at', '>=', Carbon::parse((string) $v)->utc()); }
        if (($v = $request->query('until')) !== null)       { $q->where('occurred_at', '<=', Carbon::parse((string) $v)->utc()); }

        // Cursor: accept valid-looking token; if decode fails, ignore cursor (return first page)
        if ($cursor !== '') {
            $decoded = $this->decodeCursor($cursor);
            if ($decoded !== null) {
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
            'ok'          => true,
            '_categories' => AuditCategories::ALL,
            'filters'     => [
                'order'  => $order,
                'limit'  => $limit,
                'cursor' => $cursor !== '' ? $cursor : null,
            ],
            'items'       => $items,
            'nextCursor'  => $next,
        ], 200);
    }

    /**
     * @return array<int, array{id:string,occurred_at:string,actor_id:int|null,action:string,category:string,entity_type:string,entity_id:string,ip:?string,ua:?string,meta:?array}>
     */
    private function makeStubItems(int $limit, string $order): array
    {
        $out = [];
        $now = Carbon::now('UTC');

        for ($i = 0; $i < $limit; $i++) {
            $ts = ($order === 'asc' ? $now->copy()->addSeconds($i) : $now->copy()->subSeconds($i))->toIso8601String();

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
}

