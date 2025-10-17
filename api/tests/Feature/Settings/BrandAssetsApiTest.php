<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\BrandAsset;
use App\Models\BrandProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\Settings\BrandAssetStorageService;
use App\Services\Settings\UiSettingsService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BrandAssetsApiTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempFiles = [];

    private string $assetRoot;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assetRoot = storage_path('app/test-brand-assets');
        Config::set('ui.defaults.brand.assets.filesystem_path', $this->assetRoot);
        $filesystem = new Filesystem;
        if ($filesystem->isDirectory($this->assetRoot)) {
            $filesystem->deleteDirectory($this->assetRoot);
        }

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

        $filesystem = new Filesystem;
        if (isset($this->assetRoot) && $filesystem->isDirectory($this->assetRoot)) {
            $filesystem->deleteDirectory($this->assetRoot);
        }

        $this->tempFiles = [];
        config()->set('core.rbac.mode', 'stub');
        config()->set('core.rbac.require_auth', false);
        parent::tearDown();
    }

    public function test_upload_and_delete_brand_asset(): void
    {
        $user = $this->actingAsThemeManager();

        $profile = $this->createEditableProfile(true);

        $file = UploadedFile::fake()->image('logo.png', 128, 128);

        $upload = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'profile_id' => $profile->getAttribute('id'),
            'file' => $file,
        ]);

        $upload->assertCreated();
        $data = $upload->json();
        self::assertIsArray($data);
        $assetId = $data['asset']['id'] ?? null;
        self::assertIsString($assetId);
        self::assertMatchesRegularExpression('/logo--[a-z0-9]+\\.webp/', $data['asset']['name'] ?? '');
        self::assertSame('logo.webp', $data['asset']['display_name'] ?? null);
        self::assertSame('image/webp', $data['asset']['mime'] ?? null);

        /** @var array<string,mixed>|null $variants */
        $variants = $data['variants'] ?? null;
        self::assertIsArray($variants);
        self::assertArrayHasKey('primary_logo', $variants);
        self::assertArrayHasKey('background_image', $variants);
        self::assertSame(6, count($variants));
        foreach ($variants as $kind => $variant) {
            self::assertIsArray($variant);
            if ($kind === 'favicon') {
                self::assertSame('logo.ico', $variant['display_name'] ?? null);
                self::assertSame('image/x-icon', $variant['mime'] ?? null);
            } elseif ($kind === 'background_image') {
                self::assertSame('logo.webp', $variant['display_name'] ?? null);
                self::assertSame('image/webp', $variant['mime'] ?? null);
            } else {
                self::assertSame('logo.webp', $variant['display_name'] ?? null);
                self::assertSame('image/webp', $variant['mime'] ?? null);
            }
        }

        $this->assertDatabaseHas('brand_assets', [
            'id' => $assetId,
            'kind' => 'primary_logo',
            'profile_id' => $profile->getAttribute('id'),
        ]);

        $storedAssets = BrandAsset::query()->get();
        self::assertCount(6, $storedAssets);

        /** @var BrandAssetStorageService $storage */
        $storage = app(BrandAssetStorageService::class);

        $byKind = [];
        $paths = [];
        foreach ($storedAssets as $stored) {
            /** @var string $kind */
            $kind = $stored->getAttribute('kind');
            $byKind[$kind] = $stored;
            if ($kind === 'favicon') {
                self::assertSame('image/x-icon', $stored->getAttribute('mime'));
            } else {
                self::assertSame('image/webp', $stored->getAttribute('mime'));
            }
            $path = $storage->assetPath($stored);
            self::assertIsString($path);
            self::assertFileExists($path);
            $paths[] = $path;
        }

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $idsByKind = [];
        foreach ($storedAssets as $stored) {
            /** @var string $kind */
            $kind = $stored->getAttribute('kind');
            /** @var string $idValue */
            $idValue = $stored->getAttribute('id');
            $idsByKind[$kind] = $idValue;
        }

        $settings->apply([
            'brand' => [
                'profile_id' => $profile->getAttribute('id'),
                'primary_logo_asset_id' => $idsByKind['primary_logo'] ?? null,
                'secondary_logo_asset_id' => $idsByKind['secondary_logo'] ?? null,
                'header_logo_asset_id' => $idsByKind['header_logo'] ?? null,
                'footer_logo_asset_id' => $idsByKind['footer_logo'] ?? null,
                'favicon_asset_id' => $idsByKind['favicon'] ?? null,
            ],
        ], $user->id);

        $faviconResponse = $this->get('/favicon.ico');
        $faviconResponse->assertOk();
        $faviconResponse->assertHeader('Content-Type', 'image/x-icon');

        $delete = $this->deleteJson('/settings/ui/brand-assets/'.$assetId);
        $delete->assertOk();

        self::assertSame(0, BrandAsset::query()->count());
        foreach ($paths as $path) {
            self::assertFileDoesNotExist($path);
        }

        $config = $settings->currentConfig();
        self::assertNull($config['brand']['primary_logo_asset_id']);
        self::assertNull($config['brand']['secondary_logo_asset_id']);
        self::assertNull($config['brand']['header_logo_asset_id']);
        self::assertNull($config['brand']['footer_logo_asset_id']);
        self::assertNull($config['brand']['favicon_asset_id']);
        self::assertNull($config['brand']['background_login_asset_id']);
        self::assertNull($config['brand']['background_main_asset_id']);
    }

    public function test_uploads_from_all_profiles_are_listed_globally(): void
    {
        $this->actingAsThemeManager();

        $profileA = $this->createEditableProfile(true);
        $profileB = $this->createEditableProfile();

        $fileA = UploadedFile::fake()->image('first.png', 128, 128);
        $fileB = UploadedFile::fake()->image('second.png', 128, 128);

        $uploadA = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'profile_id' => $profileA->getAttribute('id'),
            'file' => $fileA,
        ]);
        $uploadA->assertCreated();

        $uploadB = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'profile_id' => $profileB->getAttribute('id'),
            'file' => $fileB,
        ]);
        $uploadB->assertCreated();

        $response = $this->getJson('/settings/ui/brand-assets?profile_id='.$profileB->getAttribute('id'));
        $response->assertOk();

        $payload = $response->json();
        self::assertIsArray($payload);
        /** @var array<int,array<string,mixed>>|null $assets */
        $assets = $payload['assets'] ?? null;
        self::assertIsArray($assets);

        $primaryAssets = array_values(array_filter($assets, static fn (array $asset): bool => ($asset['kind'] ?? null) === 'primary_logo'));
        self::assertCount(2, $primaryAssets);

        self::assertSame(12, BrandAsset::query()->count());
    }

    public function test_upload_rejects_unsupported_mime(): void
    {
        $this->actingAsThemeManager();

        $profile = $this->createEditableProfile();

        $file = UploadedFile::fake()->create('logo.png', 10, 'image/png');

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'profile_id' => $profile->getAttribute('id'),
            'file' => $file,
        ]);

        $response->assertStatus(415);
        $response->assertJson([
            'ok' => false,
            'code' => 'UNSUPPORTED_MEDIA_TYPE',
        ]);

        self::assertSame(0, BrandAsset::query()->count());
    }

    public function test_upload_rejects_files_over_five_megabytes(): void
    {
        $this->actingAsThemeManager();

        $profile = $this->createEditableProfile();

        $file = $this->makeOversizedUploadedFile();

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'profile_id' => $profile->getAttribute('id'),
            'file' => $file,
        ]);

        $response->assertStatus(413);
        $response->assertJson([
            'ok' => false,
            'code' => 'PAYLOAD_TOO_LARGE',
        ]);

        self::assertSame(0, BrandAsset::query()->count());
    }

    public function test_upload_background_image_creates_asset_and_updates_config(): void
    {
        $user = $this->actingAsThemeManager();

        $profile = $this->createEditableProfile(true);

        $file = UploadedFile::fake()->image('background.jpg', 2560, 1440);

        $upload = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'background_image',
            'profile_id' => $profile->getAttribute('id'),
            'file' => $file,
        ]);

        $upload->assertCreated();
        $payload = $upload->json();
        self::assertIsArray($payload);
        $assetData = $payload['asset'] ?? null;
        self::assertIsArray($assetData);
        self::assertSame('background_image', $assetData['kind'] ?? null);
        self::assertSame('image/webp', $assetData['mime'] ?? null);
        self::assertMatchesRegularExpression('/background-image\\.webp$/', $assetData['name'] ?? '');

        /** @var array<string,mixed>|null $variants */
        $variants = $payload['variants'] ?? null;
        self::assertIsArray($variants);
        self::assertArrayHasKey('background_image', $variants);

        self::assertSame(1, BrandAsset::query()->count());

        /** @var BrandAsset $asset */
        $asset = BrandAsset::query()->firstOrFail();
        self::assertSame('background_image', $asset->getAttribute('kind'));
        self::assertSame('image/webp', $asset->getAttribute('mime'));

        /** @var BrandAssetStorageService $storage */
        $storage = app(BrandAssetStorageService::class);
        $path = $storage->assetPath($asset);
        self::assertIsString($path);
        self::assertFileExists($path);
        self::assertStringEndsWith('.webp', $path);

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        /** @var string $assetId */
        $assetId = $asset->getAttribute('id');

        $settings->apply([
            'brand' => [
                'profile_id' => $profile->getAttribute('id'),
                'background_login_asset_id' => $assetId,
                'background_main_asset_id' => $assetId,
            ],
        ], $user->id);

        $config = $settings->currentConfig();
        self::assertSame($assetId, $config['brand']['background_login_asset_id']);
        self::assertSame($assetId, $config['brand']['background_main_asset_id']);

        $delete = $this->deleteJson('/settings/ui/brand-assets/'.$assetId);
        $delete->assertOk();

        self::assertSame(0, BrandAsset::query()->count());
        self::assertFileDoesNotExist($path);

        $configAfter = $settings->currentConfig();
        self::assertNull($configAfter['brand']['background_login_asset_id']);
        self::assertNull($configAfter['brand']['background_main_asset_id']);
    }

    public function test_upload_fails_when_bytes_cannot_be_read(): void
    {
        $this->actingAsThemeManager();

        $profile = $this->createEditableProfile();

        $file = $this->makeUnreadableUploadedFile();

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'profile_id' => $profile->getAttribute('id'),
            'file' => $file,
        ]);

        $response->assertStatus(500);
        $response->assertJson([
            'ok' => false,
            'code' => 'UPLOAD_FAILED',
        ]);

        self::assertSame(0, BrandAsset::query()->count());
    }

    public function test_delete_nonexistent_brand_asset_returns_not_found(): void
    {
        $this->actingAsThemeManager();

        $response = $this->deleteJson('/settings/ui/brand-assets/nonexistent');

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'code' => 'NOT_FOUND',
        ]);
    }

    public function test_download_brand_asset_returns_bytes(): void
    {
        $this->actingAsThemeAuditor();

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $activeProfile = $settings->activeBrandProfile();

        $asset = BrandAsset::query()->create([
            'profile_id' => $activeProfile->getAttribute('id'),
            'kind' => 'primary_logo',
            'name' => 'logo.png',
            'mime' => 'image/png',
            'size_bytes' => 12,
            'sha256' => hash('sha256', 'sample-bytes'),
            'bytes' => 'sample-bytes',
        ]);

        $response = $this->get('/settings/ui/brand-assets/'.$asset->getAttribute('id').'/download');

        $response->assertOk();
        $response->assertHeader('Content-Type', 'image/png');

        $cacheControl = $response->headers->get('Cache-Control');
        self::assertIsString($cacheControl);
        $directives = array_map('trim', explode(',', $cacheControl));
        self::assertContains('public', $directives);
        self::assertContains('max-age=3600', $directives);
        self::assertContains('immutable', $directives);

        self::assertSame('sample-bytes', $response->getContent());
    }

    private function makeOversizedUploadedFile(): UploadedFile
    {
        $seed = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAtMB9o9Re+8AAAAASUVORK5CYII=');
        self::assertIsString($seed);
        $multiplier = (int) ceil(((5 * 1024 * 1024) + 4096) / strlen($seed));
        $bytes = str_repeat($seed, $multiplier);

        $path = $this->storeTempUpload($bytes);

        return new class($path) extends UploadedFile
        {
            public function __construct(string $path)
            {
                parent::__construct($path, 'huge.png', 'image/png', UPLOAD_ERR_OK, true);
            }

            public function getSize(): ?int
            {
                return 2048;
            }
        };
    }

    private function makeUnreadableUploadedFile(): UploadedFile
    {
        $seed = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8Xw8AAtMB9o9Re+8AAAAASUVORK5CYII=');
        self::assertIsString($seed);
        $bytes = str_repeat($seed, 4);
        $path = $this->storeTempUpload($bytes);

        return new class($path) extends UploadedFile
        {
            public function __construct(string $path)
            {
                parent::__construct($path, 'broken.png', 'image/png', UPLOAD_ERR_OK, true);
            }

            public function getSize(): ?int
            {
                return 1024;
            }

            public function get(): false|string
            {
                return false;
            }
        };
    }

    private function storeTempUpload(string $bytes): string
    {
        $path = tempnam(sys_get_temp_dir(), 'brand_asset_');
        if ($path === false) {
            self::fail('Unable to create temporary upload path.');
        }

        $written = file_put_contents($path, $bytes);
        if ($written === false) {
            self::fail('Unable to write temporary upload contents.');
        }

        $this->tempFiles[] = $path;

        return $path;
    }

    private function createEditableProfile(bool $activate = false): BrandProfile
    {
        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $profile = $settings->createBrandProfile('Test Profile '.uniqid('', true));

        if ($activate) {
            $settings->activateBrandProfile($profile);
            $profile = $profile->refresh();
        }

        return $profile;
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
