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
use RuntimeException;
use Throwable;

/**
 * CORE-008: CSV + JSON + PDF generator.
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

        $export->markRunning();

        try {
            foreach ([30, 60, 90] as $p) {
                if ($export->status !== 'running') {
                    return;
                }
                $export->progress = $p;
                $export->save();
            }

            /** @var mixed $fsDefaultRaw */
            $fsDefaultRaw = config('filesystems.default', 'local');
            $fsDefault = is_string($fsDefaultRaw) && $fsDefaultRaw !== '' ? $fsDefaultRaw : 'local';

            /** @var mixed $diskRaw */
            $diskRaw = config('core.exports.disk');
            $disk = is_string($diskRaw) && $diskRaw !== '' ? $diskRaw : $fsDefault;

            /** @var mixed $dirRaw */
            $dirRaw = config('core.exports.dir');
            $dir = is_string($dirRaw) && $dirRaw !== '' ? trim($dirRaw, '/') : 'exports';

            Storage::disk($disk)->makeDirectory($dir);

            $nowUtc = CarbonImmutable::now('UTC')->format('c');

            /** @var mixed $paramsRaw */
            $paramsRaw = $export->params;
            /**
             * @var array<string, bool|int|float|string|array<array-key,mixed>|object|null> $params
             */
            $params = is_array($paramsRaw) ? $paramsRaw : [];

            $artifact = '';
            $mime = '';
            $path = '';

            if ($export->type === 'csv') {
                /** @var list<list<string>> $rows */
                $rows = [
                    ['export_id', 'generated_at', 'type', 'param_count'],
                    [$export->id, $nowUtc, $export->type, (string) count($params)],
                ];
                foreach ($params as $k => $v) {
                    /** @var string $k */
                    $vv = ($v === null || is_scalar($v))
                        ? (string) $v
                        : (string) json_encode($v, JSON_UNESCAPED_SLASHES);
                    $rows[] = ['param_key', $k, 'param_value', $vv];
                }
                $artifact = self::toCsv($rows);
                $mime = 'text/csv';
                $path = "{$dir}/{$export->id}.csv";
            } elseif ($export->type === 'json') {
                $payload = [
                    'export_id' => $export->id,
                    'generated_at' => $nowUtc,
                    'type' => $export->type,
                    'params' => $params,
                ];
                $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
                if ($json === false) {
                    throw new RuntimeException('json_encode failed');
                }
                $artifact = $json;
                $mime = 'application/json';
                $path = "{$dir}/{$export->id}.json";
            } elseif ($export->type === 'pdf') {
                $text = "phpGRC export {$export->id} {$nowUtc}";
                $artifact = self::minimalPdf($text);
                $mime = 'application/pdf';
                $path = "{$dir}/{$export->id}.pdf";
            } else {
                $export->error_code = 'EXPORT_TYPE_UNSUPPORTED';
                $export->error_note = 'Supported types: csv, json, pdf.';
                $this->failExport($export);

                return;
            }

            Storage::disk($disk)->put($path, $artifact);

            $export->artifact_disk = $disk;
            $export->artifact_path = $path;
            $export->artifact_mime = $mime;
            $export->artifact_size = strlen($artifact);
            $export->artifact_sha256 = hash('sha256', $artifact);

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
     * @param  list<list<string>>  $rows
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
            $out .= implode(',', $escaped)."\n";
        }

        return $out;
    }

    /**
     * Generate a valid minimal one-page PDF with a text line.
     */
    private static function minimalPdf(string $text): string
    {
        $header = "%PDF-1.4\n";

        $stream = "BT\n/F1 12 Tf\n72 720 Td\n(".self::pdfEscape($text).") Tj\nET\n";
        $obj1 = "1 0 obj << /Type /Catalog /Pages 2 0 R >> endobj\n";
        $obj2 = "2 0 obj << /Type /Pages /Kids [3 0 R] /Count 1 >> endobj\n";
        $obj3 = "3 0 obj << /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Contents 4 0 R /Resources << /Font << /F1 5 0 R >> >> >> endobj\n";
        $obj4 = '4 0 obj << /Length '.strlen($stream)." >> stream\n{$stream}endstream\nendobj\n";
        $obj5 = "5 0 obj << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> endobj\n";

        $objects = [$obj1, $obj2, $obj3, $obj4, $obj5];

        $body = '';
        $offsets = [];
        $pos = strlen($header);
        foreach ($objects as $i => $obj) {
            $n = $i + 1;
            $offsets[$n] = $pos;
            $body .= $obj;
            $pos = strlen($header) + strlen($body);
        }

        $xref = "xref\n0 6\n";
        $xref .= "0000000000 65535 f \n";
        for ($i = 1; $i <= 5; $i++) {
            $xref .= sprintf("%010d 00000 n \n", $offsets[$i]);
        }

        $startxref = strlen($header) + strlen($body);
        $trailer = "trailer << /Size 6 /Root 1 0 R >>\nstartxref\n{$startxref}\n%%EOF";

        return $header.$body.$xref.$trailer;
    }

    private static function pdfEscape(string $s): string
    {
        return str_replace(
            ['\\', '(', ')'],
            ['\\\\', '\\(', '\\)'],
            $s
        );
    }

    private function failExport(Export $export): void
    {
        $export->markFailed();
    }

    /** @return array<int,string> */
    public function tags(): array
    {
        return ['exports', 'export:'.$this->exportId];
    }
}
