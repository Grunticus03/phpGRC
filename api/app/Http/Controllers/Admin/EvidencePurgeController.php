<?php

declare(strict_types=1);

namespace App\Http\Controllers\Admin;

use App\Http\Requests\Admin\PurgeEvidenceRequest;
use App\Services\Audit\AuditLogger;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class EvidencePurgeController extends Controller
{
    public function __invoke(PurgeEvidenceRequest $request, AuditLogger $audit): JsonResponse
    {
        if (! Schema::hasTable('evidence')) {
            return new JsonResponse([
                'ok' => true,
                'deleted' => 0,
                'note' => 'evidence-table-missing',
            ], 200);
        }

        /** @var int $deleted */
        $deleted = DB::transaction(static function (): int {
            return DB::table('evidence')->delete();
        });

        if ($deleted > 0 && config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            /** @var mixed $actorIdRaw */
            $actorIdRaw = Auth::id();
            $actorId = is_int($actorIdRaw) ? $actorIdRaw : (is_numeric($actorIdRaw) ? (int) $actorIdRaw : null);

            $meta = [
                'source' => 'admin.evidence.purge',
                'deleted_count' => $deleted,
                'confirm' => true,
            ];

            $user = $request->user();
            if ($user !== null) {
                /** @var mixed $nameAttr */
                $nameAttr = $user->getAttribute('name');
                if (is_string($nameAttr) && trim($nameAttr) !== '') {
                    $meta['actor_username'] = trim($nameAttr);
                }
                /** @var mixed $emailAttr */
                $emailAttr = $user->getAttribute('email');
                if (is_string($emailAttr) && trim($emailAttr) !== '') {
                    $meta['actor_email'] = trim($emailAttr);
                }
            }

            $audit->log([
                'actor_id' => $actorId,
                'action' => 'evidence.purged',
                'category' => 'EVIDENCE',
                'entity_type' => 'evidence',
                'entity_id' => 'all',
                'ip' => $request->ip(),
                'ua' => $request->userAgent(),
                'meta' => $meta,
                'occurred_at' => now(),
            ]);
        }

        $payload = [
            'ok' => true,
            'deleted' => $deleted,
        ];

        if ($deleted === 0) {
            $payload['note'] = 'no-op';
        }

        return new JsonResponse($payload, 200);
    }
}
