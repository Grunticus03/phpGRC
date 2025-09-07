<?php

declare(strict_types=1);

namespace App\Http\Controllers\Evidence;

use App\Http\Requests\Evidence\StoreEvidenceRequest;
use App\Models\Evidence;
use App\Services\Audit\AuditLogger;
use Carbon\CarbonInterface;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EvidenceController extends Controller
{
    public function store(StoreEvidenceRequest $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('core.evidence.manage');

        if (! (bool) config('core.evidence.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'EVIDENCE_NOT_ENABLED'], 400);
        }

        $uploaded = $request->file('file');

        $bytes        = (string) $uploaded->get();
        $sha256       = hash('sha256', $bytes);
        $ownerId      = (int) (Auth::id() ?? 0);
        $originalName = (string) $uploaded->getClientOriginalName();
        $mime         = $uploaded->getClientMimeType();
        $sizeBytes    = (int) $uploaded->getSize();

        /** @var array{id:string,version:int} $saved */
        $saved = DB::transaction(function () use ($ownerId, $originalName, $mime, $sizeBytes, $sha256, $bytes): array {
            $currentMax = (int) Evidence::query()
                ->where('owner_id', $ownerId)
                ->where('filename', $originalName)
                ->lockForUpdate()
                ->max('version');

            $version = $currentMax + 1;
            $id = 'ev_' . (string) Str::ulid();

            Evidence::query()->create([
                'id'         => $id,
                'owner_id'   => $ownerId,
                'filename'   => $originalName,
                'mime'       => $mime,
                'size_bytes' => $sizeBytes,
                'sha256'     => $sha256,
                'version'    => $version,
                'bytes'      => $bytes,
                'created_at' => now(),
            ]);

            return ['id' => $id, 'version' => $version];
        });

        // Audit: evidence.upload
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => 'evidence.upload',
                'category'    => 'EVIDENCE',
                'entity_type' => 'evidence',
                'entity_id'   => $saved['id'],
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => [
                    'filename'   => $originalName,
                    'mime'       => $mime,
                    'size_bytes' => $sizeBytes,
                    'sha256'     => $sha256,
                    'version'    => $saved['version'],
                ],
            ]);
        }

        return response()->json([
            'ok'      => true,
            'id'      => $saved['id'],
            'version' => $saved['version'],
            'sha256'  => $sha256,
            'size'    => $sizeBytes,
            'mime'    => $mime,
            'name'    => $originalName,
        ], 201);
    }

    public function show(Request $request, string $id, AuditLogger $audit): Response
    {
        Gate::authorize('core.evidence.manage');

        /** @var Evidence|null $ev */
        $ev = Evidence::query()->find($id);
        if (! $ev) {
            return response()->json(['ok' => false, 'code' => 'EVIDENCE_NOT_FOUND'], 404);
        }

        $etag = '"'.$ev->sha256.'"';

        $inm = (string) ($request->headers->get('If-None-Match') ?? '');
        if ($inm !== '' && $this->etagMatches($etag, $inm)) {
            // Do not audit cache hits.
            return response()->noContent(304);
        }

        $headers = [
            'Content-Type'            => $ev->mime,
            'Content-Length'          => (string) $ev->size_bytes,
            'ETag'                    => $etag,
            'Content-Disposition'     => $this->contentDisposition($ev->filename),
            'X-Content-Type-Options'  => 'nosniff',
        ];

        // Audit successful read (GET or HEAD)
        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            $audit->log([
                'actor_id'    => $request->user()?->id ?? null,
                'action'      => $request->isMethod('HEAD') ? 'evidence.head' : 'evidence.read',
                'category'    => 'EVIDENCE',
                'entity_type' => 'evidence',
                'entity_id'   => $ev->id,
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => [
                    'filename'   => $ev->filename,
                    'mime'       => $ev->mime,
                    'size_bytes' => (int) $ev->size_bytes,
                    'sha256'     => $ev->sha256,
                    'version'    => (int) $ev->version,
                ],
            ]);
        }

        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        return response($ev->getAttribute('bytes'), 200, $headers);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('core.evidence.manage');

        $limit = (int) $request->query('limit', 20);
        $limit = $limit < 1 ? 1 : ($limit > 100 ? 100 : $limit);

        $cursor = (string) $request->query('cursor', '');
        $afterTs = null;
        $afterId = null;

        // Cursor encodes "Y-m-d H:i:s|<id>"
        if ($cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if (is_string($decoded) && str_contains($decoded, '|')) {
                [$tsStr, $cid] = explode('|', $decoded, 2);
                $afterTs = $tsStr;
                $afterId = $cid;
            }
        }

        $q = Evidence::query()
            ->select(['id','owner_id','filename','mime','size_bytes','sha256','version','created_at'])
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        if ($afterTs !== null && $afterId !== null) {
            $q->where(function ($w) use ($afterTs, $afterId): void {
                $w->where('created_at', '<', $afterTs)
                  ->orWhere(function ($z) use ($afterTs, $afterId): void {
                      $z->where('created_at', '=', $afterTs)
                        ->where('id', '<', $afterId);
                  });
            });
        }

        $rows = $q->limit($limit + 1)->get();

        $hasMore = $rows->count() > $limit;
        if ($hasMore) {
            $rows = $rows->slice(0, $limit)->values();
        }

        $nextCursor = null;
        if ($hasMore && $rows->isNotEmpty()) {
            /** @var Evidence $last */
            $last = $rows->last();
            /** @var CarbonInterface $createdAt */
            $createdAt = $last->created_at;
            $ts = $createdAt->format('Y-m-d H:i:s');
            $nextCursor = base64_encode($ts.'|'.$last->id);
        }

        $data = $rows->map(static function (Evidence $e): array {
            /** @var CarbonInterface $createdAt */
            $createdAt = $e->created_at;
            return [
                'id'         => $e->id,
                'owner_id'   => $e->owner_id,
                'filename'   => $e->filename,
                'mime'       => $e->mime,
                'size_bytes' => (int) $e->size_bytes,
                'sha256'     => $e->sha256,
                'version'    => (int) $e->version,
                'created_at' => $createdAt->toRfc3339String(),
            ];
        });

        return response()->json([
            'ok'          => true,
            'data'        => $data,
            'next_cursor' => $nextCursor,
        ]);
    }

    private function etagMatches(string $etag, string $header): bool
    {
        foreach (explode(',', $header) as $part) {
            $candidate = trim($part);
            if ($candidate === '*' || $candidate === $etag) {
                return true;
            }
        }
        return false;
    }

    private function contentDisposition(string $filename): string
    {
        $fallback = str_replace(['"', '\\'], ['%22', '\\\\'], $filename);
        $utf8 = rawurlencode($filename);
        return 'attachment; filename="'.$fallback.'"; filename*=UTF-8\'\''.$utf8;
    }
}
