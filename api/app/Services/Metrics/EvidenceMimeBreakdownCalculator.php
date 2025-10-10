<?php

declare(strict_types=1);

namespace App\Services\Metrics;

use App\Models\Evidence;
use App\Services\Mime\MimeLabelService;

/**
 * Summarize evidence MIME type usage for dashboard pie chart.
 *
 * @psalm-type SliceShape=array{mime:string,mime_label:string,count:int,percent:float}
 * @psalm-type OutputShape=array{
 *   total:int,
 *   by_mime:list<SliceShape>
 * }
 */
final class EvidenceMimeBreakdownCalculator
{
    public function __construct(
        private readonly MimeLabelService $mimeLabels,
    ) {}

    /**
     * @return OutputShape
     */
    public function compute(): array
    {
        $total = Evidence::query()->count('id');

        $rows = [];
        foreach (Evidence::query()->selectRaw('mime, COUNT(*) as count')->groupBy('mime')->get() as $row) {
            /** @var mixed $mimeAttr */
            $mimeAttr = $row->getAttribute('mime');
            /** @var mixed $countAttr */
            $countAttr = $row->getAttribute('count');

            $rows[] = [
                'mime' => is_string($mimeAttr) && $mimeAttr !== '' ? $mimeAttr : null,
                'count' => is_numeric($countAttr) ? (int) $countAttr : 0,
            ];
        }

        /** @var list<array{mime:string,mime_label:string,count:int,percent:float}> $slices */
        $slices = [];
        if ($rows !== []) {
            foreach ($rows as $row) {
                $mimeValue = $row['mime'];
                if (! is_string($mimeValue)) {
                    $mime = 'application/octet-stream';
                } else {
                    $mime = $mimeValue;
                }
                $count = max(0, $row['count']);
                if ($count === 0) {
                    continue;
                }
                $label = $this->mimeLabels->labelFor($mime);
                $percent = $total > 0 ? (float) ($count / $total) : 0.0;
                $slices[] = [
                    'mime' => $mime,
                    'mime_label' => $label,
                    'count' => $count,
                    'percent' => $percent,
                ];
            }
        }

        usort(
            $slices,
            static function (array $a, array $b): int {
                /** @var non-empty-string $aMime */
                $aMime = $a['mime'];
                /** @var non-empty-string $bMime */
                $bMime = $b['mime'];

                return $b['count'] <=> $a['count'] ?: ($aMime <=> $bMime);
            }
        );

        return [
            'total' => $total,
            'by_mime' => $slices,
        ];
    }
}
