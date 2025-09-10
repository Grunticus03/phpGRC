<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExportsCsvGenerationE2ETest extends TestCase
{
    /** @test */
    public function csv_export_generates_artifact_and_allows_download_when_persisted(): void
    {
        // Enable persistence and sync queue for immediate job execution
        config(['core.exports.enabled' => true]);
        config(['queue.default' => 'sync']);

        // Run migrations so 'exports' table exists
        Artisan::call('migrate', ['--force' => true]);

        // Use a fake local disk for isolation
        Storage::fake('local');

        // Request a CSV export
        $res = $this->postJson('/api/exports/csv', ['params' => ['foo' => 'bar']])
            ->assertStatus(202)
            ->assertJsonPath('ok', true);

        $jobId = (string) $res->json('jobId');
        $this->assertNotSame('', $jobId);

        // Status should be completed after sync job runs
        $this->getJson("/api/exports/{$jobId}/status")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('jobId', $jobId);

        // Download should succeed with CSV content-type (allow charset suffix)
        $dl = $this->get("/api/exports/{$jobId}/download");
        $dl->assertOk();
        $ctype = strtolower((string) $dl->headers->get('content-type'));
        $this->assertStringStartsWith('text/csv', $ctype);

        // Body should contain header line
        $this->assertStringContainsString('export_id,generated_at,type,param_count', (string) $dl->getContent());
    }
}

