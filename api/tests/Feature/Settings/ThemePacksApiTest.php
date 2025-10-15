<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\UiThemePack;
use App\Models\UiThemePackFile;
use App\Models\User;
use App\Services\Settings\UiSettingsService;
use App\Services\Settings\UserUiPreferencesService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;
use ZipArchive;

final class ThemePacksApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    protected function setUp(): void
    {
        parent::setUp();

        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);
    }

    protected function tearDown(): void
    {
        foreach ($this->tempFiles as $path) {
            if (is_string($path) && $path !== '' && file_exists($path)) {
                @unlink($path);
            }
        }
        $this->tempFiles = [];

        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.require_auth', false);

        parent::tearDown();
    }

    public function test_import_theme_pack_succeeds(): void
    {
        $this->actingAsThemeManager();

        $archive = $this->makeThemePackArchive([
            'manifest.json' => json_encode([
                'slug' => 'midnight',
                'name' => 'Midnight',
                'version' => '1.0.0',
                'author' => 'Theme Co',
                'assets' => [
                    'dark' => 'css/dark.css',
                ],
                'license' => [
                    'name' => 'MIT',
                    'file' => 'LICENSE.html',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'css/dark.css' => 'body { background:#101010; color:#f0f0f0; }',
            'LICENSE.html' => '<p>MIT License</p>',
        ]);

        $response = $this->postJson('/settings/ui/themes/import', [
            'file' => $archive,
        ]);

        $response->assertCreated();
        $response->assertJsonFragment(['ok' => true]);

        $payload = $response->json('pack');
        self::assertIsArray($payload);
        self::assertSame('pack:midnight', $payload['slug'] ?? null);
        self::assertSame(true, $payload['enabled'] ?? null);

        $this->assertDatabaseHas('ui_theme_packs', [
            'slug' => 'pack:midnight',
            'enabled' => true,
        ]);

        $this->assertDatabaseHas('ui_theme_pack_files', [
            'pack_slug' => 'pack:midnight',
            'path' => 'css/dark.css',
        ]);

        $manifest = $this->getJson('/settings/ui/themes');
        $manifest->assertOk();
        $packs = $manifest->json('packs');
        self::assertIsArray($packs);
        $slugs = array_map(static fn ($pack) => $pack['slug'] ?? null, $packs);
        self::assertContains('pack:midnight', $slugs);
    }

    public function test_import_theme_pack_rejects_external_css_urls(): void
    {
        $this->actingAsThemeManager();

        $archive = $this->makeThemePackArchive([
            'manifest.json' => json_encode([
                'slug' => 'hazard',
                'name' => 'Hazard',
                'assets' => [
                    'dark' => 'dark.css',
                ],
                'license' => [
                    'name' => 'MIT',
                    'file' => 'LICENSE.html',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'dark.css' => 'body { background:url("https://evil.example/logo.png"); }',
            'LICENSE.html' => '<p>MIT</p>',
        ]);

        $response = $this->postJson('/settings/ui/themes/import', [
            'file' => $archive,
        ]);

        $response->assertStatus(422);
        $response->assertJson([
            'ok' => false,
            'code' => 'THEME_IMPORT_INVALID',
        ]);

        self::assertSame(0, UiThemePack::query()->count());
        self::assertSame(0, UiThemePackFile::query()->count());
    }

    public function test_delete_theme_pack_resets_assignments(): void
    {
        $user = $this->actingAsThemeManager();

        $archive = $this->makeValidThemePack('solar');
        $this->postJson('/settings/ui/themes/import', ['file' => $archive])->assertCreated();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $settings->apply([
            'theme' => [
                'default' => 'pack:solar',
            ],
        ], $user->id);

        /** @var UserUiPreferencesService $prefs */
        $prefs = app(UserUiPreferencesService::class);
        $prefs->apply($user->id, [
            'theme' => 'pack:solar',
        ]);

        $response = $this->deleteJson('/settings/ui/themes/pack:solar');
        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'default_reset' => true,
        ]);

        $this->assertDatabaseMissing('ui_theme_packs', ['slug' => 'pack:solar']);
        $this->assertDatabaseCount('ui_theme_pack_files', 0);

        $updatedPrefs = $prefs->get($user->id);
        self::assertNull($updatedPrefs['theme']);

        $config = $settings->currentConfig();
        self::assertSame('slate', $config['theme']['default']);
    }

    public function test_update_theme_pack_disables_and_resets(): void
    {
        $user = $this->actingAsThemeManager();

        $archive = $this->makeValidThemePack('ocean');
        $this->postJson('/settings/ui/themes/import', ['file' => $archive])->assertCreated();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $settings->apply([
            'theme' => [
                'default' => 'pack:ocean',
            ],
        ], $user->id);

        /** @var UserUiPreferencesService $prefs */
        $prefs = app(UserUiPreferencesService::class);
        $prefs->apply($user->id, [
            'theme' => 'pack:ocean',
        ]);

        $response = $this->putJson('/settings/ui/themes/pack:ocean', [
            'enabled' => false,
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'default_reset' => true,
        ]);

        $this->assertDatabaseHas('ui_theme_packs', [
            'slug' => 'pack:ocean',
            'enabled' => false,
        ]);

        $config = $settings->currentConfig();
        self::assertSame('slate', $config['theme']['default']);
        $updatedPrefs = $prefs->get($user->id);
        self::assertNull($updatedPrefs['theme']);

        $manifest = $this->getJson('/settings/ui/themes');
        $manifest->assertOk();
        $packs = $manifest->json('packs');
        self::assertIsArray($packs);
        $slugs = array_map(static fn ($pack) => $pack['slug'] ?? null, $packs);
        self::assertNotContains('pack:ocean', $slugs);
    }

    public function test_theme_auditor_cannot_mutate_theme_packs(): void
    {
        $this->actingAsThemeAuditor();

        $this->postJson('/settings/ui/themes/import', [
            'file' => $this->makeValidThemePack('audit'),
        ])->assertStatus(403);

        $manager = $this->actingAsThemeManager();
        $this->postJson('/settings/ui/themes/import', [
            'file' => $this->makeValidThemePack('manager-pack'),
        ])->assertCreated();

        $this->actingAsThemeAuditor();
        $this->putJson('/settings/ui/themes/pack:manager-pack', [
            'enabled' => false,
        ])->assertStatus(403);

        $this->deleteJson('/settings/ui/themes/pack:manager-pack')
            ->assertStatus(403);

        Sanctum::actingAs($manager);
        $this->deleteJson('/settings/ui/themes/pack:manager-pack')
            ->assertStatus(200);
    }

    /**
     * @param  array<string,string>  $entries
     */
    private function makeThemePackArchive(array $entries): UploadedFile
    {
        $tmp = tempnam(sys_get_temp_dir(), 'theme_pack_');
        if ($tmp === false) {
            self::fail('Unable to create temporary archive path.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            self::fail('Unable to open zip archive for writing.');
        }

        foreach ($entries as $path => $contents) {
            if (! $zip->addFromString($path, $contents)) {
                self::fail(sprintf('Failed to add entry to archive: %s', $path));
            }
        }

        $zip->close();

        $this->tempFiles[] = $tmp;

        return new UploadedFile($tmp, 'theme-pack.zip', 'application/zip', UPLOAD_ERR_OK, true);
    }

    private function makeValidThemePack(string $slug): UploadedFile
    {
        $slug = Str::of($slug)->lower()->slug('-');

        return $this->makeThemePackArchive([
            'manifest.json' => json_encode([
                'slug' => (string) $slug,
                'name' => Str::title($slug),
                'assets' => [
                    'dark' => 'styles/dark.css',
                ],
                'license' => [
                    'name' => 'Custom',
                    'file' => 'LICENSE.html',
                ],
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
            'styles/dark.css' => 'body { background:#000000; color:#ffffff; }',
            'LICENSE.html' => '<p>License</p>',
        ]);
    }

    private function actingAsThemeManager(): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'role_theme_manager', 'Theme Manager');
        Sanctum::actingAs($user);

        return $user;
    }

    private function actingAsThemeAuditor(): User
    {
        $user = User::factory()->create();
        $this->assignRole($user, 'role_theme_auditor', 'Theme Auditor');
        Sanctum::actingAs($user);

        return $user;
    }

    private function assignRole(User $user, string $roleId, string $roleName): void
    {
        Role::query()->updateOrCreate(['id' => $roleId], ['name' => $roleName]);
        $user->roles()->sync([$roleId]);
    }
}
