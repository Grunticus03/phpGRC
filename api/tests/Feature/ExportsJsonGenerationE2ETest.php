<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Export;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExportsJsonGenerationE2ETest extends TestCase
{
    #[Test]
    public function json_export_generates_artifact_and_allows_download_when_persisted(): void
    {
        // Enable persistence and sync queue for immediate job execution
        config(['core.exports.enabled' => true]);
        config(['queue.default' => 'sync']);
        config(['filesystems.default' => 'local']);

        // Run migrations so 'exports' table exists
        Artisan::call('migrate', ['--force' => true]);

        // Use a fake local disk for isolation
        Storage::fake('local');

        // Request a JSON export
        $res = $this->postJson('/exports/json', ['params' => ['foo' => 'bar']])
            ->assertStatus(202)
            ->assertJsonPath('ok', true);

        $jobId = (string) $res->json('jobId');
        $this->assertNotSame('', $jobId);

        // Status should be completed after sync job runs
        $this->getJson("/exports/{$jobId}/status")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('jobId', $jobId);

        // Download should succeed; allow charset suffix
        $dl = $this->get("/exports/{$jobId}/download");
        $dl->assertOk();
        $ctype = strtolower((string) $dl->headers->get('content-type'));
        $this->assertStringStartsWith('application/json', $ctype);
        $this->assertStringContainsString("filename=export-{$jobId}.json", (string) $dl->headers->get('content-disposition'));

        // Read artifact from storage and validate content
        /** @var Export $export */
        $export = Export::query()->findOrFail($jobId);
        $disk = $export->artifact_disk ?: 'local';
        $this->assertTrue(Storage::disk($disk)->exists((string) $export->artifact_path));
        $json = Storage::disk($disk)->get((string) $export->artifact_path);

        $decoded = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame($jobId, $decoded['export_id'] ?? null);
        $this->assertSame('json', $decoded['type'] ?? null);
        $this->assertSame(['foo' => 'bar'], $decoded['params'] ?? null);
        $this->assertIsString($decoded['generated_at'] ?? null);
    }
}
