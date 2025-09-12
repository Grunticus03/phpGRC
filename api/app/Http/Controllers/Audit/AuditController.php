<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;

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
            'page'        => ['sometimes', 'integer', 'min:1'],
            'per_page'    => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

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

        $order   = $v['order']    ?? 'desc';
        $perPage = (int) ($v['per_page'] ?? 25);
        $page    = (int) ($v['page']     ?? 1);

        $p = $q->orderBy('occurred_at', $order)->paginate($perPage, ['*'], 'page', $page);

        $data = array_map(
            static function (AuditEvent $e): array {
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
            },
            $p->items()
        );

        return response()->json([
            'ok'         => true,
            'filters'    => $v,
            'data'       => array_values($data),
            'pagination' => [
                'page'       => $p->currentPage(),
                'per_page'   => $p->perPage(),
                'total'      => $p->total(),
                'last_page'  => $p->lastPage(),
                'order'      => $order,
            ],
        ], 200);
    }
}

