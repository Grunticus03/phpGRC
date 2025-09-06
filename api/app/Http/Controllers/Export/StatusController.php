<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;

final class StatusController extends Controller
{
    /** GET /api/exports/{jobId}/status */
    public function show(string $jobId): JsonResponse
    {
        return response()->json([
            'ok'       => true,
            'status'   => 'pending',
            'progress' => 0,
            'id'       => $jobId,   // spec-friendly
            'jobId'    => $jobId,   // legacy echo
            'note'     => 'stub-only',
        ]);
    }
}
