<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceStoreTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        config()->set('core.evidence.enabled', true);
        config()->set('core.rbac.enabled', false);

        Gate::define('core.evidence.manage', fn (User $u) => true);
        $this->user = User::query()->create([
            'name' => 'T',
            'email' => 't@example.com',
            'password' => bcrypt('x'),
        ]);
    }

    public function test_store_creates_record_and_returns_hash(): void
    {
        Sanctum::actingAs($this->user);

        $content = 'hello world';
        $file = UploadedFile::fake()->createWithContent('hello.txt', $content);

        $res = $this->post('/evidence', ['file' => $file]);

        $res->assertStatus(201)->assertJsonPath('ok', true);
        $res->assertJsonStructure(['id', 'version', 'sha256', 'size', 'mime', 'name']);

        $json = $res->json();
        $this->assertSame(hash('sha256', $content), $json['sha256']);
        $this->assertSame(strlen($content), $json['size']);
        $this->assertSame('hello.txt', $json['name']);
    }

    public function test_store_rejects_when_disabled(): void
    {
        Sanctum::actingAs($this->user);

        config()->set('core.evidence.enabled', false);

        $file = UploadedFile::fake()->createWithContent('note.txt', 'x');
        $res = $this->post('/evidence', ['file' => $file]);

        $res->assertStatus(400)->assertJsonPath('code', 'EVIDENCE_NOT_ENABLED');
    }

    public function test_store_allows_guest_when_auth_not_required(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.require_auth', false);

        $file = UploadedFile::fake()->createWithContent('guest.txt', 'hello');

        $res = $this->post('/evidence', ['file' => $file]);

        $res->assertStatus(201)->assertJsonPath('ok', true);
    }
}
