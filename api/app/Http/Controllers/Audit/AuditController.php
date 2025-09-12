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

        $limit  = (int) ($limitParam ?? 25);
        $cursor = (string) ($cursorParam ?? '');

        // Phase 4: always return stub-only shape the tests require
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

