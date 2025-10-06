<?php

declare(strict_types=1);

namespace Tests\Feature\Capabilities;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

final class CapabilityGatesTest extends TestCase
{
    use RefreshDatabase;

    private function adminUser(): User
    {
        /** @var User $user */
        $user = User::factory()->create();

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => 'admin'],
            ['name' => 'Admin']
        );

        $user->roles()->sync([$role->id]);

        return $user;
    }

    private function baseRbacPersist(): void
    {
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', false);
        config()->set('core.audit.enabled', true);
        config()->set('core.metrics.throttle.enabled', false);
    }

    /** Try with and without the /api prefix to handle env differences. */
    private function getApi(string $path, array $headers = []): TestResponse
    {
        $headers = array_merge(['Accept' => 'application/json'], $headers);

        $r = $this->get($path, $headers);
        if ($r->getStatusCode() === 404 && str_starts_with($path, '/api/')) {
            $r = $this->get(substr($path, 4), $headers);
        } elseif ($r->getStatusCode() === 404 && ! str_starts_with($path, '/api/')) {
            $r = $this->get('/api'.$path, $headers);
        }

        return $r;
    }

    private function postApi(string $path, array $data = [], array $headers = []): TestResponse
    {
        $headers = array_merge(['Accept' => 'application/json'], $headers);

        $r = $this->post($path, $data, $headers);
        if ($r->getStatusCode() === 404 && str_starts_with($path, '/api/')) {
            $r = $this->post(substr($path, 4), $data, $headers);
        } elseif ($r->getStatusCode() === 404 && ! str_starts_with($path, '/api/')) {
            $r = $this->post('/api'.$path, $data, $headers);
        }

        return $r;
    }

    public function test_audit_export_denied_when_capability_disabled(): void
    {
        $this->baseRbacPersist();
        config()->set('core.capabilities.core.audit.export', false);

        $user = $this->adminUser();

        $resp = $this->actingAs($user)->getApi('/api/audit/export.csv');
        $resp->assertStatus(403);
        $json = $resp->json();
        $this->assertSame('CAPABILITY_DISABLED', (string) ($json['code'] ?? ''));
    }

    public function test_audit_export_allowed_when_capability_enabled(): void
    {
        $this->baseRbacPersist();
        config()->set('core.capabilities.core.audit.export', true);

        $user = $this->adminUser();

        $resp = $this->actingAs($user)->getApi('/api/audit/export.csv');
        $this->assertContains($resp->getStatusCode(), [200, 206]); // stream may be partial
        $this->assertNotSame('', (string) $resp->getContent());
    }

    public function test_evidence_upload_denied_when_capability_disabled(): void
    {
        $this->baseRbacPersist();
        config()->set('core.capabilities.core.evidence.upload', false);

        $user = $this->adminUser();

        Storage::fake('local');
        $file = UploadedFile::fake()->create('note.txt', 1, 'text/plain');

        $resp = $this->actingAs($user)->postApi('/api/evidence', [
            'file' => $file,
        ]);

        $resp->assertStatus(403);
        $json = $resp->json();
        $this->assertSame('CAPABILITY_DISABLED', (string) ($json['code'] ?? ''));
    }

    public function test_evidence_upload_allowed_when_capability_enabled(): void
    {
        $this->baseRbacPersist();
        config()->set('core.capabilities.core.evidence.upload', true);

        $user = $this->adminUser();

        Storage::fake('local');
        $file = UploadedFile::fake()->create('doc.txt', 1, 'text/plain');

        $resp = $this->actingAs($user)->postApi('/api/evidence', [
            'file' => $file,
        ]);

        $this->assertContains($resp->getStatusCode(), [200, 201]);
        $json = $resp->json();
        $this->assertTrue((bool) ($json['ok'] ?? false));
        $this->assertArrayHasKey('id', $json);
    }
}
