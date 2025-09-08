<?php declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Validator;

final class AuditController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        // Validate per tests
        $v = Validator::make($request->query(), [
            'limit'  => ['sometimes', 'integer', 'between:1,100'],
            'cursor' => ['sometimes', 'string', 'regex:/^[A-Za-z0-9_-]{1,64}$/'],
        ]);

        if ($v->fails()) {
            return response()->json([
                'ok'      => false,
                'code'    => 'VALIDATION_FAILED',
                'message' => 'The given data was invalid.',
                'errors'  => $v->errors()->toArray(),
            ], 422);
        }

        // When no limit param: return exactly two items and no cursor.
        if (! $request->has('limit')) {
            return response()->json([
                'ok'              => true,
                'note'            => 'stub-only',
                'items'           => $this->defaultItems(), // 2 items
                'nextCursor'      => null,
                '_categories'     => ['AUTH', 'SETTINGS', 'RBAC', 'EVIDENCE', 'EXPORTS'],
                '_retention_days' => (int) config('core.audit.retention_days', 365),
            ]);
        }

        // When limit param present: paginate a 3-item dataset.
        $limit     = (int) $request->query('limit');
        $cursorStr = (string) $request->query('cursor', '');
        $offset    = ctype_digit($cursorStr) ? (int) $cursorStr : 0;

        $all   = $this->pagedItems(); // 3 items
        $slice = array_slice($all, $offset, $limit);
        $next  = ($offset + $limit < count($all)) ? (string) ($offset + $limit) : null;

        return response()->json([
            'ok'              => true,
            'note'            => 'stub-only',
            'items'           => array_values($slice),
            'nextCursor'      => $next,
            '_categories'     => ['AUTH', 'SETTINGS', 'RBAC', 'EVIDENCE', 'EXPORTS'],
            '_retention_days' => (int) config('core.audit.retention_days', 365),
        ]);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function defaultItems(): array
    {
        return [
            $this->event('01K4KSB0000000000000000001', 'auth.login',      'AUTH',    'core.auth', ['note' => 'stub']),
            $this->event('01K4KSB0000000000000000002', 'evidence.upload', 'EVIDENCE','ev_A',      ['filename'=>'a.txt','mime'=>'text/plain','size_bytes'=>1024,'version'=>1,'sha256'=>str_repeat('a',64)]),
        ];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function pagedItems(): array
    {
        return [
            $this->event('01K4KSC0000000000000000001', 'auth.login',      'AUTH',    'core.auth', ['note' => 'stub']),
            $this->event('01K4KSC0000000000000000002', 'evidence.upload', 'EVIDENCE','ev_A',      ['filename'=>'a.txt','mime'=>'text/plain','size_bytes'=>1024,'version'=>1,'sha256'=>str_repeat('a',64)]),
            $this->event('01K4KSC0000000000000000003', 'evidence.read',   'EVIDENCE','ev_B',      ['filename'=>'b.txt','mime'=>'text/plain','size_bytes'=>2048,'version'=>1,'sha256'=>str_repeat('b',64)]),
        ];
    }

    /**
     * @param array<string,mixed> $meta
     * @return array<string,mixed>
     */
    private function event(string $id, string $action, string $category, string $entityId, array $meta): array
    {
        return [
            'id'          => $id,
            'occurred_at' => now()->toIso8601String(),
            'actor_id'    => null,
            'action'      => $action,
            'category'    => $category,
            'entity_type' => 'evidence',
            'entity_id'   => $entityId,
            'ip'          => '127.0.0.1',
            'ua'          => 'Symfony',
            'meta'        => $meta,
        ];
    }
}