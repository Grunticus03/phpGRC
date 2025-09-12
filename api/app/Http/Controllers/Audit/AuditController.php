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
        $limitParam  = $request->query('limit', $request->query('per_page', $request->query('perPage', $request->query('take'))));
        $cursorParam = $request->query('cursor', $request->query('nextCursor'));
        $order       = (string) ($request->query('order', 'desc'));

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

        $decoded      = $cursorParam ? $this->decodeCursor((string) $cursorParam) : null;
        $cursorTs     = $decoded[0] ?? null;
        $cursorLimit  = $decoded[2] ?? null;

        // Limit rules:
        // - If explicit limit provided, use it.
        // - Else if cursor carries prior limit, use it.
        // - Else if cursor present without prior limit, default to 1.
        // - Else default to 2 (stub first page).
        if ($limitParam !== null) {
            $limit = (int) $limitParam;
        } elseif ($cursorLimit !== null) {
            $limit = (int) $cursorLimit;
        } elseif ($cursorParam !== null) {
            $limit = 1;
        } else {
            $limit = 2;
        }

        $retention    = (int) config('core.audit.retention_days', 365);

        $items        = $this->makeStubPage($limit, $order, $cursorTs);
        $tail         = $items !== [] ? $items[array_key_last($items)] : null;

        $wantsPaging  = ($limitParam !== null) || ($cursorParam !== null && $cursorParam !== '');
        $next         = ($wantsPaging && $tail)
            ? $this->encodeCursor($tail['occurred_at'], $tail['id'], $limit)
            : null;

        return response()->json([
            'ok'              => true,
            'note'            => 'stub-only',
            '_categories'     => AuditCategories::ALL,
            '_retention_days' => $retention,
            'filters'         => [
                'order'  => $order,
                'limit'  => $limit,
                'cursor' => $cursorParam ? (string) $cursorParam : null,
            ],
            'items'           => $items,
            'nextCursor'      => $next,
        ], 200);
    }

    private function makeStubPage(int $limit, string $order, ?Carbon $cursorTs): array
    {
        $out  = [];
        $base = $cursorTs?->copy() ?? Carbon::now('UTC');

        for ($i = 0; $i < $limit; $i++) {
            if ($order === 'asc') {
                $ts = ($cursorTs ? $base->copy()->addSeconds($i + 1) : $base->copy()->addSeconds($i));
            } else {
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

    private function encodeCursor(string $isoTs, string $id, int $limit): string
    {
        $raw = $isoTs . '|' . $id . '|' . $limit;
        return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
    }

    /**
     * @return array{0:\Illuminate\Support\Carbon,1:string,2:int|null}|null
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

        try {
            $ts = Carbon::parse($tsRaw)->utc();
        } catch (\Throwable) {
            return null;
        }
        return [$ts, $id, $lim];
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

