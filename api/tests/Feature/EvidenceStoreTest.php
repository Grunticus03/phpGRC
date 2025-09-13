<?php

declare(strict_types=1);

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class EvidenceStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);
        Gate::define('core.evidence.manage', fn () => true);
    }

    public function test_store_creates_record_and_returns_hash(): void
    {
        $content = "hello world";
        $file = UploadedFile::fake()->createWithContent('hello.txt', $content);

        $res = $this->post('/api/evidence', ['file' => $file]);

        $res->assertStatus(201)->assertJsonPath('ok', true);
        $res->assertJsonStructure(['id','version','sha256','size','mime','name']);

        $json = $res->json();
        $this->assertSame(hash('sha256', $content), $json['sha256']);
        $this->assertSame(strlen($content), $json['size']);
        $this->assertSame('hello.txt', $json['name']);
    }

    public function test_store_rejects_when_disabled(): void
    {
        config()->set('core.evidence.enabled', false);

        $file = UploadedFile::fake()->createWithContent('note.txt', 'x');
        $res = $this->post('/api/evidence', ['file' => $file]);

        $res->assertStatus(400)->assertJsonPath('code', 'EVIDENCE_NOT_ENABLED');
    }
}
