<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Mockery;
use Tests\TestCase;

final class EvidenceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::shouldReceive('authorize')->andReturn(true);

        $mock = Mockery::mock(AuditLogger::class);
        $mock->shouldReceive('log')->byDefault()->andReturnNull();
        $this->app->instance(AuditLogger::class, $mock);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    /** @test */
    public function store_accepts_allowed_pdf_and_returns_201_with_ids(): void
    {
        $file = UploadedFile::fake()->create('evidence.pdf', 2, 'application/pdf');

        $res = $this->post('/api/evidence', ['file' => $file]);

        $res->assertStatus(201)
            ->assertJson([
                'ok'   => true,
                'mime' => 'application/pdf',
                'name' => 'evidence.pdf',
            ])
            ->assertJsonStructure(['id', 'version', 'sha256', 'size']);
    }

    /** @test */
    public function store_accepts_allowed_png_and_returns_201(): void
    {
        $file = UploadedFile::fake()->image('screen.png', 10, 10);

        $this->post('/api/evidence', ['file' => $file])
            ->assertStatus(201)
            ->assertJsonPath('mime', 'image/png')
            ->assertJsonPath('name', 'screen.png');
    }

    /** @test */
    public function store_rejects_disallowed_mime_with_422(): void
    {
        $file = UploadedFile::fake()->create('malware.exe', 1, 'application/x-msdownload');

        $this->post('/api/evidence', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /** @test */
    public function store_rejects_oversize_file_with_422(): void
    {
        Config::set('core.evidence.max_mb', 1); // 1 MB limit
        $file = UploadedFile::fake()->create('big.pdf', 1024 + 1, 'application/pdf');

        $this->post('/api/evidence', ['file' => $file])
            ->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED');
    }

    /** @test */
    public function store_returns_400_when_feature_disabled(): void
    {
        Config::set('core.evidence.enabled', false);

        $file = UploadedFile::fake()->create('evidence.pdf', 2, 'application/pdf');

        $this->post('/api/evidence', ['file' => $file])
            ->assertStatus(400)
            ->assertJson([
                'ok'   => false,
                'code' => 'EVIDENCE_NOT_ENABLED',
            ]);
    }

    /** @test */
    public function index_paginates_and_returns_next_cursor(): void
    {
        $this->post('/api/evidence', ['file' => UploadedFile::fake()->create('a.txt', 1, 'text/plain')]);
        $this->post('/api/evidence', ['file' => UploadedFile::fake()->create('b.txt', 1, 'text/plain')]);
        $this->post('/api/evidence', ['file' => UploadedFile::fake()->create('c.txt', 1, 'text/plain')]);

        $res = $this->getJson('/api/evidence?limit=2');

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['next_cursor']);
    }

    /** @test */
    public function show_returns_bytes_and_headers_for_get(): void
    {
        $upload = UploadedFile::fake()->createWithContent('doc.txt', 'DOC');
        $created = $this->post('/api/evidence', ['file' => $upload])->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->get("/api/evidence/{$id}");

        $res->assertOk()
            ->assertHeader('ETag', "\"{$sha}\"")
            ->assertHeader('Content-Type', 'text/plain')
            ->assertHeader('Content-Length', (string) strlen('DOC'));

        $this->assertSame('DOC', $res->getContent());
    }

    /** @test */
    public function head_returns_headers_only(): void
    {
        $upload = UploadedFile::fake()->createWithContent('head.txt', 'HEAD');
        $created = $this->post('/api/evidence', ['file' => $upload])->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->call('HEAD', "/api/evidence/{$id}");

        $res->assertStatus(200)
            ->assertHeader('ETag', "\"{$sha}\"")
            ->assertHeader('Content-Type', 'text/plain');

        $this->assertSame('', $res->getContent());
    }

    /** @test */
    public function get_with_if_none_match_returns_304(): void
    {
        $upload = UploadedFile::fake()->createWithContent('etag.txt', 'ETAG');
        $created = $this->post('/api/evidence', ['file' => $upload])->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->withHeaders(['If-None-Match' => "\"{$sha}\""])
            ->get("/api/evidence/{$id}");

        $res->assertStatus(304);
        $this->assertSame('', $res->getContent());
    }

    /** @test */
    public function show_returns_404_for_missing_id(): void
    {
        $this->get('/api/evidence/ev_does_not_exist')
            ->assertStatus(404)
            ->assertJson([
                'ok'   => false,
                'code' => 'EVIDENCE_NOT_FOUND',
            ]);
    }
}
