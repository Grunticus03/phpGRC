<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\Role;
use App\Models\UiSetting;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class DesignerThemesApiTest extends TestCase
{
    use RefreshDatabase;

    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir().'/phpgrc-designer-'.uniqid();
        (new Filesystem)->ensureDirectoryExists($this->storagePath, 0755);

        config()->set('ui.defaults.theme.designer.filesystem_path', $this->storagePath);
        config()->set('ui.defaults.theme.designer.storage', 'filesystem');
        config()->set('core.rbac.enabled', true);
        config()->set('core.rbac.mode', 'persist');
        config()->set('core.rbac.require_auth', true);
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storagePath);
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.require_auth', false);
        parent::tearDown();
    }

    public function test_store_theme_persists_file_and_updates_manifest(): void
    {
        $this->actingAsThemeManager();

        $response = $this->postJson('/settings/ui/designer/themes', [
            'name' => 'My Custom Theme',
            'variables' => [
                '--td-example-background' => '#101010',
                '--td-example-text' => '#ffffff',
            ],
        ]);

        $response->assertCreated();
        $response->assertJson([
            'ok' => true,
        ]);

        $pack = $response->json('pack');
        self::assertIsArray($pack);
        self::assertSame('my-custom-theme', $pack['slug'] ?? null);
        self::assertSame('My Custom Theme', $pack['name'] ?? null);
        self::assertSame('custom', $pack['source'] ?? null);

        $expectedPath = $this->storagePath.'/my-custom-theme.json';
        self::assertFileExists($expectedPath);
        $stored = json_decode((string) file_get_contents($expectedPath), true);
        self::assertIsArray($stored);
        self::assertSame(1, $stored['version'] ?? null);

        $manifest = $this->getJson('/settings/ui/themes');
        $manifest->assertOk();
        $packs = $manifest->json('packs');
        self::assertIsArray($packs);
        $slugs = array_map(static fn ($item) => $item['slug'] ?? null, $packs);
        self::assertContains('my-custom-theme', $slugs);
    }

    public function test_export_theme_downloads_payload(): void
    {
        $this->actingAsThemeManager();

        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Export Theme',
            'variables' => [
                '--td-export-background' => '#202020',
            ],
        ])->assertCreated();

        $response = $this->get('/settings/ui/designer/themes/export-theme/export');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $disposition = $response->headers->get('Content-Disposition');
        self::assertIsString($disposition);
        self::assertStringContainsString('export-theme.theme.json', $disposition);

        $payload = json_decode((string) $response->getContent(), true);

        self::assertIsArray($payload);
        self::assertSame('export-theme', $payload['slug'] ?? null);
        self::assertSame('Export Theme', $payload['name'] ?? null);
        self::assertSame(1, $payload['version'] ?? null);
        self::assertIsArray($payload['variables'] ?? null);
        self::assertSame('#202020', $payload['variables']['--td-export-background'] ?? null);
        self::assertIsArray($payload['supports'] ?? null);
        self::assertSame(['light', 'dark'], $payload['supports']['mode'] ?? null);
        self::assertIsString($payload['exported_at'] ?? null);
    }

    public function test_import_theme_creates_theme_from_export(): void
    {
        $this->actingAsThemeManager();

        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Import Source Theme',
            'variables' => [
                '--td-import-color' => '#eeeeee',
            ],
        ])->assertCreated();

        $export = $this->get('/settings/ui/designer/themes/import-source-theme/export');
        $export->assertOk();

        $file = UploadedFile::fake()->createWithContent('import-source.theme.json', (string) $export->getContent());

        $response = $this->post('/settings/ui/designer/themes/import', [
            'slug' => 'imported-theme',
            'file' => $file,
        ]);

        $response->assertCreated();
        $response->assertJson([
            'ok' => true,
            'pack' => [
                'slug' => 'imported-theme',
                'name' => 'Import Source Theme',
            ],
        ]);

        $pack = $response->json('pack');
        self::assertIsArray($pack);
        self::assertSame('#eeeeee', $pack['variables']['--td-import-color'] ?? null);
        self::assertSame(['light', 'dark'], $pack['supports']['mode'] ?? null);

        $expectedPath = $this->storagePath.'/imported-theme.json';
        self::assertFileExists($expectedPath);

        $manifest = $this->getJson('/settings/ui/themes');
        $manifest->assertOk();
        $packs = $manifest->json('packs');
        self::assertIsArray($packs);
        $slugs = array_map(static fn ($item) => $item['slug'] ?? null, $packs);
        self::assertContains('imported-theme', $slugs);
    }

    public function test_store_theme_blocks_conflicts_with_builtin_slug(): void
    {
        $this->actingAsThemeManager();

        $response = $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Slate Override',
            'slug' => 'slate',
            'variables' => [
                '--td-over' => '#123123',
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'ok' => false,
            'code' => 'DESIGNER_THEME_CONFLICT',
        ]);

        self::assertFileDoesNotExist($this->storagePath.'/slate.json');
    }

    public function test_store_theme_respects_storage_setting(): void
    {
        $this->actingAsThemeManager();

        UiSetting::query()->create([
            'key' => 'ui.theme.designer.storage',
            'value' => '"browser"',
            'type' => 'json',
        ]);

        $response = $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Browser Only',
            'variables' => [
                '--td-browser' => '#000000',
            ],
        ]);

        $response->assertStatus(409);
        $response->assertJson([
            'ok' => false,
            'code' => 'DESIGNER_STORAGE_DISABLED',
        ]);
    }

    public function test_delete_theme_removes_file(): void
    {
        $this->actingAsThemeManager();

        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Delete Me',
            'variables' => [
                '--td-delete' => '#999999',
            ],
        ])->assertCreated();

        $manifestBefore = $this->getJson('/settings/ui/themes');
        $manifestBefore->assertOk();
        $packs = $manifestBefore->json('packs');
        self::assertIsArray($packs);
        self::assertContains('delete-me', array_map(static fn ($item) => $item['slug'] ?? null, $packs));

        $response = $this->deleteJson('/settings/ui/designer/themes/delete-me');
        $response->assertOk();
        $response->assertJson(['ok' => true]);

        self::assertFileDoesNotExist($this->storagePath.'/delete-me.json');

        $manifestAfter = $this->getJson('/settings/ui/themes');
        $manifestAfter->assertOk();
        $packsAfter = $manifestAfter->json('packs');
        self::assertIsArray($packsAfter);
        self::assertNotContains('delete-me', array_map(static fn ($item) => $item['slug'] ?? null, $packsAfter));
    }

    public function test_index_returns_storage_config_and_themes(): void
    {
        $manager = $this->actingAsThemeManager();

        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Index Theme',
            'variables' => [
                '--td-index' => '#abcdef',
            ],
        ])->assertCreated();

        $this->actingAsThemeAuditor();

        $response = $this->getJson('/settings/ui/designer/themes');
        $response->assertOk();
        $response->assertJson([
            'ok' => true,
        ]);

        $storage = $response->json('storage');
        self::assertIsArray($storage);
        self::assertSame('filesystem', $storage['storage'] ?? null);
        self::assertSame($this->storagePath, $storage['filesystem_path'] ?? null);

        $themes = $response->json('themes');
        self::assertIsArray($themes);
        $slugs = array_map(static fn ($item) => $item['slug'] ?? null, $themes);
        self::assertContains('index-theme', $slugs);
    }

    public function test_theme_auditor_cannot_create_or_delete_themes(): void
    {
        $this->actingAsThemeAuditor();

        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Denied Theme',
            'variables' => [
                '--td-denied' => '#000000',
            ],
        ])->assertStatus(403);

        $manager = $this->actingAsThemeManager();
        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Created By Manager',
            'variables' => [
                '--td-manager' => '#333333',
            ],
        ])->assertCreated();

        $this->actingAsThemeAuditor();
        $this->deleteJson('/settings/ui/designer/themes/created-by-manager')
            ->assertStatus(403);

        Sanctum::actingAs($manager);
        $this->deleteJson('/settings/ui/designer/themes/created-by-manager')
            ->assertStatus(200);
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
