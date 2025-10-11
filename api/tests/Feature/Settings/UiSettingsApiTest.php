<?php

declare(strict_types=1);

namespace Tests\Feature\Settings;

use App\Models\User;
use App\Services\Settings\UiSettingsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UiSettingsApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_get_ui_settings_returns_defaults_with_etag(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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

    public function test_put_ui_settings_requires_if_match(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
        $updated->assertJson([
            'ok' => true,
            'config' => [
                'ui' => [
                    'theme' => [
                        'default' => 'cosmo',
                        'allow_user_override' => false,
                        'force_global' => true,
                    ],
                    'brand' => [
                        'title_text' => 'Custom Dashboard',
                        'footer_logo_disabled' => true,
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
    }

    public function test_get_ui_settings_honors_if_none_match_with_multiple_values(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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
    }

    public function test_put_ui_settings_rejects_stale_etag_and_returns_current(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

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

    public function test_get_theme_manifest_requires_authentication(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $resp = $this->getJson('/settings/ui/themes');
        $resp->assertOk();
        $manifest = $resp->json();
        self::assertIsArray($manifest);
        self::assertSame('5.3.3', $manifest['version'] ?? null);
        self::assertIsArray($manifest['themes'] ?? null);
    }
}
