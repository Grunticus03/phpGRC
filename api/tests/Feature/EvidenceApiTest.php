<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\Audit\AuditLogger;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

final class EvidenceApiTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Gate::shouldReceive('authorize')->andReturn(true);

        // Resolve real logger; controller guards on table existence.
        $this->app->make(AuditLogger::class);
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
    public function store_accepts_arbitrary_mime_and_returns_201(): void
    {
        $file = UploadedFile::fake()->create('tool.exe', 1, 'application/x-msdownload');

        $this->post('/api/evidence', ['file' => $file])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'tool.exe')
            ->assertJsonPath('mime', 'application/x-msdownload');
    }

    /** @test */
    public function store_ignores_configured_max_mb_and_accepts_large_file(): void
    {
        // Even if configured, controller policy does not enforce size limits.
        Config::set('core.evidence.max_mb', 1); // 1 MB
        $file = UploadedFile::fake()->create('big.pdf', 1024 + 1, 'application/pdf');

        $this->post('/api/evidence', ['file' => $file])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'big.pdf')
            ->assertJsonPath('mime', 'application/pdf');
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
        $this->post('/api/evidence', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'A', 'text/plain')]);
        $this->post('/api/evidence', ['file' => UploadedFile::fake()->createWithContent('b.txt', 'B', 'text/plain')]);
        $this->post('/api/evidence', ['file' => UploadedFile::fake()->createWithContent('c.txt', 'C', 'text/plain')]);

        $res = $this->getJson('/api/evidence?limit=2');

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['next_cursor']);
    }

    /** @test */
    public function show_returns_bytes_and_headers_for_get(): void
    {
        $upload  = UploadedFile::fake()->createWithContent('doc.txt', 'DOC', 'text/plain');
        $created = $this->post('/api/evidence', ['file' => $upload])->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->get("/api/evidence/{$id}");

        $res->assertOk()
            ->assertHeader('ETag', "\"{$sha}\"")
            ->assertHeader('Content-Length', (string) strlen('DOC'));

        $ct = $res->headers->get('Content-Type');
        $this->assertNotNull($ct);
        $this->assertMatchesRegularExpression('#^text/plain\b#', $ct);

        $this->assertSame('DOC', $res->getContent());
    }

    /** @test */
    public function head_returns_headers_only(): void
    {
        $upload  = UploadedFile::fake()->createWithContent('head.txt', 'HEAD', 'text/plain');
        $created = $this->post('/api/evidence', ['file' => $upload])->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->call('HEAD', "/api/evidence/{$id}");

        $res->assertStatus(200)
            ->assertHeader('ETag', "\"{$sha}\"");

        $ct = $res->headers->get('Content-Type');
        $this->assertNotNull($ct);
        $this->assertMatchesRegularExpression('#^text/plain\b#', $ct);

        $this->assertSame('', $res->getContent());
    }

    /** @test */
    public function get_with_if_none_match_returns_304(): void
    {
        $upload  = UploadedFile::fake()->createWithContent('etag.txt', 'ETAG', 'text/plain');
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
