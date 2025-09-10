<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Export;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Phase 4: placeholder generation worker.
 * Simulates work and flips status to completed.
 * No files are written yet; artifact_* remain null.
 */
final class GenerateExport implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(public readonly string $exportId) {}

    public function handle(): void
    {
        /** @var Export|null $export */
        $export = Export::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        // Move to running
        $export->markRunning();

        // Simulate progress
        foreach ([30, 60, 90] as $p) {
            if ($export->status !== 'running') {
                return;
            }
            $export->progress = $p;
            $export->save();
        }

        // Complete
        $export->markCompleted();
    }

    public function tags(): array
    {
        return ['exports', 'export:'.$this->exportId];
    }
}

