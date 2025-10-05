<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Jobs\GenerateExport;
use App\Models\Export;

/**
 * Orchestrates export job creation and dispatch.
 * Persistence path only; caller should gate via config/table checks.
 */
final class ExportService
{
    /**
     * @param array<string,mixed> $params
     */
    public function enqueue(string $type, array $params = []): Export
    {
        $export = Export::createPending($type, $params);

        // Dispatch to queue. In tests we force `queue.default=sync`.
        GenerateExport::dispatch($export->id);

        return $export;
    }
}
