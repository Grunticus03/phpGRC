<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class StatusController extends Controller
{
    /**
     * GET /api/exports/{jobId}/status
     * Stub: always pending with 0% progress.
     */
    public function show(string $jobId): JsonResponse
    {
        return response()->json([
            'ok'       => true,
            'jobId'    => $jobId,
            'status'   => 'pending',
            'progress' => 0,
            'note'     => 'stub-only',
        ]);
    }
}
