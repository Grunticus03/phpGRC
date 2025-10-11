<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\BrandAsset;
use App\Models\User;
use App\Services\Settings\UiSettingsService;
use Illuminate\Http\UploadedFile;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class BrandAssetsApiTest extends TestCase
{
    public function test_upload_and_delete_brand_asset(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->image('logo.png', 128, 128);

        $upload = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'primary_logo',
            'file' => $file,
        ]);

        $upload->assertCreated();
        $data = $upload->json();
        self::assertIsArray($data);
        $assetId = $data['asset']['id'] ?? null;
        self::assertIsString($assetId);

        $this->assertDatabaseHas('brand_assets', ['id' => $assetId, 'kind' => 'primary_logo']);

        /** @var UiSettingsService $settings */
        $settings = app(UiSettingsService::class);
        $settings->apply([
            'brand' => [
                'primary_logo_asset_id' => $assetId,
            ],
        ], $user->id);

        $delete = $this->deleteJson('/settings/ui/brand-assets/'.$assetId);
        $delete->assertOk();

        $this->assertDatabaseMissing('brand_assets', ['id' => $assetId]);

        $config = $settings->currentConfig();
        self::assertNull($config['brand']['primary_logo_asset_id']);
    }

    public function test_upload_rejects_unsupported_mime(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $file = UploadedFile::fake()->create('logo.png', 10, 'image/png');

        $response = $this->postJson('/settings/ui/brand-assets', [
            'kind' => 'favicon',
            'file' => $file,
        ]);

        $response->assertStatus(415);
        $response->assertJson([
            'ok' => false,
            'code' => 'UNSUPPORTED_MEDIA_TYPE',
        ]);

        self::assertSame(0, BrandAsset::query()->count());
    }
}
