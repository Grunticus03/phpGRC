<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceRetrieveTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Enable feature and satisfy authz
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);

        Gate::define('core.evidence.manage', fn (User $u) => true);

        $u = User::factory()->create();
        Sanctum::actingAs($u);
    }

    private function uploadSample(string $name = 'small.txt', string $mime = 'text/plain', string $body = "hello\n"): array
    {
        $file = UploadedFile::fake()->createWithContent($name, $body, $mime);
        $res  = $this->postJson('/evidence', ['file' => $file])
            ->assertCreated()
            ->assertJsonStructure(['id','sha256','size','mime','name']);

        return [
            'id'   => $res->json('id'),
            'etag' => '"' . $res->json('sha256') . '"',
            'body' => $body,
            'mime' => $mime,
            'size' => strlen($body),
            'name' => $name,
        ];
    }

    public function test_head_returns_headers_only(): void
    {
        $ev = $this->uploadSample();

        $resp = $this->call('HEAD', '/evidence/'.$ev['id']);
        $resp->assertOk();
        $this->assertTrue(str_starts_with((string) $resp->headers->get('Content-Type'), $ev['mime']));
        $resp->assertHeader('Content-Length', (string) $ev['size']);
        $resp->assertHeader('ETag', $ev['etag']);
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame('', $resp->getContent());
    }

    public function test_get_streams_bytes_with_headers(): void
    {
        $ev = $this->uploadSample();

        $resp = $this->get('/evidence/'.$ev['id']);
        $resp->assertOk();
        $this->assertTrue(str_starts_with((string) $resp->headers->get('Content-Type'), $ev['mime']));
        $resp->assertHeader('Content-Length', (string) $ev['size']);
        $resp->assertHeader('ETag', $ev['etag']);
        $resp->assertHeader('Content-Disposition');
        $resp->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertSame($ev['body'], $resp->getContent());
    }

    public function test_etag_304_works(): void
    {
        $ev = $this->uploadSample();

        $this->withHeaders(['If-None-Match' => $ev['etag']])
            ->get('/evidence/'.$ev['id'])
            ->assertStatus(304);
    }

    public function test_list_paginates_with_cursor(): void
    {
        $this->uploadSample('doc.txt', 'text/plain', "a\n");
        usleep(1000);
        $this->uploadSample('doc.txt', 'text/plain', "b\n");

        $r1 = $this->get('/evidence?limit=1')->assertOk();
        $this->assertCount(1, $r1->json('data'));
        $this->assertNotNull($r1->json('next_cursor'));

        $cursor = (string) $r1->json('next_cursor');
        $r2 = $this->get('/evidence?limit=1&cursor='.$cursor)->assertOk();
        $this->assertCount(1, $r2->json('data'));
        $this->assertNotSame($r1->json('data.0.id'), $r2->json('data.0.id'));
    }

    public function test_not_found_returns_404(): void
    {
        $this->get('/evidence/ev_DOES_NOT_EXIST')->assertStatus(404);
        $this->call('HEAD', '/evidence/ev_DOES_NOT_EXIST')->assertStatus(404);
    }
}

