<?php

declare(strict_types=1);

namespace App\Http\Controllers\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\AdminActivityReportBuilder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Symfony\Component\HttpFoundation\Response;

final class AdminActivityReportController extends Controller
{
    public function __invoke(Request $request, AdminActivityReportBuilder $builder): Response
    {
        /** @var array<string, mixed> $query */
        $query = $request->query->all();

        $validated = Validator::make(
            $query,
            [
                'format' => ['nullable', 'string', 'in:json,csv'],
            ]
        );

        if ($validated->fails()) {
            return new JsonResponse([
                'ok' => false,
                'code' => 'VALIDATION_FAILED',
                'errors' => $validated->errors()->getMessages(),
            ], 422);
        }

        /** @var array{format?:string|null} $data */
        $data = $validated->validated();
        $format = isset($data['format']) ? strtolower($data['format']) : 'json';

        $payload = $builder->build();

        if ($format === 'csv') {
            return $this->asCsv($payload);
        }

        return new JsonResponse([
            'ok' => true,
            'data' => $payload,
        ], 200);
    }

    /**
     * @param  array{
     *   generated_at:non-empty-string,
     *   rows:list<array{
     *     id:int,
     *     name:string,
     *     email:string,
     *     last_login_at:string|null,
     *     logins_total:int,
     *     logins_30_days:int,
     *     logins_7_days:int
     *   }>,
     *   totals:array{
     *     admins:int,
     *     logins_total:int,
     *     logins_30_days:int,
     *     logins_7_days:int
     *   }
     * }  $payload
     */
    private function asCsv(array $payload): Response
    {
        $header = [
            'id',
            'name',
            'email',
            'last_login_at',
            'logins_total',
            'logins_30_days',
            'logins_7_days',
        ];

        /** @var list<array{
         *   id:int,
         *   name:string,
         *   email:string,
         *   last_login_at:string|null,
         *   logins_total:int,
         *   logins_30_days:int,
         *   logins_7_days:int
         * }> $rows
         */
        $rows = $payload['rows'];

        $csvRows = [$header];

        foreach ($rows as $row) {
            $csvRows[] = [
                (string) $row['id'],
                $row['name'],
                $row['email'],
                $row['last_login_at'] ?? '',
                (string) $row['logins_total'],
                (string) $row['logins_30_days'],
                (string) $row['logins_7_days'],
            ];
        }

        $csv = $this->toCsv($csvRows);

        $filename = sprintf(
            'admin-activity-report-%s.csv',
            now('UTC')->format('Ymd_His')
        );

        return response($csv, 200, [
            'Content-Type' => 'text/csv; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * @param  list<list<string>>  $rows
     */
    private function toCsv(array $rows): string
    {
        $out = '';

        foreach ($rows as $row) {
            $escaped = array_map(
                static function (string $cell): string {
                    $needsQuotes = strpbrk($cell, ",\"\n\r") !== false;
                    $cell = str_replace('"', '""', $cell);

                    return $needsQuotes ? '"'.$cell.'"' : $cell;
                },
                $row
            );

            $out .= implode(',', $escaped)."\n";
        }

        return $out;
    }
}
