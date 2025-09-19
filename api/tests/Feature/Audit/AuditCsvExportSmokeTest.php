<?php

declare(strict_types=1);

namespace Tests\Feature\Audit;

use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

final class AuditCsvExportSmokeTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function export_streams_csv_for_large_dataset_without_regression(): void
    {
        // Enable DB-backed audit path and open endpoint.
        config([
            'core.audit.enabled' => true,
            'core.audit.persistence' => true,
            'core.rbac.require_auth' => false,
        ]);

        // Seed a large dataset quickly using chunked bulk inserts.
        $rows = 5000; // CI-safe; adjust if needed
        $chunk = 1000;
        $now = CarbonImmutable::now()->format('Y-m-d H:i:s');

        for ($i = 0; $i < $rows; $i += $chunk) {
            $batch = [];
            $limit = min($chunk, $rows - $i);
            for ($j = 0; $j < $limit; $j++) {
                $batch[] = [
                    'id'          => (string) Str::ulid(),
                    'occurred_at' => $now,
                    'created_at'  => $now,
                    'category'    => 'RBAC',
                    'action'      => 'rbac.user_role.attached',
                    'entity_type' => 'user',
                    'entity_id'   => (string) random_int(1, 1000),
                    'actor_id'    => (string) random_int(1, 100),
                    'ip'          => '127.0.0.1',
                    'ua'          => 'phpunit',
                    // Important: meta must be JSON string, not array.
                    'meta'        => json_encode(['role' => 'role_auditor'], JSON_THROW_ON_ERROR),
                ];
            }
            DB::table('audit_events')->insert($batch);
        }

        // Hit CSV export. Accept header to drive content negotiation if present.
        $res = $this->get('/api/audit/export.csv', ['Accept' => 'text/csv']);

        // Status and headers.
        $res->assertOk();
        $contentType = strtolower((string) $res->headers->get('content-type'));
        $this->assertStringContainsString('text/csv', $contentType);

        // Read streamed content and validate line count and header anchors.
        $csv = $res->streamedContent();
        $this->assertIsString($csv);

        // Normalize newlines and split.
        $normalized = str_replace(["\r\n", "\r"], "\n", $csv);
        $lines = array_values(array_filter(explode("\n", $normalized), static fn ($l) => $l !== ''));

        // Expect header + N data lines.
        $this->assertGreaterThanOrEqual($rows + 1, count($lines));

        // Header anchors should include common columns.
        $header = $lines[0];
        $this->assertStringContainsString('id,', $header);
        $this->assertStringContainsString('occurred_at,', $header);
        $this->assertStringContainsString('category,', $header);
        $this->assertStringContainsString('action,', $header);

        // Spot-check a data row to be CSV-ish (has commas and quoted/escaped JSON at end).
        $sample = $lines[1] ?? '';
        $this->assertNotSame('', $sample);
        $this->assertTrue(substr_count($sample, ',') >= 5, 'row should have several commas');
    }
}
