<?php

declare(strict_types=1);

namespace App\Services\Export;

use App\Jobs\GenerateExport;
use App\Models\Export;

final class ExportService
{
    /**
     * Enqueue a new export and dispatch the worker.
     *
     * @param array<string,mixed> $params
     */
    public function enqueue(string $type, array $params = []): Export
    {
        $export = Export::createPending($type, $params);

        // Queue the generator (queue 'sync' in tests runs immediately)
        GenerateExport::dispatch($export->id);

        return $export;
    }
}

