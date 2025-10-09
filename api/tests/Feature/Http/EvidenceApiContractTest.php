<?php

declare(strict_types=1);

namespace Tests\Feature\Http;

use App\Http\Middleware\RbacMiddleware;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class EvidenceApiContractTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Gate::before(static fn () => true);
        $this->withoutMiddleware(RbacMiddleware::class);
    }

    public function test_upload_response_uses_size_and_not_size_bytes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('doc.pdf', 7, 'application/pdf');

        $res = $this->post('/evidence', ['file' => $file]);

        $res->assertCreated()
            ->assertJsonStructure(['ok', 'id', 'version', 'sha256', 'size', 'mime', 'name'])
            ->assertJsonMissingPath('size_bytes');

        $json = $res->json();
        $this->assertIsInt($json['size']);
        $this->assertGreaterThanOrEqual(7 * 1024, $json['size']);
    }

    public function test_list_uses_size_and_not_size_bytes(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('x.pdf', 3, 'application/pdf');
        $this->post('/evidence', ['file' => $file])->assertCreated();

        $list = $this->get('/evidence')->assertOk();

        $data = $list->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);

        $first = $data[0];
        $this->assertArrayHasKey('size', $first);
        $this->assertArrayNotHasKey('size_bytes', $first);
        $this->assertIsInt($first['size']);
        $this->assertGreaterThanOrEqual(3 * 1024, $first['size']);
    }

    public function test_missing_file_returns_validation_failed_shape(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $res = $this->postJson('/evidence', []);

        $res->assertStatus(422)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'VALIDATION_FAILED')
            ->assertJsonPath('message', 'Upload validation failed: The file field is required.')
            ->assertJsonStructure(['errors' => ['file']]);
    }

    public function test_disabled_returns_400(): void
    {
        config(['core.evidence.enabled' => false]);

        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('doc.pdf', 1, 'application/pdf');

        $res = $this->post('/evidence', ['file' => $file]);

        $res->assertStatus(400)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'EVIDENCE_NOT_ENABLED');
    }
}
