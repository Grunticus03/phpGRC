<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Support\Audit\AuditCategories;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;

final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Param aliases used in tests
        $limitParam  = $request->query('limit', $request->query('per_page', $request->query('perPage', $request->query('take'))));
        $cursorParam = $request->query('cursor', $request->query('nextCursor'));
        $order       = (string) ($request->query('order', 'desc'));

        // Validation with Laravel messages the tests expect
        $data = [];
        if ($limitParam !== null)  { $data['limit']  = $limitParam; }
        if ($cursorParam !== null) { $data['cursor'] = $cursorParam; }

        $v = Validator::make($data, [
            'limit'  => ['integer', 'between:1,100'],
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

        $limit       = (int) ($limitParam ?? 25);
        $cursorToken = (string) ($cursorParam ?? '');
        $retention   = (int) config('core.audit.retention_days', 365);

        // Phase 4: return stub-only shape the tests require, with working cursor
        $cursorTs = null;
        if ($cursorToken !== '') {
            $decoded = $this->decodeCursor($cursorToken);
            if ($decoded !== null) {
                [$cursorTs, ] = $decoded; // Illuminate\Support\Carbon
            }
        }

        $items = $this->makeStubPage($limit, $order, $cursorTs);
        $next  = $items !== [] ? $this->encodeCursor($items[array_key_last($items)]['occurred_at'], $items[array_key_last($items)]['id']) : null;

        return response()->json([
            'ok'              => true,
            'note'            => 'stub-only',
            '_categories'     => AuditCategories::ALL,
            '_retention_days' => $retention,
            'filters'         => [
                'order'  => $order,
                'limit'  => $limit,
                'cursor' => $cursorToken !== '' ? $cursorToken : null,
            ],
            'items'           => $items,
            'nextCursor'      => $next,
        ], 200);
    }

    /**
     * Build a deterministic stub page.
     * If $cursorTs is null: page 1 from "now".
     * If provided: start strictly after cursor (older for desc, newer for asc).
     *
     * @param int $limit
     * @param 'asc'|'desc' $order
     * @param \Illuminate\Support\Carbon|null $cursorTs
     * @return array<int, array{id:string,occurred_at:string,actor_id:int|null,action:string,category:string,entity_type:string,entity_id:string,ip:?string,ua:?string,meta:?array}>
     */
    private function makeStubPage(int $limit, string $order, ?Carbon $cursorTs): array
    {
        $out = [];
        $base = $cursorTs?->copy() ?? Carbon::now('UTC');

        for ($i = 0; $i < $limit; $i++) {
            if ($order === 'asc') {
                // After cursor => strictly greater than cursorTs
                $ts = ($cursorTs ? $base->copy()->addSeconds($i + 1) : $base->copy()->addSeconds($i));
            } else {
                // Desc: after cursor means strictly less than cursorTs
                $ts = ($cursorTs ? $base->copy()->subSeconds($i + 1) : $base->copy()->subSeconds($i));
            }

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

