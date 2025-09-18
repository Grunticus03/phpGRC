<?php

declare(strict_types=1);

namespace App\Http\Controllers\Evidence;

use App\Models\Evidence;
use App\Services\Audit\AuditLogger;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class EvidenceController extends Controller
{
    public function store(Request $request, AuditLogger $audit): JsonResponse
    {
        Gate::authorize('core.evidence.manage');

        if (!(bool) config('core.evidence.enabled', true)) {
            return response()->json(['ok' => false, 'code' => 'EVIDENCE_NOT_ENABLED'], 400);
        }

        $uploaded = $request->file('file');
        if (!($uploaded instanceof UploadedFile)) {
            return response()->json(['ok' => false, 'code' => 'EVIDENCE_FILE_REQUIRED'], 422);
        }

        $bytesRaw     = $uploaded->get();
        $bytes        = is_string($bytesRaw) ? $bytesRaw : '';
        $sha256       = hash('sha256', $bytes);
        $ownerId      = (int) (Auth::id() ?? 0);
        $originalName = $uploaded->getClientOriginalName();

        // Avoid null-coalesce on a value tools think is non-nullable
        /** @var mixed $mimeTmp */
        $mimeTmp = $uploaded->getClientMimeType();
        $mime = (is_string($mimeTmp) && $mimeTmp !== '') ? $mimeTmp : 'application/octet-stream';

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

        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            /** @var mixed $authId */
            $authId  = $request->user()?->getAuthIdentifier();
            $actorId = is_int($authId) ? $authId : (is_string($authId) && ctype_digit($authId) ? (int) $authId : null);

            $audit->log([
                'actor_id'    => $actorId,
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
        if (!$ev) {
            return response()->json(['ok' => false, 'code' => 'EVIDENCE_NOT_FOUND'], 404);
        }

        $etag = '"' . $ev->sha256 . '"';

        // Optional hash verification: ?sha256=<hex>
        $shaQ = $request->query('sha256', '');
        $providedHash = is_string($shaQ) ? strtolower($shaQ) : '';
        if ($providedHash !== '' && !hash_equals($ev->sha256, $providedHash)) {
            return response()->json([
                'ok'       => false,
                'code'     => 'EVIDENCE_HASH_MISMATCH',
                'expected' => $ev->sha256,
                'provided' => $providedHash,
            ], 412);
        }

        $inmRaw = $request->headers->get('If-None-Match');
        $inm = is_string($inmRaw) ? $inmRaw : '';
        if ($inm !== '' && $this->etagMatches($etag, $inm)) {
            return response()->noContent(304);
        }

        $headers = [
            'Content-Type'            => $ev->mime,
            'Content-Length'          => (string) $ev->size_bytes,
            'ETag'                    => $etag,
            'Content-Disposition'     => $this->contentDisposition($ev->filename),
            'X-Content-Type-Options'  => 'nosniff',
            'X-Checksum-SHA256'       => $ev->sha256,
        ];

        if (config('core.audit.enabled', true) && Schema::hasTable('audit_events')) {
            /** @var mixed $authId */
            $authId  = $request->user()?->getAuthIdentifier();
            $actorId = is_int($authId) ? $authId : (is_string($authId) && ctype_digit($authId) ? (int) $authId : null);

            $audit->log([
                'actor_id'    => $actorId,
                'action'      => $request->isMethod('HEAD') ? 'evidence.head' : 'evidence.read',
                'category'    => 'EVIDENCE',
                'entity_type' => 'evidence',
                'entity_id'   => $ev->id,
                'ip'          => $request->ip(),
                'ua'          => $request->userAgent(),
                'meta'        => [
                    'filename'   => $ev->filename,
                    'mime'       => $ev->mime,
                    'size_bytes' => $ev->size_bytes,
                    'sha256'     => $ev->sha256,
                    'version'    => $ev->version,
                ],
            ]);
        }

        if ($request->isMethod('HEAD')) {
            return response('', 200, $headers);
        }

        $body = (string) $ev->getAttribute('bytes');
        return response($body, 200, $headers);
    }

    public function index(Request $request): JsonResponse
    {
        Gate::authorize('core.evidence.manage');

        $limitRaw = $request->query('limit');
        $limit = (is_scalar($limitRaw) && is_numeric($limitRaw)) ? (int) $limitRaw : 20;
        $limit = $limit < 1 ? 1 : ($limit > 100 ? 100 : $limit);

        $orderQ = $request->query('order', 'desc');
        $order = is_string($orderQ) && strtolower($orderQ) === 'asc' ? 'asc' : 'desc';

        $cursorQ = $request->query('cursor', '');
        $cursor = is_string($cursorQ) ? $cursorQ : '';
        $afterTs = null;
        $afterId = null;
        if ($cursor !== '') {
            $decoded = base64_decode($cursor, true);
            if (is_string($decoded)) {
                $parts = explode('|', $decoded, 2);
                if (count($parts) === 2) {
                    [$tsStr, $cid] = $parts;
                    $afterTs = $tsStr;
                    $afterId = $cid;
                }
            }
        }

        /** @var Builder<Evidence> $q */
        $q = Evidence::query()
            ->select(['id','owner_id','filename','mime','size_bytes','sha256','version','created_at']);

        // Filters
        $ownerId = $request->query('owner_id');
        if ($ownerId !== null && is_numeric($ownerId)) {
            $q->where('owner_id', '=', (int) $ownerId);
        }

        $filenameQ = $request->query('filename', '');
        $filename = is_string($filenameQ) ? trim($filenameQ) : '';
        if ($filename !== '') {
            $q->where('filename', 'like', '%'.$this->escapeLike($filename).'%');
        }

        $mimeQ = $request->query('mime', '');
        $mime = is_string($mimeQ) ? trim($mimeQ) : '';
        if ($mime !== '') {
            if (str_ends_with($mime, '/*')) {
                $prefix = substr($mime, 0, -2);
                $q->where('mime', 'like', $this->escapeLike($prefix).'%');
            } else {
                $q->where('mime', '=', $mime);
            }
        }

        $shaExactQ = $request->query('sha256', '');
        $shaExact = is_string($shaExactQ) ? strtolower(trim($shaExactQ)) : '';
        if ($shaExact !== '') {
            $q->where('sha256', '=', $shaExact);
        }
        $shaPrefixQ = $request->query('sha256_prefix', '');
        $shaPrefix = is_string($shaPrefixQ) ? strtolower(trim($shaPrefixQ)) : '';
        if ($shaPrefix !== '') {
            $q->where('sha256', 'like', $this->escapeLike($shaPrefix).'%');
        }

        $vFrom = $request->query('version_from');
        if ($vFrom !== null && is_numeric($vFrom)) {
            $q->where('version', '>=', (int) $vFrom);
        }
        $vTo = $request->query('version_to');
        if ($vTo !== null && is_numeric($vTo)) {
            $q->where('version', '<=', (int) $vTo);
        }

        $createdFromQ = $request->query('created_from', '');
        $createdFrom = is_string($createdFromQ) ? $createdFromQ : '';
        if ($createdFrom !== '') {
            try { $dt = Carbon::parse($createdFrom); $q->where('created_at', '>=', $dt); } catch (\Throwable) {}
        }
        $createdToQ = $request->query('created_to', '');
        $createdTo = is_string($createdToQ) ? $createdToQ : '';
        if ($createdTo !== '') {
            try { $dt = Carbon::parse($createdTo); $q->where('created_at', '<=', $dt); } catch (\Throwable) {}
        }

        $q->orderBy('created_at', $order)->orderBy('id', $order);

        if ($afterTs !== null && $afterId !== null) {
            /** @psalm-suppress InvalidArgument */
            if ($order === 'desc') {
                $q->where(function (Builder $w) use ($afterTs, $afterId): void {
                    $w->where('created_at', '<', $afterTs)
                      ->orWhere(function (Builder $z) use ($afterTs, $afterId): void {
                          $z->where('created_at', '=', $afterTs)
                            ->where('id', '<', $afterId);
                      });
                });
            } else {
                $q->where(function (Builder $w) use ($afterTs, $afterId): void {
                    $w->where('created_at', '>', $afterTs)
                      ->orWhere(function (Builder $z) use ($afterTs, $afterId): void {
                          $z->where('created_at', '=', $afterTs)
                            ->where('id', '>', $afterId);
                      });
                });
            }
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

        $data = $rows->toBase()->map(function ($e): array {
            /** @var CarbonInterface $createdAt */
            $createdAt = $e->created_at;
            return [
                'id'         => $e->id,
                'owner_id'   => $e->owner_id,
                'filename'   => $e->filename,
                'mime'       => $e->mime,
                'size_bytes' => $e->size_bytes,
                'sha256'     => $e->sha256,
                'version'    => $e->version,
                'created_at' => $createdAt->toRfc3339String(),
            ];
        })->values()->all();

        return response()->json([
            'ok'          => true,
            'filters'     => [
                'order'          => $order,
                'limit'          => $limit,
                'owner_id'       => $ownerId !== null && is_numeric($ownerId) ? (int) $ownerId : null,
                'filename'       => $filename !== '' ? $filename : null,
                'mime'           => $mime !== '' ? $mime : null,
                'sha256'         => $shaExact !== '' ? $shaExact : null,
                'sha256_prefix'  => $shaPrefix !== '' ? $shaPrefix : null,
                'version_from'   => $vFrom !== null && is_numeric($vFrom) ? (int) $vFrom : null,
                'version_to'     => $vTo !== null && is_numeric($vTo) ? (int) $vTo : null,
                'created_from'   => $createdFrom !== '' ? $createdFrom : null,
                'created_to'     => $createdTo !== '' ? $createdTo : null,
                'cursor'         => $cursor !== '' ? $cursor : null,
            ],
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

    private function escapeLike(string $value): string
    {
        return str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $value);
    }
}

