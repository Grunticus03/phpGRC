<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Auth\Idp\DTO\IdpHealthCheckResult;
use App\Models\AuditEvent;
use App\Models\IdpProvider;
use App\Models\Role;
use App\Models\User;
use App\Services\Auth\SamlMetadataService;
use App\Support\Rbac\PolicyMap;
use Carbon\CarbonImmutable;
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
    public function preview_saml_metadata_parses_configuration(): void
    {
        $this->actingAsAdmin();

        $metadata = $this->sampleMetadataXml();

        $this->postJson('/admin/idp/providers/saml/metadata/preview', [
            'metadata' => $metadata,
        ])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('config.entity_id', 'https://sso.example.test/entity')
            ->assertJsonPath('config.sso_url', 'https://sso.example.test/login');
    }

    #[Test]
    public function import_saml_metadata_updates_provider_config(): void
    {
        $this->actingAsAdmin();

        /** @var IdpProvider $provider */
        $provider = IdpProvider::query()->create([
            'key' => 'saml-primary',
            'name' => 'SAML Primary',
            'driver' => 'saml',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'entity_id' => 'https://old.example.test',
                'sso_url' => 'https://old.example.test/login',
                'certificate' => $this->sampleCertificate(),
            ],
        ]);

        $metadata = $this->sampleMetadataXml();

        $this->postJson("/admin/idp/providers/{$provider->id}/saml/metadata", [
            'metadata' => $metadata,
        ])
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('provider.config.entity_id', 'https://sso.example.test/entity');

        $provider->refresh();

        self::assertSame('https://sso.example.test/entity', $provider->config['entity_id'] ?? null);
        self::assertSame('https://sso.example.test/login', $provider->config['sso_url'] ?? null);
        self::assertStringContainsString('BEGIN CERTIFICATE', $provider->config['certificate'] ?? '');

        $meta = $provider->meta ?? [];
        self::assertIsArray($meta);
        self::assertArrayHasKey('saml', $meta);
        self::assertArrayHasKey('metadata_imported_at', $meta['saml']);
        self::assertSame(hash('sha256', trim($metadata)), $meta['saml']['metadata_sha256'] ?? null);
    }

    #[Test]
    public function export_saml_metadata_returns_xml_document(): void
    {
        $this->actingAsAdmin();

        /** @var IdpProvider $provider */
        $provider = IdpProvider::query()->create([
            'key' => 'saml-export',
            'name' => 'SAML Export',
            'driver' => 'saml',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'entity_id' => 'https://sso.example.test/entity',
                'sso_url' => 'https://sso.example.test/login',
                'certificate' => $this->sampleCertificate(),
            ],
        ]);

        $response = $this->get("/admin/idp/providers/{$provider->id}/saml/metadata");

        $response->assertStatus(200);
        self::assertSame('application/samlmetadata+xml; charset=UTF-8', $response->headers->get('Content-Type'));

        $xml = $response->getContent();
        self::assertIsString($xml);

        $document = simplexml_load_string($xml);
        self::assertInstanceOf(\SimpleXMLElement::class, $document);
        $document->registerXPathNamespace('md', 'urn:oasis:names:tc:SAML:2.0:metadata');
        $descriptor = $document->xpath('/md:EntityDescriptor');
        self::assertNotFalse($descriptor);
        self::assertNotEmpty($descriptor);
        self::assertSame('https://sso.example.test/entity', (string) ($descriptor[0]['entityID'] ?? ''));
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

    private function sampleMetadataXml(): string
    {
        /** @var SamlMetadataService $service */
        $service = app(SamlMetadataService::class);

        return $service->generate([
            'entity_id' => 'https://sso.example.test/entity',
            'sso_url' => 'https://sso.example.test/login',
            'certificate' => $this->sampleCertificate(),
        ], CarbonImmutable::create(2030, 1, 1, 0, 0, 0, 'UTC'));
    }

    private function sampleCertificate(): string
    {
        return <<<'PEM'
-----BEGIN CERTIFICATE-----
MIICxjCCAa6gAwIBAgIUJ7X7YvXy5whhtjfiPgk41IrT9NAwDQYJKoZIhvcNAQEL
BQAwFTETMBEGA1UEAwwKc2FtbC10ZXN0MB4XDTI0MDEwMTAwMDAwMFoXDTM0MDEw
MTAwMDAwMFowFTETMBEGA1UEAwwKc2FtbC10ZXN0MIIBIjANBgkqhkiG9w0BAQEF
AAOCAQ8AMIIBCgKCAQEAr0pWQw0Hnq2ZlzfFbY3ybCmqa7e9DbKoTz3RvqFAn0Zf
IyO6jz5Dm48uoTWMukmMZ0P6E4ha3YJ4bLBPOfSmf/C4C5Qw9p+S5o5MWHbJkI7j
eWrjqh5ws8wX9AtUKgLw9SL98QtZVFBO3T8kA9OVdQ04cw/9ezEr6QO034QXkdpZ
5PGlTma63bplVOwUhbeGdnPL4489VJ5SACoQwQkn1vmpj6m7pKOGDWUy4KfUU8cX
nBASezPK5ghI1lpMUgUo/lhjggrB4/9lgYQtImHXQImiXoAhlmlpiG8Wp3xfpqgs
iY0QMhVNfy/7xKQXIDYiJlEUpP2Zjz4/v7K6HbqTMwIDAQABo1MwUTAdBgNVHQ4E
FgQUJDYw7w7wPwhjNzHNxog8Ppytg9kwHwYDVR0jBBgwFoAUJDYw7w7wPwhjNzHN
xog8Ppytg9kwDwYDVR0TAQH/BAUwAwEB/zANBgkqhkiG9w0BAQsFAAOCAQEAOYxQ
Gk4J4Pp8sVhQOWtmZ6vhj71R1z70dr50xj43Xj1H0w4WW+0lDuzHggzYM4G52g6l
2kBfnVBCcm/jRkDj1qGi6pQsKEd+bcfWZH7LvXsKTRZLdxDGjszT+2Xl9V7mWVPf
b0q8zKk0qJWQGFucvQKig9wAbHR0GmP2oRlOiuAIs61hp7d2kIs2cUJEsARQdILQ
efvHgTQgqYwZvh7gApS9Vz90suDWJ3+YkNPv6L+s7PJlIQMXM63iXU1BYf1YsKaA
X3h5xUEwsDPN9/5lCq3QxvxZ2O8xbrv6iH98sX8j5dhPj6l3SeSYyYqO6xbyicWG
ECdAPOTwv0u62Y8JEA==
-----END CERTIFICATE-----
PEM;
    }
}
