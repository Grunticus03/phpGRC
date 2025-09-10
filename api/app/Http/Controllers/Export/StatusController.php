<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class StatusController extends Controller
{
    /** GET /api/exports/{jobId}/status */
    public function show(string $jobId): JsonResponse
    {
        // If persistence is available, return real status.
        if (Schema::hasTable('exports')) {
            /** @var Export|null $export */
            $export = Export::query()->find($jobId);

            if ($export instanceof Export) {
                return response()->json([
                    'ok'       => true,
                    'status'   => (string) $export->status,
                    'progress' => (int) ($export->progress ?? 0),
                    'id'       => $export->id,
                    'jobId'    => $export->id, // legacy echo
                ], 200);
            }

            // Backward-compatible allowance for stub IDs during transition.
            if (str_starts_with($jobId, 'exp_stub_')) {
                return response()->json([
                    'ok'       => true,
                    'status'   => 'pending',
                    'progress' => 0,
                    'id'       => $jobId,
                    'jobId'    => $jobId,
                    'note'     => 'stub-only',
                ], 200);
            }

            return response()->json([
                'ok'   => false,
                'code' => 'EXPORT_NOT_FOUND',
                'id'   => $jobId,
            ], 404);
        }

        // No table yet: fixed stub.
        return response()->json([
            'ok'       => true,
            'status'   => 'pending',
            'progress' => 0,
            'id'       => $jobId,
            'jobId'    => $jobId,
            'note'     => 'stub-only',
        ], 200);
    }
}

