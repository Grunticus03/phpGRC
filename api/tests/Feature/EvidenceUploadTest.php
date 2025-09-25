<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceUploadTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('core.rbac.enabled', false);
        Gate::define('core.evidence.manage', fn (User $u) => true);

        $u = User::factory()->create();
        Sanctum::actingAs($u);
    }

    public function test_upload_disabled_returns_400(): void
    {
        config()->set('core.evidence.enabled', false);

        $file = UploadedFile::fake()->createWithContent('small.txt', "hello\n", 'text/plain');

        $res = $this->postJson('/evidence', ['file' => $file]);

        $res->assertStatus(400)->assertJson([
            'ok' => false,
            'code' => 'EVIDENCE_NOT_ENABLED',
        ]);
    }

    public function test_upload_persists_and_returns_metadata(): void
    {
        config()->set('core.evidence.enabled', true);

        $content = "hello\n";
        $file = UploadedFile::fake()->createWithContent('small.txt', $content, 'text/plain');

        $res = $this->postJson('/evidence', ['file' => $file]);

        $res->assertCreated()
            ->assertJsonStructure(['ok','id','version','sha256','size','mime','name'])
            ->assertJson([
                'ok'   => true,
                'name' => 'small.txt',
                'size' => strlen($content),
            ]);

        $data = $res->json();
        $this->assertMatchesRegularExpression('/^ev_[0-9A-HJKMNP-TV-Z]{26}$/', $data['id']);

        $this->assertDatabaseHas('evidence', [
            'id'       => $data['id'],
            'filename' => 'small.txt',
            'version'  => 1,
        ]);
    }
}

