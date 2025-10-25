<?php

declare(strict_types=1);

namespace Tests\Feature\Admin;

use App\Models\Role;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;
use Tests\TestCase;

final class SettingsControllerTest extends TestCase
{
    public function test_get_settings_returns_defaults(): void
    {
        $response = $this->getJson('/admin/settings');

        $response->assertOk();

        $this->assertTrue($response->headers->has('ETag'));
        $etagValue = (string) $response->headers->get('ETag');
        $this->assertStringStartsWith('W/"', $etagValue);
        $this->assertStringContainsString('settings:', $etagValue);
        $cacheControl = (string) $response->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $response->assertHeader('Pragma', 'no-cache');

        $response->assertJson(fn (AssertableJson $json) => $json->where('ok', true)
            ->has('config.core.rbac', fn (AssertableJson $j) => $j->where('enabled', true)
                ->whereType('roles', 'array')
                ->whereType('require_auth', 'boolean')
                ->has('user_search', fn (AssertableJson $us) => $us->whereType('default_per_page', 'integer')->etc()
                )
                ->etc()
            )
            ->has('config.core.audit', fn (AssertableJson $j) => $j->where('enabled', true)
                ->whereType('retention_days', 'integer')
                ->etc()
            )
            ->has('config.core.evidence', fn (AssertableJson $j) => $j->where('enabled', true)
                ->whereType('max_mb', 'integer')
                ->whereType('allowed_mime', 'array')
                ->whereType('blob_storage_path', 'string')
                ->etc()
            )
            ->has('config.core.avatars', fn (AssertableJson $j) => $j->where('enabled', true)
                ->where('size_px', 128)
                ->where('format', 'webp')
                ->etc()
            )
            ->has('config.saml.security', fn (AssertableJson $j) => $j
                ->whereType('authnRequestsSigned', 'boolean')
                ->whereType('wantAssertionsSigned', 'boolean')
                ->whereType('wantAssertionsEncrypted', 'boolean')
                ->etc()
            )
            ->has('config.saml.sp', fn (AssertableJson $j) => $j
                ->whereType('x509cert', 'string')
                ->whereType('privateKey', 'string')
                ->whereType('privateKeyPath', 'string')
                ->whereType('privateKeyPassphrase', 'string')
                ->etc()
            )
            ->missing('config.core.auth.saml')
        );
    }

    public function test_get_settings_returns_not_modified_when_etag_matches(): void
    {
        $response = $this->getJson('/admin/settings');
        $etag = $response->headers->get('ETag');

        $this->assertNotNull($etag);

        $second = $this->withHeaders(['If-None-Match' => $etag])->getJson('/admin/settings');

        $second->assertNoContent(304);
        $second->assertHeader('ETag', $etag);
        $cacheControl = (string) $second->headers->get('Cache-Control');
        $this->assertStringContainsString('no-store', $cacheControl);
        $this->assertStringContainsString('max-age=0', $cacheControl);
        $second->assertHeader('Pragma', 'no-cache');
    }

    public function test_get_settings_requires_auth_when_flag_enabled(): void
    {
        config()->set('core.rbac.require_auth', true);

        $response = $this->getJson('/admin/settings');

        $response->assertUnauthorized();
    }

    public function test_post_settings_accepts_spec_shape_and_normalizes(): void
    {
        $cert = <<<'CERT'
-----BEGIN CERTIFICATE-----
TEST
-----END CERTIFICATE-----
CERT;

        $privateKey = <<<'KEY'
-----BEGIN PRIVATE KEY-----
TEST
-----END PRIVATE KEY-----
KEY;

        $payload = [
            'rbac' => ['enabled' => true, 'roles' => ['Admin', 'Auditor', 'Risk Manager', 'User']],
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'], 'blob_storage_path' => '/opt/phpgrc/shared/blobs'],
            'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
            'saml' => [
                'security' => [
                    'authnRequestsSigned' => true,
                    'wantAssertionsSigned' => false,
                    'wantAssertionsEncrypted' => true,
                ],
                'sp' => [
                    'x509cert' => $cert,
                    'privateKey' => $privateKey,
                    'privateKeyPath' => '/opt/phpgrc/shared/sp.key',
                    'privateKeyPassphrase' => 'secret',
                ],
            ],
        ];

        $response = $this->postJson('/admin/settings', $payload);

        $response->assertOk();

        $this->assertTrue($response->headers->has('ETag'));
        $response->assertJson(fn (AssertableJson $json) => $json->where('ok', true)
            ->where('applied', false)
            ->where('note', 'stub-only')
            ->whereType('etag', 'string')
            ->has('config.core')
            ->has('accepted', fn (AssertableJson $j) => $j->hasAll(['rbac', 'audit', 'evidence', 'avatars', 'saml'])
                ->where('avatars.size_px', 128)
                ->where('avatars.format', 'webp')
                ->where('saml.security.authnRequestsSigned', true)
                ->where('saml.security.wantAssertionsSigned', false)
                ->where('saml.security.wantAssertionsEncrypted', true)
                ->where('saml.sp.x509cert', $cert)
                ->where('saml.sp.privateKeyPath', '/opt/phpgrc/shared/sp.key')
            )
        );
    }

    public function test_post_settings_rejects_legacy_contract_payload(): void
    {
        $payload = [
            'core' => [
                'rbac' => ['enabled' => true, 'roles' => ['Admin', 'Auditor', 'Risk Manager', 'User']],
                'audit' => ['enabled' => true, 'retention_days' => 365],
                'evidence' => ['enabled' => true, 'max_mb' => 25, 'allowed_mime' => ['application/pdf', 'image/png', 'image/jpeg', 'text/plain'], 'blob_storage_path' => '/opt/phpgrc/shared/blobs'],
                'avatars' => ['enabled' => true, 'size_px' => 128, 'format' => 'webp'],
                'auth' => [
                    'saml' => [
                        'sp' => [
                            'sign_authn_requests' => true,
                            'want_assertions_signed' => false,
                            'want_assertions_encrypted' => true,
                            'certificate' => 'legacy',
                            'private_key' => 'legacy-key',
                            'private_key_path' => '/opt/phpgrc/legacy.key',
                            'private_key_passphrase' => 'legacy-pass',
                        ],
                    ],
                ],
            ],
        ];

        $response = $this->postJson('/admin/settings', $payload);

        $response->assertStatus(422);
        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('ok', false)
            ->where('code', 'VALIDATION_FAILED')
            ->where('errors.core.auth.0', 'The core.auth field is prohibited.')
            ->where('errors.core.auth.saml.0', 'The core.auth.saml field is prohibited.')
            ->etc()
        );
    }

    public function test_post_settings_requires_auth_when_flag_enabled(): void
    {
        config()->set('core.rbac.require_auth', true);

        $payload = [
            'audit' => ['enabled' => true, 'retention_days' => 365],
            'apply' => true,
        ];

        $response = $this->postJson('/admin/settings', $payload);

        $response->assertUnauthorized();
    }

    public function test_post_settings_accepts_saml_contract_payload(): void
    {
        $cert = <<<'CERT'
-----BEGIN CERTIFICATE-----
UI
-----END CERTIFICATE-----
CERT;

        $privateKey = <<<'KEY'
-----BEGIN PRIVATE KEY-----
UI
-----END PRIVATE KEY-----
KEY;

        $payload = [
            'saml' => [
                'security' => [
                    'authnRequestsSigned' => true,
                    'wantAssertionsSigned' => false,
                    'wantAssertionsEncrypted' => true,
                ],
                'sp' => [
                    'x509cert' => $cert,
                    'privateKey' => $privateKey,
                    'privateKeyPath' => '/opt/phpgrc/sp.key',
                    'privateKeyPassphrase' => 'pass',
                ],
            ],
        ];

        $response = $this->postJson('/admin/settings', $payload);

        $response->assertOk();
        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('ok', true)
            ->where('applied', false)
            ->has('accepted', fn (AssertableJson $accepted) => $accepted
                ->where('saml.security.authnRequestsSigned', true)
                ->where('saml.security.wantAssertionsSigned', false)
                ->where('saml.security.wantAssertionsEncrypted', true)
                ->where('saml.sp.x509cert', $cert)
                ->where('saml.sp.privateKeyPath', '/opt/phpgrc/sp.key')
                ->etc()
            )
            ->etc()
        );
    }

    public function test_post_settings_requires_if_match_when_applying_changes(): void
    {
        $user = $this->createAdminUser();
        $payload = [
            'audit' => ['enabled' => true, 'retention_days' => 400],
            'apply' => true,
        ];

        $response = $this->actingAs($user)->postJson('/admin/settings', $payload);

        $response->assertStatus(409);
        $response->assertHeader('ETag');
        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('ok', false)
            ->where('code', 'PRECONDITION_FAILED')
            ->whereType('current_etag', 'string')
            ->etc()
        );
    }

    public function test_post_settings_rejects_stale_etag(): void
    {
        $user = $this->createAdminUser();
        $staleEtag = 'W/'.chr(34).'settings:stale'.chr(34);
        $payload = [
            'audit' => ['enabled' => true, 'retention_days' => 400],
            'apply' => true,
        ];

        $response = $this->actingAs($user)
            ->withHeaders(['If-Match' => $staleEtag])
            ->postJson('/admin/settings', $payload);

        $response->assertStatus(409);
        $response->assertHeader('ETag');
        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('ok', false)
            ->where('code', 'PRECONDITION_FAILED')
            ->whereType('current_etag', 'string')
            ->etc()
        );
    }

    public function test_post_settings_applies_changes_with_valid_etag(): void
    {
        $user = $this->createAdminUser();

        $initial = $this->getJson('/admin/settings');
        $initialEtag = (string) $initial->headers->get('ETag');

        $payload = [
            'audit' => ['enabled' => true, 'retention_days' => 730],
            'apply' => true,
        ];

        $response = $this->actingAs($user)
            ->withHeaders(['If-Match' => $initialEtag])
            ->postJson('/admin/settings', $payload);

        $response->assertOk();
        $response->assertHeader('ETag');

        $newEtag = (string) $response->headers->get('ETag');
        $this->assertNotSame($initialEtag, $newEtag);

        $response->assertJson(fn (AssertableJson $json) => $json
            ->where('ok', true)
            ->where('applied', true)
            ->where('etag', $newEtag)
            ->where('config.core.audit.retention_days', 730)
            ->has('changes')
            ->etc()
        );

        $latest = $this->getJson('/admin/settings');
        $latest->assertOk();
        $this->assertSame($newEtag, (string) $latest->headers->get('ETag'));
        $latest->assertJson(fn (AssertableJson $json) => $json
            ->where('config.core.audit.retention_days', 730)
            ->etc());

        $this->assertDatabaseHas('core_settings', [
            'key' => 'core.audit.retention_days',
        ]);
    }

    private function createAdminUser(): User
    {
        $role = Role::query()->firstOrCreate(
            ['id' => 'role_admin'],
            ['name' => 'Admin']
        );

        $user = User::factory()->create();
        $user->roles()->sync([$role->getAttribute('id')]);

        return $user;
    }
}
