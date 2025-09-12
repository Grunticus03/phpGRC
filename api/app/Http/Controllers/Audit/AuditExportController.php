<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AuditExportController extends Controller
{
    /**
     * CSV export of audit events. Same filters as list. No pagination.
     * Content-Type must be exactly "text/csv".
     *
     * @return \Symfony\Component\HttpFoundation\Response
     */
    public function exportCsv(Request $request): Response
    {
        if (!config('core.audit.enabled', true)) {
            return response()->json([
                'ok'    => false,
                'code'  => 'AUDIT_NOT_ENABLED',
                'note'  => 'Audit disabled by configuration.',
            ], 400);
        }

        $order = (string) ($request->query('order', 'desc')) === 'asc' ? 'asc' : 'desc';

        $data = [
            'order'         => $order,
            'category'      => $request->query('category'),
            'action'        => $request->query('action'),
            'occurred_from' => $request->query('occurred_from'),
            'occurred_to'   => $request->query('occurred_to'),
            'actor_id'      => $request->query('actor_id'),
            'entity_type'   => $request->query('entity_type'),
            'entity_id'     => $request->query('entity_id'),
            'ip'            => $request->query('ip'),
        ];

        $rules = [
            'order'         => ['in:asc,desc'],
            'category'      => ['nullable', 'in:' . implode(',', AuditCategories::ALL)],
            'action'        => ['nullable', 'string', 'max:191'],
            'occurred_from' => ['nullable', 'date'],
            'occurred_to'   => ['nullable', 'date'],
            'actor_id'      => ['nullable', 'integer'],
            'entity_type'   => ['nullable', 'string', 'max:128'],
            'entity_id'     => ['nullable', 'string', 'max:191'],
            'ip'            => ['nullable', 'ip'],
        ];

        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            return response()->json([
                'ok'      => false,
                'message' => 'The given data was invalid.',
                'code'    => 'VALIDATION_FAILED',
                'errors'  => $v->errors()->toArray(),
            ], 422);
        }

        /** @var Builder $q */
        $q = AuditEvent::query();

        if ($data['category'])     { $q->where('category', $data['category']); }
        if ($data['action'])       { $q->where('action', $data['action']); }
        if ($data['actor_id'])     { $q->where('actor_id', (int) $data['actor_id']); }
        if ($data['entity_type'])  { $q->where('entity_type', $data['entity_type']); }
        if ($data['entity_id'])    { $q->where('entity_id', $data['entity_id']); }
        if ($data['ip'])           { $q->where('ip', $data['ip']); }
        if ($data['occurred_from']) {
            $q->where('occurred_at', '>=', Carbon::parse((string) $data['occurred_from'])->utc());
        }
        if ($data['occurred_to']) {
            $q->where('occurred_at', '<=', Carbon::parse((string) $data['occurred_to'])->utc());
        }

        $q->orderBy('occurred_at', $order)->orderBy('id', $order);

        $filename = 'audit-' . gmdate('Ymd\THis\Z') . '.csv';
        $headers  = [
            'Content-Type'            => 'text/csv',
            'Content-Disposition'     => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options'  => 'nosniff',
            'Cache-Control'           => 'no-store, max-age=0',
        ];

        return response()->streamDownload(function () use ($q): void {
            $out = fopen('php://output', 'wb');
            fputcsv($out, [
                'id','occurred_at','actor_id','action','category',
                'entity_type','entity_id','ip','ua','meta_json'
            ]);

            $q->chunk(1000, function ($rows) use ($out): void {
                foreach ($rows as $row) {
                    /** @var \App\Models\AuditEvent $row */
                    $meta = $row->meta;
                    $metaStr = $meta === null ? '' : json_encode($meta, JSON_UNESCAPED_SLASHES);

                    fputcsv($out, [
                        $row->id,
                        $row->occurred_at->toIso8601String(),
                        $row->actor_id,
                        $row->action,
                        $row->category,
                        $row->entity_type,
                        $row->entity_id,
                        $row->ip,
                        $row->ua,
                        $metaStr,
                    ]);
                }
            });

            fflush($out);
            fclose($out);
        }, $filename, $headers);
    }
}

