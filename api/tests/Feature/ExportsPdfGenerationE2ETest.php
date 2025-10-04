<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Export;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class ExportsPdfGenerationE2ETest extends TestCase
{
    #[Test]
    public function pdf_export_generates_artifact_and_allows_download_when_persisted(): void
    {
        config(['core.exports.enabled' => true]);
        config(['queue.default' => 'sync']);
        config(['filesystems.default' => 'local']);

        Artisan::call('migrate', ['--force' => true]);
        Storage::fake('local');

        $res = $this->postJson('/exports/pdf', ['params' => ['foo' => 'bar']])
            ->assertStatus(202)
            ->assertJsonPath('ok', true);

        $jobId = (string) $res->json('jobId');
        $this->assertNotSame('', $jobId);

        $this->getJson("/exports/{$jobId}/status")
            ->assertOk()
            ->assertJsonPath('status', 'completed')
            ->assertJsonPath('jobId', $jobId);

        $dl = $this->get("/exports/{$jobId}/download");
        $dl->assertOk();
        $ctype = strtolower((string) $dl->headers->get('content-type'));
        $this->assertStringStartsWith('application/pdf', $ctype);
        $this->assertStringContainsString("filename=export-{$jobId}.pdf", (string) $dl->headers->get('content-disposition'));

        /** @var Export $export */
        $export = Export::query()->findOrFail($jobId);
        $disk = $export->artifact_disk ?: 'local';
        $this->assertTrue(Storage::disk($disk)->exists((string) $export->artifact_path));
        $bytes = Storage::disk($disk)->get((string) $export->artifact_path);

        $this->assertStringStartsWith('%PDF-', (string) $bytes);
    }
}
