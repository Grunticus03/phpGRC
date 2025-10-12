<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\UiSetting;
use App\Models\User;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
    }

    protected function tearDown(): void
    {
        (new Filesystem)->deleteDirectory($this->storagePath);
        parent::tearDown();
    }

    public function test_store_theme_persists_file_and_updates_manifest(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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

        $manifest = $this->getJson('/settings/ui/themes');
        $manifest->assertOk();
        $packs = $manifest->json('packs');
        self::assertIsArray($packs);
        $slugs = array_map(static fn ($item) => $item['slug'] ?? null, $packs);
        self::assertContains('my-custom-theme', $slugs);
    }

    public function test_store_theme_blocks_conflicts_with_builtin_slug(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $this->postJson('/settings/ui/designer/themes', [
            'name' => 'Index Theme',
            'variables' => [
                '--td-index' => '#abcdef',
            ],
        ])->assertCreated();

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
}
