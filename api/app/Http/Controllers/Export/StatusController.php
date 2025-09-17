<?php

declare(strict_types=1);

namespace App\Http\Controllers\Export;

use App\Models\Export;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Schema;

final class StatusController extends Controller
{
    private function persistenceOn(): bool
    {
        return (bool) config('core.exports.enabled', false) && Schema::hasTable('exports');
    }

    /** GET /api/exports/{jobId}/status */
    public function show(string $jobId): JsonResponse
    {
        if ($this->persistenceOn()) {
            /** @var Export|null $export */
            $export = Export::query()->find($jobId);

            if ($export instanceof Export) {
                return response()->json([
                    'ok'       => true,
                    'status'   => $export->status,
                    'progress' => $export->progress,
                    'id'       => $export->id,
                    'jobId'    => $export->id,
                ], 200);
            }

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

