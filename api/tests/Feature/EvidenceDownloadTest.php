<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceDownloadTest extends TestCase
{
    use RefreshDatabase;

    private string $id;

    private string $sha;

    private string $bytes;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);

        Gate::define('core.evidence.manage', fn (User $u) => true);
        $u = User::query()->create([
            'name' => 'T',
            'email' => 't@example.com',
            'password' => bcrypt('x'),
        ]);
        Sanctum::actingAs($u);

        $this->bytes = 'sample-bytes';
        $file = UploadedFile::fake()->createWithContent('sample.txt', $this->bytes);
        $r = $this->post('/evidence', ['file' => $file]);
        $r->assertStatus(201);

        $this->id = $r->json('id');
        $this->sha = $r->json('sha256');
    }

    public function test_head_and_get_etag_and_headers(): void
    {
        $h = $this->head("/evidence/{$this->id}");
        $h->assertStatus(200);
        $h->assertHeader('ETag', '"'.$this->sha.'"');
        $h->assertHeader('X-Content-Type-Options', 'nosniff');
        $h->assertHeader('X-Checksum-SHA256', $this->sha);

        $g = $this->get("/evidence/{$this->id}?sha256={$this->sha}");
        $g->assertStatus(200);
        $g->assertHeader('ETag', '"'.$this->sha.'"');
        $this->assertFalse($g->getContent());
        $this->assertSame($this->bytes, $g->streamedContent());
    }

    public function test_if_none_match_304_and_hash_mismatch_412(): void
    {
        $etag = '"'.$this->sha.'"';

        $n = $this->get("/evidence/{$this->id}", ['If-None-Match' => $etag]);
        $n->assertStatus(304);

        $m = $this->get("/evidence/{$this->id}?sha256=deadbeef");
        $m->assertStatus(412)->assertJsonPath('code', 'EVIDENCE_HASH_MISMATCH');
    }
}
