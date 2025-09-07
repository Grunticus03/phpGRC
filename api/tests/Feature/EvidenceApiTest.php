<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

final class EvidenceApiTest extends TestCase
{
    public function test_disabled_returns_400(): void
    {
        config(['core.evidence.enabled' => false]);

        $file = UploadedFile::fake()->create('a.txt', 1, 'text/plain');

        $this->postJson('/api/evidence', ['file' => $file])
            ->assertStatus(400)
            ->assertJson(['ok' => false, 'code' => 'EVIDENCE_NOT_ENABLED']);
    }

    public function test_upload_valid_pdf_201_and_fetch_by_id_and_etag(): void
    {
        if (! Schema::hasTable('evidence')) {
            $this->markTestSkipped('evidence table not present');
        }

        config(['core.evidence.enabled' => true]);

        $file = UploadedFile::fake()->create('doc.pdf', 5, 'application/pdf');

        $r = $this->postJson('/api/evidence', ['file' => $file])
            ->assertCreated()
            ->assertJsonStructure(['ok','id','version','sha256','size','mime','name'])
            ->json();

        $id = $r['id'];
        $etag = '"' . $r['sha256'] . '"';

        // HEAD returns headers only
        $head = $this->call('HEAD', "/api/evidence/{$id}");
        $head->assertOk();
        $head->assertHeader('ETag', $etag);
        $this->assertSame('', $head->getContent());

        // GET returns body and same ETag
        $get = $this->get("/api/evidence/{$id}");
        $get->assertOk();
        $get->assertHeader('ETag', $etag);

        // 304 when ETag matches
        $this->get("/api/evidence/{$id}", ['If-None-Match' => $etag])
            ->assertStatus(304);
    }

    public function test_index_cursor_and_limit(): void
    {
        if (! Schema::hasTable('evidence')) {
            $this->markTestSkipped('evidence table not present');
        }

        config(['core.evidence.enabled' => true]);

        // Ensure at least 2 items
        $this->postJson('/api/evidence', ['file' => UploadedFile::fake()->create('x.txt', 1, 'text/plain')])->assertCreated();
        $this->postJson('/api/evidence', ['file' => UploadedFile::fake()->create('y.txt', 1, 'text/plain')])->assertCreated();

        $page1 = $this->getJson('/api/evidence?limit=1')
            ->assertOk()
            ->json();

        $this->assertIsArray($page1['data']);
        $this->assertCount(1, $page1['data']);
        $this->assertArrayHasKey('next_cursor', $page1);

        if (! empty($page1['next_cursor'])) {
            $this->getJson('/api/evidence?limit=1&cursor=' . urlencode($page1['next_cursor']))
                ->assertOk()
                ->assertJsonStructure(['ok','data','next_cursor']);
        }
    }

    public function test_rejects_large_file_and_wrong_mime(): void
    {
        config(['core.evidence.enabled' => true, 'core.evidence.max_mb' => 1, 'core.evidence.allowed_mime' => ['text/plain']]);

        // 2 MB exceeds max_mb=1
        $tooBig = UploadedFile::fake()->create('big.bin', 2048, 'application/octet-stream');
        $this->postJson('/api/evidence', ['file' => $tooBig])
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'VALIDATION_FAILED']);

        // Wrong mime
        $wrong = UploadedFile::fake()->create('img.jpeg', 10, 'image/jpeg');
        $this->postJson('/api/evidence', ['file' => $wrong])
            ->assertStatus(422)
            ->assertJsonFragment(['code' => 'VALIDATION_FAILED']);
    }
}
