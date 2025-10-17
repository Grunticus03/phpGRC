<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\AuditEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\UiSettingsService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    private string $brandAssetRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->brandAssetRoot = storage_path('app/test-ui-brand-assets');
        Config::set('ui.defaults.brand.assets.filesystem_path', $this->brandAssetRoot);
        $filesystem = new Filesystem;
        if ($filesystem->isDirectory($this->brandAssetRoot)) {
            $filesystem->deleteDirectory($this->brandAssetRoot);
        }
    }

    protected function tearDown(): void
    {
        $filesystem = new Filesystem;
        if (isset($this->brandAssetRoot) && $filesystem->isDirectory($this->brandAssetRoot)) {
            $filesystem->deleteDirectory($this->brandAssetRoot);
        }
        parent::tearDown();
    }

    public function test_get_ui_settings_returns_defaults_with_etag(): void
    {
        $this->actingAsThemeManager();

        $response = $this->getJson('/settings/ui');
        $response->assertOk();

        $body = $response->json();
        self::assertIsArray($body);
        self::assertSame(true, $body['ok'] ?? null);
        self::assertIsArray($body['config']['ui'] ?? null);
        self::assertIsString($body['etag'] ?? null);

        $etag = $response->headers->get('ETag');
        self::assertNotNull($etag);
        self::assertSame($etag, $body['etag']);
    }

    public function test_get_ui_settings_allows_anonymous_when_auth_disabled(): void
    {
        Config::set('core.rbac.require_auth', false);

        $response = $this->getJson('/settings/ui');

        $response->assertOk();
    }

    public function test_get_ui_settings_requires_auth_when_flag_enabled(): void
    {
        Config::set('core.rbac.require_auth', true);

        $response = $this->getJson('/settings/ui');

        $response->assertUnauthorized();
    }

    public function test_get_ui_settings_honors_string_false_flag(): void
    {
        Config::set('core.rbac.require_auth', 'false');

        $response = $this->getJson('/settings/ui');

        $response->assertOk();
    }

    public function test_get_ui_settings_honors_string_true_flag(): void
    {
        Config::set('core.rbac.require_auth', 'true');

        $response = $this->getJson('/settings/ui');

        $response->assertUnauthorized();
    }

    public function test_put_ui_settings_requires_if_match(): void
    {
        $this->actingAsThemeManager();

        $response = $this->putJson('/settings/ui', [
            'ui' => [
                'theme' => [
                    'default' => 'cosmo',
                ],
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'ok' => false,
            'code' => 'PRECONDITION_FAILED',
        ]);
    }

    public function test_put_ui_settings_updates_configuration(): void
    {
        $user = $this->actingAsThemeManager();

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'theme' => [
                    'default' => 'cosmo',
                    'allow_user_override' => false,
                    'force_global' => true,
                    'overrides' => [
                        'color.primary' => '#ff0000',
                        'shadow' => 'light',
                    ],
                    'login' => [
                        'layout' => 'layout_2',
                    ],
                ],
                'brand' => [
                    'title_text' => 'Custom Dashboard',
                    'footer_logo_disabled' => true,
                ],
            ],
        ];

        $updated = $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload);

        $updated->assertOk();
        $defaultBrandPath = config('ui.defaults.brand.assets.filesystem_path');
        self::assertIsString($defaultBrandPath);
        $updated->assertJson([
            'ok' => true,
            'config' => [
                'ui' => [
                    'theme' => [
                        'default' => 'cosmo',
                        'allow_user_override' => false,
                        'force_global' => true,
                        'login' => [
                            'layout' => 'layout_2',
                        ],
                    ],
                    'brand' => [
                        'title_text' => 'Custom Dashboard',
                        'footer_logo_disabled' => true,
                        'assets' => [
                            'filesystem_path' => $defaultBrandPath,
                        ],
                    ],
                ],
            ],
        ]);

        $newEtag = $updated->headers->get('ETag');
        self::assertNotNull($newEtag);
        self::assertNotSame($etag, $newEtag);

        /** @var UiSettingsService $service */
        $service = app(UiSettingsService::class);
        $config = $service->currentConfig();
        self::assertSame('cosmo', $config['theme']['default']);
        self::assertFalse($config['theme']['allow_user_override']);
        self::assertTrue($config['theme']['force_global']);
        self::assertSame('#ff0000', $config['theme']['overrides']['color.primary']);
        self::assertSame('layout_2', $config['theme']['login']['layout']);
        $overrideDefaults = config('ui.defaults.theme.overrides');
        self::assertIsArray($overrideDefaults);
        $defaultBackground = $overrideDefaults['color.background'] ?? null;
        self::assertIsString($defaultBackground);
        self::assertSame($defaultBackground, $config['theme']['overrides']['color.background']);
        self::assertSame($defaultBrandPath, $config['brand']['assets']['filesystem_path']);
    }

    public function test_put_ui_settings_accepts_layout_3(): void
    {
        $user = $this->actingAsThemeManager();

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'theme' => [
                    'login' => [
                        'layout' => 'layout_3',
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload);

        $response->assertOk();
        $response->assertJsonPath('config.ui.theme.login.layout', 'layout_3');

        /** @var UiSettingsService $service */
        $service = app(UiSettingsService::class);
        $config = $service->currentConfig();
        self::assertSame('layout_3', $config['theme']['login']['layout']);
    }

    public function test_get_ui_settings_honors_if_none_match_with_multiple_values(): void
    {
        $user = $this->actingAsThemeManager();

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $notModified = $this->withHeaders([
            'If-None-Match' => sprintf('W/"bogus", %s , W/"other"', $etag),
        ])->getJson('/settings/ui');

        $notModified->assertNoContent(304);
        self::assertSame($etag, $notModified->headers->get('ETag'));
        $cacheControl = $notModified->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
        self::assertSame('no-cache', $notModified->headers->get('Pragma'));
        self::assertSame('', $notModified->getContent());
    }

    public function test_put_ui_settings_accepts_wildcard_if_match_header(): void
    {
        $user = $this->actingAsThemeManager();

        $payload = [
            'ui' => [
                'theme' => [
                    'default' => 'darkly',
                    'allow_user_override' => true,
                    'force_global' => false,
                ],
                'brand' => [
                    'title_text' => 'Wildcard Update',
                ],
            ],
        ];

        $response = $this->withHeaders(['If-Match' => '*'])
            ->putJson('/settings/ui', $payload);

        $response->assertOk();
        $response->assertJsonFragment([
            'ok' => true,
            'etag' => $response->headers->get('ETag'),
        ]);

        /** @var UiSettingsService $service */
        $service = app(UiSettingsService::class);
        $config = $service->currentConfig();
        self::assertSame('darkly', $config['theme']['default']);
        self::assertSame('Wildcard Update', $config['brand']['title_text']);
        self::assertSame(
            config('ui.defaults.brand.assets.filesystem_path'),
            $config['brand']['assets']['filesystem_path']
        );
    }

    public function test_put_ui_settings_updates_brand_asset_path(): void
    {
        $user = $this->actingAsThemeManager();

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'brand' => [
                    'assets' => [
                        'filesystem_path' => 'var/www/custom-brands',
                    ],
                ],
            ],
        ];

        $response = $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload);

        $response->assertOk();
        $response->assertJsonFragment([
            'ok' => true,
        ]);

        /** @var UiSettingsService $service */
        $service = app(UiSettingsService::class);
        $config = $service->currentConfig();
        self::assertSame('/var/www/custom-brands', $config['brand']['assets']['filesystem_path']);

        $this->assertDatabaseHas('ui_settings', [
            'key' => 'ui.brand.assets.filesystem_path',
        ]);
    }

    public function test_put_ui_settings_rejects_stale_etag_and_returns_current(): void
    {
        $user = $this->actingAsThemeManager();

        $first = $this->getJson('/settings/ui');
        $first->assertOk();
        $staleEtag = $first->headers->get('ETag');
        self::assertNotNull($staleEtag);

        /** @var UiSettingsService $service */
        $service = app(UiSettingsService::class);
        $service->apply([
            'theme' => [
                'default' => 'cerulean',
                'force_global' => true,
            ],
        ], $user->id);

        $payload = [
            'ui' => [
                'brand' => [
                    'footer_logo_disabled' => true,
                ],
            ],
        ];

        $response = $this->withHeaders(['If-Match' => $staleEtag])
            ->putJson('/settings/ui', $payload);

        $response->assertStatus(409);
        $response->assertJson([
            'ok' => false,
            'code' => 'PRECONDITION_FAILED',
        ]);

        $currentConfig = $service->currentConfig();
        $currentEtag = $service->etagFor($currentConfig);

        self::assertSame($currentEtag, $response->json('current_etag'));
        self::assertSame($currentEtag, $response->headers->get('ETag'));
        $cacheControl = $response->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('max-age=0', $cacheControl);
    }

    public function test_get_theme_manifest_allows_anonymous_when_auth_disabled(): void
    {
        Config::set('core.rbac.require_auth', false);

        $resp = $this->getJson('/settings/ui/themes');
        $resp->assertOk();
        $manifest = $resp->json();
        self::assertIsArray($manifest);
        self::assertSame('5.3.8', $manifest['version'] ?? null);
        self::assertIsArray($manifest['themes'] ?? null);
    }

    public function test_get_theme_manifest_requires_auth_when_flag_enabled(): void
    {
        Config::set('core.rbac.require_auth', true);

        $resp = $this->getJson('/settings/ui/themes');
        $resp->assertUnauthorized();
    }

    public function test_get_theme_manifest_returns_manifest_for_authenticated_user(): void
    {
        Config::set('core.rbac.require_auth', true);

        $this->actingAsThemeManager();

        $resp = $this->getJson('/settings/ui/themes');
        $resp->assertOk();
        $manifest = $resp->json();
        self::assertIsArray($manifest);
        self::assertSame('5.3.8', $manifest['version'] ?? null);
        self::assertIsArray($manifest['themes'] ?? null);
    }

    public function test_put_ui_settings_denied_without_policy_logs_audit(): void
    {
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.require_auth', true);
        Config::set('core.audit.enabled', true);
        $manager = User::factory()->create();
        $this->attachNamedRole($manager, 'Theme Manager');
        Sanctum::actingAs($manager);

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);
        $user = User::factory()->create();
        Sanctum::actingAs($user);
        $payload = [
            'ui' => [
                'theme' => [
                    'default' => 'cosmo',
                ],
            ],
        ];

        $response = $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload);

        $response->assertForbidden();

        /** @var AuditEvent|null $event */
        $event = AuditEvent::query()
            ->orderByDesc('occurred_at')
            ->first();

        self::assertNotNull($event);
        self::assertSame('rbac.deny.policy', $event->getAttribute('action'));
        /** @var array<string,mixed>|null $meta */
        $meta = $event->getAttribute('meta');
        self::assertIsArray($meta);
        self::assertSame('ui.theme.manage', $meta['policy'] ?? null);
    }

    public function test_put_ui_settings_records_ui_audit_events(): void
    {
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.require_auth', true);
        Config::set('core.audit.enabled', true);

        $admin = User::factory()->create();
        $this->attachNamedRole($admin, 'Admin');
        Sanctum::actingAs($admin);

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'theme' => [
                    'default' => 'darkly',
                    'allow_user_override' => false,
                    'overrides' => [
                        'shadow' => 'light',
                        'color.primary' => '#ff0000',
                    ],
                ],
                'nav' => [
                    'sidebar' => [
                        'default_order' => ['dashboard', 'metrics'],
                    ],
                ],
                'brand' => [
                    'title_text' => 'phpGRC — Nightly',
                    'footer_logo_disabled' => true,
                ],
            ],
        ];

        $response = $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload);
        $response->assertOk();

        $actions = AuditEvent::query()
            ->pluck('action')
            ->all();

        self::assertContains('ui.theme.updated', $actions);
        self::assertContains('ui.theme.overrides.updated', $actions);
        self::assertContains('ui.brand.updated', $actions);
        self::assertContains('ui.nav.sidebar.saved', $actions);
        self::assertNotContains('setting.modified', $actions);

        /** @var AuditEvent|null $themeEvent */
        $themeEvent = AuditEvent::query()
            ->where('action', 'ui.theme.updated')
            ->where('meta->setting_key', 'ui.theme.default')
            ->first();

        self::assertNotNull($themeEvent);
        /** @var array<string,mixed>|null $themeMeta */
        $themeMeta = $themeEvent->getAttribute('meta');
        self::assertIsArray($themeMeta);
        self::assertSame('ui.theme.default', $themeMeta['setting_key'] ?? null);
        self::assertSame('slate', $themeMeta['old_value'] ?? null);
        self::assertSame('darkly', $themeMeta['new_value'] ?? null);
    }

    private function attachNamedRole(User $user, string $name): void
    {
        $token = 'role_'.strtolower(preg_replace('/[^a-z0-9]+/i', '_', $name));

        /** @var Role $role */
        $role = Role::query()->firstOrCreate(
            ['id' => $token],
            ['name' => $name]
        );

        $user->roles()->syncWithoutDetaching([$role->getKey()]);
    }

    private function actingAsThemeManager(): User
    {
        $user = User::factory()->create();
        $this->attachNamedRole($user, 'Admin');
        Sanctum::actingAs($user);

        return $user;
    }

    public function test_brand_assets_default_to_primary_logo_when_missing(): void
    {
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.require_auth', true);

        $admin = User::factory()->create();
        $this->attachNamedRole($admin, 'Admin');
        Sanctum::actingAs($admin);

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'brand' => [
                    'primary_logo_asset_id' => 'asset-primary-123',
                    'footer_logo_disabled' => false,
                ],
            ],
        ];

        $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload)
            ->assertOk();

        $snapshot = $this->getJson('/settings/ui');
        $snapshot->assertOk();

        $brand = $snapshot->json('config.ui.brand');
        self::assertIsArray($brand);
        self::assertSame('asset-primary-123', $brand['primary_logo_asset_id'] ?? null);
        self::assertSame('asset-primary-123', $brand['favicon_asset_id'] ?? null);
        self::assertSame('asset-primary-123', $brand['footer_logo_asset_id'] ?? null);
        self::assertNull($brand['background_login_asset_id'] ?? null);
        self::assertNull($brand['background_main_asset_id'] ?? null);
    }

    public function test_footer_logo_stays_null_when_disabled(): void
    {
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.require_auth', true);

        $admin = User::factory()->create();
        $this->attachNamedRole($admin, 'Admin');
        Sanctum::actingAs($admin);

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'brand' => [
                    'primary_logo_asset_id' => 'asset-primary-456',
                    'footer_logo_disabled' => true,
                ],
            ],
        ];

        $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload)
            ->assertOk();

        $snapshot = $this->getJson('/settings/ui');
        $snapshot->assertOk();

        $brand = $snapshot->json('config.ui.brand');
        self::assertIsArray($brand);
        self::assertSame('asset-primary-456', $brand['primary_logo_asset_id'] ?? null);
        self::assertSame('asset-primary-456', $brand['favicon_asset_id'] ?? null);
        self::assertNull($brand['footer_logo_asset_id'] ?? null);
        self::assertTrue((bool) ($brand['footer_logo_disabled'] ?? false));
        self::assertNull($brand['background_login_asset_id'] ?? null);
        self::assertNull($brand['background_main_asset_id'] ?? null);
    }

    public function test_brand_background_fields_are_persisted(): void
    {
        Config::set('core.rbac.enabled', true);
        Config::set('core.rbac.mode', 'persist');
        Config::set('core.rbac.require_auth', true);

        $admin = User::factory()->create();
        $this->attachNamedRole($admin, 'Admin');
        Sanctum::actingAs($admin);

        $initial = $this->getJson('/settings/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'ui' => [
                'brand' => [
                    'background_login_asset_id' => 'asset-background-login',
                    'background_main_asset_id' => 'asset-background-main',
                ],
            ],
        ];

        $this->withHeaders(['If-Match' => $etag])
            ->putJson('/settings/ui', $payload)
            ->assertOk();

        $snapshot = $this->getJson('/settings/ui');
        $snapshot->assertOk();

        $brand = $snapshot->json('config.ui.brand');
        self::assertIsArray($brand);
        self::assertSame('asset-background-login', $brand['background_login_asset_id'] ?? null);
        self::assertSame('asset-background-main', $brand['background_main_asset_id'] ?? null);
    }
}
