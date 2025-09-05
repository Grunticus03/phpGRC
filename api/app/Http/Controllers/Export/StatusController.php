<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

/**
 * Placeholder export status controller.
 */
final class StatusController extends Controller
{
    public function show(string $jobId): JsonResponse
    {
        return response()->json([
            'jobId'   => $jobId,
            'status'  => 'queued', // or 'running','done','error' in future
            'percent' => 0,
            'note'    => 'status placeholder; no background work in Phase 2',
        ]);
    }
}
