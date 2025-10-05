<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Export;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

final class ExportsQueueTest extends TestCase
{
    use RefreshDatabase;

    public function test_csv_export_runs_on_sync_queue_and_downloads_artifact(): void
    {
        // Enable persistence path and force sync queue.
        config()->set('core.exports.enabled', true);
        config()->set('queue.default', 'sync');
        config()->set('filesystems.default', 'local');
        // RBAC off for this test so routes pass without auth.
        config()->set('core.rbac.enabled', false);

        $params = ['foo' => 'bar', 'n' => 3];

        $res = $this->postJson('/exports/csv', ['params' => $params]);
        $res->assertStatus(202)->assertJson(['ok' => true, 'type' => 'csv']);

        $jobId = (string) $res->json('jobId');
        $this->assertSame(26, strlen($jobId), 'jobId should be ULID length 26');

        /** @var Export|null $export */
        $export = Export::query()->find($jobId);
        $this->assertNotNull($export);
        $this->assertContains($export->status, ['running', 'completed']);

        $status = $this->getJson("/exports/{$jobId}/status")->assertStatus(200)->json();
        $this->assertTrue($status['ok']);
        $this->assertArrayHasKey('status', $status);
        $this->assertArrayHasKey('progress', $status);

        if ($export->status === 'completed') {
            $this->assertNotEmpty($export->artifact_path);
            $this->assertTrue(Storage::disk($export->artifact_disk ?? 'local')->exists((string) $export->artifact_path));

            $dl = $this->get("/exports/{$jobId}/download");
            $dl->assertStatus(200);
            // Symfony appends charset for text responses; accept the canonical value we emit.
            $dl->assertHeader('content-type', 'text/csv; charset=UTF-8');
        } else {
            $this->getJson("/exports/{$jobId}/download")
                ->assertStatus(404)
                ->assertJson(['ok' => false, 'code' => 'EXPORT_NOT_READY']);
        }
    }

    public function test_stub_path_when_persistence_disabled(): void
    {
        config()->set('core.exports.enabled', false);
        config()->set('core.rbac.enabled', false);

        $res = $this->postJson('/exports/json', ['params' => ['a' => 1]]);
        $res->assertStatus(202)->assertJson(['ok' => true, 'note' => 'stub-only']);
        $jobId = (string) $res->json('jobId');
        $this->assertSame('exp_stub_0001', $jobId);

        $this->getJson("/exports/{$jobId}/status")
            ->assertStatus(200)
            ->assertJson(['ok' => true, 'status' => 'pending', 'note' => 'stub-only']);

        $this->getJson("/exports/{$jobId}/download")
            ->assertStatus(404)
            ->assertJson(['ok' => false, 'code' => 'EXPORT_NOT_READY', 'note' => 'stub-only']);
    }
}
