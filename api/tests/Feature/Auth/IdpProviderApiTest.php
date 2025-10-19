<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Models\AuditEvent;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class IdpProviderApiTest extends TestCase
{
    use RefreshDatabase;

    private ?User $admin = null;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'core.rbac.enabled' => true,
            'core.rbac.mode' => 'persist',
            'core.rbac.persistence' => true,
            'core.rbac.require_auth' => false,
        ]);

        if (Schema::hasTable('roles')) {
            Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        }

        PolicyMap::clearCache();

        /** @var User $admin */
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin-idp@example.test',
        ]);

        $this->admin = $admin;

        if (Schema::hasTable('role_user')) {
            $admin->roles()->sync(['role_admin']);
        }
    }

    private function actingAsAdmin(): void
    {
        Sanctum::actingAs($this->admin ?? User::factory()->create());
    }

    #[Test]
    public function index_returns_empty_collection_initially(): void
    {
        $this->actingAsAdmin();

        $this->getJson('/admin/idp/providers')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items', [])
            ->assertJsonPath('meta.total', 0)
            ->assertJsonPath('meta.enabled', 0);
    }

    #[Test]
    public function store_creates_provider_and_encrypts_secret(): void
    {
        $this->actingAsAdmin();

        $payload = [
            'key' => 'okta-primary',
            'name' => 'Okta Primary',
            'driver' => 'oidc',
            'enabled' => true,
            'config' => [
                'issuer' => 'https://okta.example.test',
                'client_id' => 'client-123',
                'client_secret' => 'super-secret',
            ],
            'meta' => [
                'region' => 'us',
            ],
        ];

        $this->postJson('/admin/idp/providers', $payload)
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider.key', 'okta-primary')
            ->assertJsonPath('provider.config.client_id', 'client-123')
            ->assertJsonPath('provider.enabled', true)
            ->assertJsonPath('provider.evaluation_order', 1);

        /** @var IdpProvider $provider */
        $provider = IdpProvider::query()->firstOrFail();
        self::assertSame('okta-primary', $provider->key);
        self::assertSame('Okta Primary', $provider->name);
        self::assertSame('oidc', $provider->driver);
        self::assertTrue($provider->enabled);
        self::assertSame('super-secret', $provider->config['client_secret'] ?? null);

        $raw = $provider->getRawOriginal('config');
        self::assertIsString($raw);
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString('super-secret', $raw);

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()->where('action', 'idp.provider.created')->first();
        self::assertNotNull($audit);
        self::assertSame($provider->id, $audit->entity_id);
    }

    #[Test]
    public function show_returns_provider_by_key(): void
    {
        $this->actingAsAdmin();

        $provider = IdpProvider::query()->create([
            'key' => 'azure-ad',
            'name' => 'Azure AD',
            'driver' => 'oidc',
            'enabled' => false,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://login.microsoftonline.com/tenant/v2.0',
                'client_id' => 'client',
                'client_secret' => 'secret',
            ],
        ]);

        $this->getJson('/admin/idp/providers/azure-ad')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider.id', $provider->id)
            ->assertJsonPath('provider.config.issuer', 'https://login.microsoftonline.com/tenant/v2.0');
    }

    #[Test]
    public function saml_validation_requires_mandatory_fields(): void
    {
        $this->actingAsAdmin();

        $response = $this->postJson('/admin/idp/providers', [
            'key' => 'saml-primary',
            'name' => 'SAML Primary',
            'driver' => 'saml',
            'config' => [
                'entity_id' => 'https://sso.example.test/entity',
            ],
        ]);

        $response->assertStatus(422);
        $errors = $response->json('errors');
        self::assertIsArray($errors);
        self::assertArrayHasKey('config.sso_url', $errors);
        self::assertArrayHasKey('config.certificate', $errors);
        self::assertSame('SSO URL must be a valid URL.', $errors['config.sso_url'][0] ?? null);
        self::assertSame('Signing certificate is required.', $errors['config.certificate'][0] ?? null);
    }

    #[Test]
    public function update_allows_reordering_and_toggle(): void
    {
        $this->actingAsAdmin();

        $first = IdpProvider::query()->create([
            'key' => 'first',
            'name' => 'First',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://idp.example/first',
                'client_id' => 'first',
                'client_secret' => 'first-secret',
            ],
        ]);

        $second = IdpProvider::query()->create([
            'key' => 'second',
            'name' => 'Second',
            'driver' => 'oidc',
            'enabled' => false,
            'evaluation_order' => 2,
            'config' => [
                'issuer' => 'https://idp.example/second',
                'client_id' => 'second',
                'client_secret' => 'second-secret',
            ],
        ]);

        $this->patchJson("/admin/idp/providers/{$second->id}", [
            'enabled' => true,
            'evaluation_order' => 1,
        ])
            ->assertStatus(200)
            ->assertJsonPath('provider.enabled', true)
            ->assertJsonPath('provider.evaluation_order', 1);

        $first->refresh();
        $second->refresh();

        self::assertSame(2, $first->evaluation_order);
        self::assertSame(1, $second->evaluation_order);

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()
            ->where('action', 'idp.provider.updated')
            ->where('entity_id', $second->id)
            ->latest('occurred_at')
            ->first();
        self::assertNotNull($audit);
    }

    #[Test]
    public function destroy_removes_provider_and_collapse_order(): void
    {
        $this->actingAsAdmin();

        $provider = IdpProvider::query()->create([
            'key' => 'remove-me',
            'name' => 'Remove Me',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://remove.test',
                'client_id' => 'remove',
                'client_secret' => 'remove',
            ],
        ]);

        $other = IdpProvider::query()->create([
            'key' => 'stay',
            'name' => 'Stay',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 2,
            'config' => [
                'issuer' => 'https://stay.test',
                'client_id' => 'stay',
                'client_secret' => 'stay',
            ],
        ]);

        $this->deleteJson('/admin/idp/providers/remove-me')
            ->assertStatus(200)
            ->assertJsonPath('deleted', 'remove-me');

        self::assertDatabaseMissing('idp_providers', ['id' => $provider->id]);
        $other->refresh();
        self::assertSame(1, $other->evaluation_order);

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()
            ->where('action', 'idp.provider.deleted')
            ->where('entity_id', $provider->id)
            ->first();
        self::assertNotNull($audit);
    }

    #[Test]
    public function health_endpoint_updates_provider_metadata(): void
    {
        $this->actingAsAdmin();

        /** @var IdpProvider $provider */
        $provider = IdpProvider::query()->create([
            'key' => 'check-me',
            'name' => 'Check Me',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://health.example.test',
                'client_id' => 'client-health',
                'client_secret' => 'secret-health',
                'scopes' => ['openid', 'profile'],
            ],
        ]);

        $this->postJson("/admin/idp/providers/{$provider->id}/health")
            ->assertStatus(200)
            ->assertJsonPath('status', IdpHealthCheckResult::STATUS_OK)
            ->assertJsonPath('provider.id', $provider->id);

        $provider->refresh();
        self::assertNotNull($provider->last_health_at);
        $healthMeta = Arr::get($provider->meta, 'health');
        self::assertIsArray($healthMeta);
        self::assertSame('ok', $healthMeta['status'] ?? null);

        /** @var AuditEvent|null $audit */
        $audit = AuditEvent::query()
            ->where('action', 'idp.provider.health_checked')
            ->where('entity_id', $provider->id)
            ->first();
        self::assertNotNull($audit);
    }

    #[Test]
    public function policy_denies_requests_without_assignment(): void
    {
        PolicyMap::clearCache();

        /** @var User $user */
        $user = User::factory()->create([
            'name' => 'Viewer',
            'email' => 'viewer@example.test',
        ]);

        Sanctum::actingAs($user);

        $this->getJson('/admin/idp/providers')
            ->assertStatus(403);
    }
}
