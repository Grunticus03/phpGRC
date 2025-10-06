<?php

declare(strict_types=1);

namespace App\Http\Controllers\Audit;

use App\Http\Responses\CsvStreamResponse;
use App\Models\AuditEvent;
use App\Support\Audit\AuditCategories;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Response as Resp;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AuditExportController extends Controller
{
    /**
     * CSV export of audit events. Same filters as list. No pagination.
     * Content-Type is exactly text/csv.
     */
    public function exportCsv(Request $request): Response
    {
        if (! Config::get('core.audit.enabled', true)) {
            return Resp::json([
                'ok' => false,
                'code' => 'AUDIT_NOT_ENABLED',
                'note' => 'Audit disabled by configuration.',
            ], 400);
        }

        $o = $request->query('order', 'desc');
        $order = is_string($o) && $o === 'asc' ? 'asc' : 'desc';

        $data = [
            'order' => $order,
            'category' => $request->query('category'),
            'action' => $request->query('action'),
            'occurred_from' => $request->query('occurred_from'),
            'occurred_to' => $request->query('occurred_to'),
            'actor_id' => $request->query('actor_id'),
            'entity_type' => $request->query('entity_type'),
            'entity_id' => $request->query('entity_id'),
            'ip' => $request->query('ip'),
        ];

        $rules = [
            'order' => ['in:asc,desc'],
            'category' => ['nullable', 'in:'.implode(',', AuditCategories::ALL)],
            'action' => ['nullable', 'string', 'max:191'],
            'occurred_from' => ['nullable', 'date'],
            'occurred_to' => ['nullable', 'date'],
            'actor_id' => ['nullable', 'integer'],
            'entity_type' => ['nullable', 'string', 'max:128'],
            'entity_id' => ['nullable', 'string', 'max:191'],
            'ip' => ['nullable', 'ip'],
        ];

        /** @var \Illuminate\Contracts\Validation\Validator $v */
        $v = Validator::make($data, $rules);
        if ($v->fails()) {
            return Resp::json([
                'ok' => false,
                'message' => 'The given data was invalid.',
                'code' => 'VALIDATION_FAILED',
                'errors' => $v->errors()->toArray(),
            ], 422);
        }

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

        $q->orderBy('occurred_at', $order)->orderBy('id', $order);

        $filename = 'audit-'.gmdate('Ymd\THis\Z').'.csv';
        $headers = [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, max-age=0',
        ];

        $useCursor = (bool) Config::get('core.audit.csv_use_cursor', true);

        $callback = function () use ($q, $useCursor): void {
            $out = fopen('php://output', 'wb');
            if ($out === false) {
                return;
            }

            fputcsv($out, [
                'id', 'occurred_at', 'actor_id', 'action', 'category',
                'entity_type', 'entity_id', 'ip', 'ua', 'meta_json',
            ]);

            /** @var iterable<int,\App\Models\AuditEvent> $iter */
            $iter = $useCursor ? $q->cursor() : $q->get();

            foreach ($iter as $row) {
                /** @var array<string,mixed>|null $meta */
                $meta = $row->meta;
                $json = $meta === null ? '' : json_encode($meta, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $metaStr = ($json === false) ? '' : $json;

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

            fflush($out);
            fclose($out);
        };

        return new CsvStreamResponse($callback, 200, $headers);
    }
}
