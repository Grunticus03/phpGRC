<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use App\Services\Audit\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use PHPUnit\Framework\Attributes\Test;

final class EvidenceApiTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Feature + RBAC config to avoid 403s
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);
        config()->set('core.rbac.require_auth', false);

        // Authorization always allowed for tests
        Gate::define('core.evidence.manage', fn (User $u) => true);

        // Authenticated user for owner_id FK
        $user = User::query()->create([
            'name' => 'Tester',
            'email' => 'tester@example.test',
            'password' => bcrypt('x'),
        ]);
        Sanctum::actingAs($user);

        // Ensure AuditLogger is bound
        $this->app->make(AuditLogger::class);
    }

    #[Test]
    public function store_accepts_allowed_pdf_and_returns_201_with_ids(): void
    {
        $file = UploadedFile::fake()->create('evidence.pdf', 2, 'application/pdf');

        $res = $this->post('/evidence', ['file' => $file]);

        $res->assertStatus(201)
            ->assertJson([
                'ok'   => true,
                'mime' => 'application/pdf',
                'name' => 'evidence.pdf',
            ])
            ->assertJsonStructure(['id', 'version', 'sha256', 'size']);
    }

    #[Test]
    public function store_accepts_allowed_png_and_returns_201(): void
    {
        $file = UploadedFile::fake()->image('screen.png', 10, 10);

        $this->post('/evidence', ['file' => $file])
            ->assertStatus(201)
            ->assertJsonPath('mime', 'image/png')
            ->assertJsonPath('name', 'screen.png');
    }

    #[Test]
    public function store_accepts_arbitrary_mime_and_returns_201(): void
    {
        $file = UploadedFile::fake()->create('tool.exe', 1, 'application/x-msdownload');

        $res = $this->post('/evidence', ['file' => $file]);
        $res->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'tool.exe');

        $mime = $res->json('mime');
        $this->assertIsString($mime);
        $this->assertMatchesRegularExpression('#^application/[^;]+#', $mime);
    }

    #[Test]
    public function store_ignores_configured_max_mb_and_accepts_large_file(): void
    {
        Config::set('core.evidence.max_mb', 1);
        $file = UploadedFile::fake()->create('big.pdf', 1024 + 1, 'application/pdf');

        $this->post('/evidence', ['file' => $file])
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('name', 'big.pdf')
            ->assertJsonPath('mime', 'application/pdf');
    }

    #[Test]
    public function store_returns_400_when_feature_disabled(): void
    {
        Config::set('core.evidence.enabled', false);

        $file = UploadedFile::fake()->create('evidence.pdf', 2, 'application/pdf');

        $this->post('/evidence', ['file' => $file])
            ->assertStatus(400)
            ->assertJson([
                'ok'   => false,
                'code' => 'EVIDENCE_NOT_ENABLED',
            ]);
    }

    #[Test]
    public function index_paginates_and_returns_next_cursor(): void
    {
        $this->post('/evidence', ['file' => UploadedFile::fake()->createWithContent('a.txt', 'A', 'text/plain')]);
        $this->post('/evidence', ['file' => UploadedFile::fake()->createWithContent('b.txt', 'B', 'text/plain')]);
        $this->post('/evidence', ['file' => UploadedFile::fake()->createWithContent('c.txt', 'C', 'text/plain')]);

        $res = $this->getJson('/evidence?limit=2');

        $res->assertOk()
            ->assertJsonPath('ok', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonStructure(['next_cursor']);
    }

    #[Test]
    public function show_returns_bytes_and_headers_for_get(): void
    {
        $upload  = UploadedFile::fake()->createWithContent('doc.txt', 'DOC', 'text/plain');
        $created = $this->post('/evidence', ['file' => $upload])->assertCreated()->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->get("/evidence/{$id}");

        $res->assertOk()
            ->assertHeader('ETag', "\"{$sha}\"")
            ->assertHeader('Content-Length', (string) strlen('DOC'));

        $ct = $res->headers->get('Content-Type');
        $this->assertNotNull($ct);
        $this->assertMatchesRegularExpression('#^text/plain\b#', $ct);

        $this->assertSame('DOC', $res->getContent());
    }

    #[Test]
    public function head_returns_headers_only(): void
    {
        $upload  = UploadedFile::fake()->createWithContent('head.txt', 'HEAD', 'text/plain');
        $created = $this->post('/evidence', ['file' => $upload])->assertCreated()->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->call('HEAD', "/evidence/{$id}");

        $res->assertStatus(200)
            ->assertHeader('ETag', "\"{$sha}\"");

        $ct = $res->headers->get('Content-Type');
        $this->assertNotNull($ct);
        $this->assertMatchesRegularExpression('#^text/plain\b#', $ct);

        $this->assertSame('', $res->getContent());
    }

    #[Test]
    public function get_with_if_none_match_returns_304(): void
    {
        $upload  = UploadedFile::fake()->createWithContent('etag.txt', 'ETAG', 'text/plain');
        $created = $this->post('/evidence', ['file' => $upload])->assertCreated()->json();

        $id  = $created['id'];
        $sha = $created['sha256'];

        $res = $this->withHeaders(['If-None-Match' => "\"{$sha}\""])
            ->get("/evidence/{$id}");

        $res->assertStatus(304);
        $this->assertSame('', $res->getContent());
    }

    #[Test]
    public function show_returns_404_for_missing_id(): void
    {
        $this->get('/evidence/ev_does_not_exist')
            ->assertStatus(404)
            ->assertJson([
                'ok'   => false,
                'code' => 'EVIDENCE_NOT_FOUND',
            ]);
    }
}
