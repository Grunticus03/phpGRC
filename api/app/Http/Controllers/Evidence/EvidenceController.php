<?php

declare(strict_types=1);

namespace App\Http\Controllers\Evidence;

use App\Http\Requests\Evidence\StoreEvidenceRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Phase 4 stub: validates evidence upload only.
 * No storage, hashing, or DB writes this phase.
 */
final class EvidenceController extends Controller
{
    /**
     * POST /api/evidence
     * Accepts multipart/form-data with "file".
     */
    public function store(StoreEvidenceRequest $request): JsonResponse
    {
        // Feature toggle: allow bypass in this phase if disabled.
        if (! (bool) config('core.evidence.enabled', true)) {
            return response()->json([
                'ok'   => false,
                'code' => 'EVIDENCE_NOT_ENABLED',
                'note' => 'stub-only',
            ], 400);
        }

        $f = $request->file('file'); // already validated to exist and meet limits

        // Echo-only metadata; no persistence.
        return response()->json([
            'ok'   => false,
            'note' => 'stub-only',
            'file' => [
                'original_name' => $f->getClientOriginalName(),
                'mime'          => $f->getClientMimeType(),
                'size_bytes'    => $f->getSize(),
            ],
        ]);
    }
}
