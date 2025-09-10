<?php

declare(strict_types=1);

namespace App\Jobs;

use App\Models\Export;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Phase 4: CSV + JSON generator for CORE-008.
 * Writes an artifact for type=csv or type=json when persistence is enabled.
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

        try {
            // Simulate staged progress
            foreach ([30, 60, 90] as $p) {
                if ($export->status !== 'running') {
                    return;
                }
                $export->progress = $p;
                $export->save();
            }

            // Resolve disk/path base
            $disk = (string) config('core.exports.disk', config('filesystems.default', 'local'));
            $dir  = trim((string) config('core.exports.dir', 'exports'), '/');
            Storage::disk($disk)->makeDirectory($dir);

            $nowUtc    = CarbonImmutable::now('UTC')->format('c');
            $params    = (array) ($export->params ?? []);
            $artifact  = '';
            $mime      = '';
            $path      = '';

            if ($export->type === 'csv') {
                $rows = [
                    ['export_id', 'generated_at', 'type', 'param_count'],
                    [$export->id, $nowUtc, $export->type, (string) count($params)],
                ];
                foreach ($params as $k => $v) {
                    $rows[] = ['param_key', (string) $k, 'param_value', is_scalar($v) ? (string) $v : json_encode($v, JSON_UNESCAPED_SLASHES)];
                }
                $artifact = self::toCsv($rows);
                $mime     = 'text/csv';
                $path     = "{$dir}/{$export->id}.csv";
            } elseif ($export->type === 'json') {
                $payload = [
                    'export_id'    => $export->id,
                    'generated_at' => $nowUtc,
                    'type'         => $export->type,
                    'params'       => $params,
                ];
                $artifact = (string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                $mime     = 'application/json';
                $path     = "{$dir}/{$export->id}.json";
            } else {
                $export->error_code = 'EXPORT_TYPE_UNSUPPORTED';
                $export->error_note = 'Only CSV and JSON supported in Phase 4 step.';
                $this->failExport($export);
                return;
            }

            // Write artifact
            Storage::disk($disk)->put($path, $artifact);

            // Capture metadata
            $export->artifact_disk   = $disk;
            $export->artifact_path   = $path;
            $export->artifact_mime   = $mime;
            $export->artifact_size   = strlen($artifact);
            $export->artifact_sha256 = hash('sha256', $artifact);

            // Complete
            $export->progress = 100;
            $export->save();
            $export->markCompleted();
        } catch (Throwable $e) {
            $export->error_code = 'EXPORT_GENERATION_FAILED';
            $export->error_note = substr($e->getMessage(), 0, 180);
            $this->failExport($export);
        }
    }

    /**
     * @param list<list<string>> $rows
     */
    private static function toCsv(array $rows): string
    {
        $out = '';
        foreach ($rows as $row) {
            $escaped = array_map(
                static function (string $cell): string {
                    $needsQuotes = strpbrk($cell, ",\"\n\r") !== false;
                    $cell = str_replace('"', '""', $cell);
                    return $needsQuotes ? "\"{$cell}\"" : $cell;
                },
                $row
            );
            $out .= implode(',', $escaped) . "\n";
        }
        return $out;
    }

    private function failExport(Export $export): void
    {
        if (method_exists($export, 'markFailed')) {
            $export->markFailed();
        } else {
            $export->status    = 'failed';
            $export->failed_at = CarbonImmutable::now('UTC');
            $export->save();
        }
    }

    public function tags(): array
    {
        return ['exports', 'export:' . $this->exportId];
    }
}

