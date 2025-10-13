<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\BrandAsset;
use App\Models\BrandProfile;
use App\Models\User;
use App\Services\Settings\BrandAssetStorageService;
use App\Services\Settings\UiSettingsService;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Config;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BrandAssetsApiTest extends TestCase
{
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
        parent::tearDown();
    }

    public function test_upload_and_delete_brand_asset(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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

        $this->assertDatabaseHas('brand_assets', [
            'id' => $assetId,
            'kind' => 'primary_logo',
            'profile_id' => $profile->getAttribute('id'),
        ]);

        /** @var BrandAsset|null $stored */
        $stored = BrandAsset::query()->find($assetId);
        self::assertInstanceOf(BrandAsset::class, $stored);

        /** @var BrandAssetStorageService $storage */
        $storage = app(BrandAssetStorageService::class);
        $assetPath = $storage->assetPath($stored);
        self::assertIsString($assetPath);
        self::assertFileExists($assetPath);

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $settings->apply([
            'brand' => [
                'profile_id' => $profile->getAttribute('id'),
                'primary_logo_asset_id' => $assetId,
            ],
        ], $user->id);

        $delete = $this->deleteJson('/settings/ui/brand-assets/'.$assetId);
        $delete->assertOk();

        $this->assertDatabaseMissing('brand_assets', ['id' => $assetId]);
        self::assertFileDoesNotExist($assetPath);

        $config = $settings->currentConfig();
        self::assertNull($config['brand']['primary_logo_asset_id']);
    }

    public function test_upload_rejects_unsupported_mime(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $profile = $this->createEditableProfile();

        $file = UploadedFile::fake()->create('logo.png', 10, 'image/png');

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'favicon',
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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $profile = $this->createEditableProfile();

        $file = $this->makeOversizedUploadedFile();

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'secondary_logo',
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

    public function test_upload_fails_when_bytes_cannot_be_read(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $profile = $this->createEditableProfile();

        $file = $this->makeUnreadableUploadedFile();

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'header_logo',
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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->deleteJson('/settings/ui/brand-assets/nonexistent');

        $response->assertStatus(404);
        $response->assertJson([
            'ok' => false,
            'code' => 'NOT_FOUND',
        ]);
    }

    public function test_download_brand_asset_returns_bytes(): void
    {
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
}
