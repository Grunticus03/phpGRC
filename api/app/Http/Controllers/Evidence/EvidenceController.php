<?php

declare(strict_types=1);

namespace App\Http\Controllers\Evidence;

use App\Http\Requests\Evidence\StoreEvidenceRequest;
use App\Models\Evidence;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Phase 4: persist evidence bytes in DB with SHA-256 and simple versioning.
 * - Max size and MIME types enforced by StoreEvidenceRequest.
 * - Owner defaults to authenticated user id or 0 when unauthenticated.
 */
final class EvidenceController extends Controller
{
    /**
     * POST /api/evidence
     * Accepts multipart/form-data with "file".
     */
    public function store(StoreEvidenceRequest $request): JsonResponse
    {
        if (! (bool) config('core.evidence.enabled', true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'EVIDENCE_NOT_ENABLED',
            ], 400);
        }

        $uploaded = $request->file('file');

        $bytes        = (string) $uploaded->get();
        $sha256       = hash('sha256', $bytes);
        $ownerId      = (int) (Auth::id() ?? 0);
        $originalName = (string) $uploaded->getClientOriginalName();
        $mime         = $uploaded->getClientMimeType(); // non-nullable; remove ?? default
        $sizeBytes    = (int) $uploaded->getSize();

        /** @var array{id:string,version:int} $saved */
        $saved = DB::transaction(function () use ($ownerId, $originalName, $mime, $sizeBytes, $sha256, $bytes): array {
            // Versioning: increment per owner+filename
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
}
