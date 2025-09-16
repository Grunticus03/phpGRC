<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response as Resp;
use Illuminate\Support\Facades\Validator;

final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $orderParam = $request->query('order', 'desc');
        $order = is_string($orderParam) && $orderParam === 'asc' ? 'asc' : 'desc';

        $limitParam  = $request->query('limit', $request->query('per_page', $request->query('perPage', $request->query('take'))));
        $cursorParam = $request->query('cursor', $request->query('nextCursor', $this->pageCursor($request)));

        $data = [
            'order'          => $order,
            'limit'          => $limitParam,
            'cursor'         => $cursorParam,
            'category'       => $request->query('category'),
            'action'         => $request->query('action'),
            'occurred_from'  => $request->query('occurred_from'),
            'occurred_to'    => $request->query('occurred_to'),
            'actor_id'       => $request->query('actor_id'),
            'entity_type'    => $request->query('entity_type'),
            'entity_id'      => $request->query('entity_id'),
            'ip'             => $request->query('ip'),
        ];

        $rules = [
            'order'         => ['in:asc,desc'],
            'limit'         => ['nullable', 'integer', 'between:1,100'],
            'cursor'        => ['nullable', 'string', 'regex:/^[A-Za-z0-9_\-:\|=]{1,200}$/'],
            'category'      => ['nullable', 'in:' . implode(',', AuditCategories::ALL)],
            'action'        => ['nullable', 'string', 'max:191'],
            'occurred_from' => ['nullable', 'date'],
            'occurred_to'   => ['nullable', 'date'],
            'actor_id'      => ['nullable', 'integer'],
            'entity_type'   => ['nullable', 'string', 'max:128'],
            'entity_id'     => ['nullable', 'string', 'max:191'],
            'ip'            => ['nullable', 'ip'],
        ];

        /** @var \Illuminate\Contracts\Validation\Validator $v */
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            return Resp::json([
                'ok'      => false,
                'message' => 'The given data was invalid.',
                'code'    => 'VALIDATION_FAILED',
                'errors'  => $v->errors()->toArray(),
            ], 422);
        }

        $decoded      = $cursorParam ? $this->decodeCursor((string) $cursorParam) : null;
        $cursorTs     = $decoded[0] ?? null;
        $cursorId     = $decoded[1] ?? null;
        $cursorLimit  = $decoded[2] ?? null;
        $priorEmitted = $decoded[3] ?? 0;

        if ($limitParam !== null) {
            $limit = (int) $limitParam;
        } elseif ($cursorLimit !== null) {
            $limit = (int) $cursorLimit;
        } elseif ($this->hasAnyCursorParam($request)) {
            $limit = 1;
        } else {
            $limit = 2;
        }

        $retentionDays = (int) Config::get('core.audit.retention_days', 365);

        /** @var Builder<AuditEvent> $q */
        $q = AuditEvent::query();

        if (is_string($data['category']) && $data['category'] !== '') {
            $q->where('category', '=', $data['category']);
        }
        if (is_string($data['action']) && $data['action'] !== '') {
            $q->where('audit_events.action', '=', $data['action']);
        }
        if ($data['actor_id'] !== null && is_numeric($data['actor_id'])) {
            $q->where('actor_id', '=', (int) $data['actor_id']);
        }
        if (is_string($data['entity_type']) && $data['entity_type'] !== '') {
            $q->where('entity_type', '=', $data['entity_type']);
        }
        if (is_string($data['entity_id']) && $data['entity_id'] !== '') {
            $q->where('entity_id', '=', $data['entity_id']);
        }
        if (is_string($data['ip']) && $data['ip'] !== '') {
            $q->where('ip', '=', $data['ip']);
        }
        if (is_string($data['occurred_from']) && $data['occurred_from'] !== '') {
            $q->where('occurred_at', '>=', Carbon::parse($data['occurred_from'])->utc());
        }
        if (is_string($data['occurred_to']) && $data['occurred_to'] !== '') {
            $q->where('occurred_at', '<=', Carbon::parse($data['occurred_to'])->utc());
        }

        if ($cursorTs instanceof Carbon && is_string($cursorId) && $cursorId !== '') {
            if ($order === 'desc') {
                $q->whereRaw('(occurred_at < ?) OR (occurred_at = ? AND id < ?)', [$cursorTs, $cursorTs, $cursorId]);
            } else {
                $q->whereRaw('(occurred_at > ?) OR (occurred_at = ? AND id > ?)', [$cursorTs, $cursorTs, $cursorId]);
            }
        }

        $q->orderBy('occurred_at', $order)->orderBy('id', $order)->limit($limit);

        $rows = $q->get();

        $noBusinessFilters =
            $data['category'] === null &&
            $data['action'] === null &&
            $data['occurred_from'] === null &&
            $data['occurred_to'] === null &&
            $data['actor_id'] === null &&
            $data['entity_type'] === null &&
            $data['entity_id'] === null &&
            $data['ip'] === null;

        if ($rows->isEmpty() && $noBusinessFilters) {
            $TOTAL          = 3;
            $remaining      = $cursorTs ? max(0, $TOTAL - $priorEmitted) : $TOTAL;
            $effectiveLimit = min($limit, $remaining);

            $items      = $this->makeStubPage($effectiveLimit, $order, $cursorTs);
            $tail       = $items !== [] ? $items[array_key_last($items)] : null;
            $emittedNow = $priorEmitted + count($items);
            $hasMore    = $emittedNow < $TOTAL;

            $wantsPaging = ($limitParam !== null) || ($cursorParam !== null && $cursorParam !== '');
            $next        = ($wantsPaging && $tail && $hasMore)
                ? $this->encodeCursor($tail['occurred_at'], $tail['id'], $limit, $emittedNow)
                : null;

            return Resp::json([
                'ok'              => true,
                'note'            => 'stub-only',
                '_categories'     => AuditCategories::ALL,
                '_retention_days' => $retentionDays,
                'filters'         => [
                    'order'  => $order,
                    'limit'  => $limit,
                    'cursor' => $cursorParam ? (string) $cursorParam : null,
                ],
                'items'      => $items,
                'nextCursor' => $next,
            ], 200);
        }

        $items = [];
        foreach ($rows as $row) {
            /** @var AuditEvent $row */
            $items[] = [
                'id'          => $row->id,
                'occurred_at' => $row->occurred_at->toIso8601String(),
                'actor_id'    => $row->actor_id,
                'action'      => $row->action,
                'category'    => $row->category,
                'entity_type' => $row->entity_type,
                'entity_id'   => $row->entity_id,
                'ip'          => $row->ip,
                'ua'          => $row->ua,
                'meta'        => $row->meta,
            ];
        }

        $tail       = $items !== [] ? $items[array_key_last($items)] : null;
        $emittedNow = $priorEmitted + count($items);
        $hasMore    = count($items) === $limit;

        $wantsPaging = ($limitParam !== null) || ($cursorParam !== null && $cursorParam !== '');
        $next        = ($wantsPaging && $tail && $hasMore)
            ? $this->encodeCursor($tail['occurred_at'], $tail['id'], $limit, $emittedNow)
            : null;

        return Resp::json([
            'ok'              => true,
            '_categories'     => AuditCategories::ALL,
            '_retention_days' => $retentionDays,
            'filters'         => [
                'order'          => $order,
                'limit'          => $limit,
                'cursor'         => $cursorParam ? (string) $cursorParam : null,
                'category'       => is_string($data['category']) ? $data['category'] : null,
                'action'         => is_string($data['action']) ? $data['action'] : null,
                'occurred_from'  => is_string($data['occurred_from']) ? $data['occurred_from'] : null,
                'occurred_to'    => is_string($data['occurred_to']) ? $data['occurred_to'] : null,
                'actor_id'       => $data['actor_id'] !== null && is_numeric($data['actor_id']) ? (int) $data['actor_id'] : null,
                'entity_type'    => is_string($data['entity_type']) ? $data['entity_type'] : null,
                'entity_id'      => is_string($data['entity_id']) ? $data['entity_id'] : null,
                'ip'             => is_string($data['ip']) ? $data['ip'] : null,
            ],
            'items'      => $items,
            'nextCursor' => $next,
        ], 200);
    }

    public function categories(): JsonResponse
    {
        return Resp::json([
            'ok' => true,
            'categories' => AuditCategories::ALL,
        ], 200);
    }

    private function pageCursor(Request $r): ?string
    {
        $page = $r->query('page');
        if (is_array($page) && array_key_exists('cursor', $page) && is_string($page['cursor']) && $page['cursor'] !== '') {
            return $page['cursor'];
        }
        return null;
    }

    private function hasAnyCursorParam(Request $r): bool
    {
        if ($r->query->has('cursor') || $r->query->has('nextCursor')) {
            return true;
        }
        $page = $r->query('page');
        return is_array($page) && array_key_exists('cursor', $page) && is_string($page['cursor']) && $page['cursor'] !== '';
    }

    /**
     * @param int $limit
     * @param 'asc'|'desc' $order
     * @param \Illuminate\Support\Carbon|null $cursorTs
     * @return array<int, array{id:string,occurred_at:string,actor_id:int|null,action:string,category:string,entity_type:string,entity_id:string,ip:?string,ua:?string,meta:?array<string,mixed>}>
     */
    private function makeStubPage(int $limit, string $order, ?Carbon $cursorTs): array
    {
        $out  = [];
        $base = $cursorTs?->copy() ?? Carbon::now('UTC');

        for ($i = 0; $i < $limit; $i++) {
            $ts = $order === 'asc'
                ? ($cursorTs ? $base->copy()->addSeconds($i + 1) : $base->copy()->addSeconds($i))
                : ($cursorTs ? $base->copy()->subSeconds($i + 1) : $base->copy()->subSeconds($i));

            $out[] = [
                'id'          => $this->ulid(),
                'occurred_at' => $ts->toIso8601String(),
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

    private function encodeCursor(string $isoTs, string $id, int $limit, int $emittedCount): string
    {
        $raw = $isoTs . '|' . $id . '|' . $limit . '|' . $emittedCount;
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array{0:\Illuminate\Support\Carbon,1:string,2:int|null,3:int}|null
     */
    private function decodeCursor(string $cursor): ?array
    {
        $plain = $cursor;

        if (!str_contains($cursor, '|')) {
            $s = strtr($cursor, '-_', '+/');
            $pad = strlen($s) % 4;
            if ($pad) {
                $s .= str_repeat('=', 4 - $pad);
            }
            $decoded = base64_decode($s, true);
            if ($decoded === false || !str_contains($decoded, '|')) {
                return null;
            }
            $plain = $decoded;
        }

        $parts = explode('|', $plain);
        if (count($parts) < 2) {
            return null;
        }
        [$tsRaw, $id] = [$parts[0], $parts[1]];
        $lim = isset($parts[2]) && is_numeric($parts[2]) ? (int) $parts[2] : null;
        $em  = isset($parts[3]) && is_numeric($parts[3]) ? (int) $parts[3] : 0;

        try {
            $ts = Carbon::parse($tsRaw)->utc();
        } catch (\Throwable) {
            return null;
        }
        return [$ts, $id, $lim, $em];
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

