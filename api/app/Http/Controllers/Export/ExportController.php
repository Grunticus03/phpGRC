<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Placeholder export controller.
 * No DB, no queues, no files. Returns fixed placeholders.
 */
final class ExportController extends Controller
{
    public function create(): JsonResponse
    {
        // Placeholder job id
        $jobId = 'exp_' . Str::lower(Str::random(10));

        return response()->json([
            'accepted' => true,
            'jobId'    => $jobId,
            'note'     => 'placeholder export; processing not implemented',
        ], Response::HTTP_ACCEPTED);
    }

    public function download(string $jobId): JsonResponse
    {
        return response()->json([
            'error' => 'EXPORT_NOT_READY',
            'jobId' => $jobId,
            'note'  => 'download not implemented in Phase 2',
        ], Response::HTTP_NOT_FOUND);
    }
}
