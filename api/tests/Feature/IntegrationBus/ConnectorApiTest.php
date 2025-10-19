<?php

declare(strict_types=1);

namespace Tests\Feature\IntegrationBus;

use App\Models\IntegrationConnector;
use App\Models\Role;
use App\Models\User;
use App\Support\Rbac\PolicyMap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\Test;
use Tests\TestCase;

final class ConnectorApiTest extends TestCase
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

        if (Schema::hasTable('policy_role_assignments')) {
            DB::table('policy_role_assignments')->updateOrInsert(
                [
                    'policy' => 'integrations.connectors.manage',
                    'role_id' => 'role_admin',
                ],
                [
                    'created_at' => now('UTC')->toDateTimeString(),
                    'updated_at' => now('UTC')->toDateTimeString(),
                ]
            );
        }

        PolicyMap::clearCache();

        if (Schema::hasTable('roles')) {
            Role::query()->updateOrCreate(['id' => 'role_admin'], ['name' => 'Admin']);
        }

        /** @var User $admin */
        $admin = User::factory()->create([
            'name' => 'Admin',
            'email' => 'admin@example.test',
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

        $this->getJson('/integrations/connectors')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('items', [])
            ->assertJsonPath('meta.total', 0);
    }

    #[Test]
    public function store_creates_connector_and_encrypts_config(): void
    {
        $this->actingAsAdmin();

        $payload = [
            'key' => 'aws-config',
            'name' => 'AWS Config',
            'kind' => 'asset.discovery',
            'enabled' => true,
            'config' => [
                'access_key' => 'AKIA123',
                'secret_key' => 'super-secret',
            ],
            'meta' => [
                'owner' => 'infra',
            ],
        ];

        $this->postJson('/integrations/connectors', $payload)
            ->assertStatus(201)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('connector.key', 'aws-config')
            ->assertJsonPath('connector.config.access_key', 'AKIA123')
            ->assertJsonPath('connector.enabled', true);

        /** @var IntegrationConnector $connector */
        $connector = IntegrationConnector::query()->firstOrFail();
        self::assertSame('aws-config', $connector->key);
        self::assertSame('AWS Config', $connector->name);
        self::assertSame('asset.discovery', $connector->kind);
        self::assertTrue($connector->enabled);
        self::assertSame('super-secret', $connector->config['secret_key'] ?? null);

        $raw = $connector->getRawOriginal('config');
        self::assertIsString($raw);
        self::assertNotSame('', $raw);
        self::assertStringNotContainsString('super-secret', $raw);
    }

    #[Test]
    public function show_returns_connector_by_key(): void
    {
        $this->actingAsAdmin();

        $connector = IntegrationConnector::query()->create([
            'key' => 'servicenow',
            'name' => 'ServiceNow',
            'kind' => 'incident.event',
            'enabled' => false,
            'config' => ['instance' => 'demo', 'token' => 'abc123'],
            'meta' => ['region' => 'us'],
        ]);

        $this->getJson('/integrations/connectors/servicenow')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('connector.id', $connector->id)
            ->assertJsonPath('connector.config.instance', 'demo');
    }

    #[Test]
    public function update_applies_partial_changes(): void
    {
        $this->actingAsAdmin();

        $connector = IntegrationConnector::query()->create([
            'key' => 'okta',
            'name' => 'Okta',
            'kind' => 'auth.provider',
            'enabled' => false,
            'config' => ['domain' => 'example.okta.com', 'token' => 't1'],
        ]);

        $this->patchJson("/integrations/connectors/{$connector->id}", [
            'enabled' => true,
            'config' => ['domain' => 'new.example.okta.com', 'token' => 't2'],
        ])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('connector.enabled', true)
            ->assertJsonPath('connector.config.token', 't2');

        $connector->refresh();
        self::assertTrue($connector->enabled);
        self::assertSame('new.example.okta.com', $connector->config['domain'] ?? null);
    }

    #[Test]
    public function destroy_removes_connector(): void
    {
        $this->actingAsAdmin();

        $connector = IntegrationConnector::query()->create([
            'key' => 'pagerduty',
            'name' => 'PagerDuty',
            'kind' => 'incident.event',
            'enabled' => true,
            'config' => ['token' => 'pd-secret'],
        ]);

        $this->deleteJson("/integrations/connectors/{$connector->key}")
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('deleted', 'pagerduty');

        self::assertDatabaseMissing('integration_connectors', ['id' => $connector->id]);
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

        $this->getJson('/integrations/connectors')
            ->assertStatus(403)
            ->assertJsonPath('ok', false)
            ->assertJsonPath('code', 'FORBIDDEN');
    }
}
