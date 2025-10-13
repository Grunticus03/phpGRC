<?php

declare(strict_types=1);

namespace Tests\Feature\User;

use App\Models\User;
use App\Models\UserUiPreference;
use App\Services\Settings\UserUiPreferencesService;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

final class UiPreferencesApiTest extends TestCase
{
    public function test_get_preferences_returns_defaults_with_etag(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->getJson('/me/prefs/ui');
        $response->assertOk();

        $body = $response->json();
        self::assertIsArray($body);
        self::assertTrue($body['ok']);
        self::assertIsArray($body['prefs']);
        self::assertArrayHasKey('theme', $body['prefs']);

        $etag = $response->headers->get('ETag');
        self::assertNotNull($etag);
        self::assertSame($etag, $body['etag']);
    }

    public function test_put_preferences_requires_if_match(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $response = $this->putJson('/me/prefs/ui', [
            'theme' => 'flatly',
        ]);

        $response->assertStatus(409);
    }

    public function test_put_preferences_updates_record(): void
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        $initial = $this->getJson('/me/prefs/ui');
        $initial->assertOk();
        $etag = $initial->headers->get('ETag');
        self::assertNotNull($etag);

        $payload = [
            'theme' => 'flatly',
            'mode' => 'light',
            'overrides' => [
                'color.primary' => '#123456',
                'motion' => 'limited',
            ],
            'sidebar' => [
                'collapsed' => true,
                'width' => 320,
                'order' => ['risks', 'audits'],
            ],
        ];

        $update = $this->withHeaders(['If-Match' => $etag])
            ->putJson('/me/prefs/ui', $payload);
        $update->assertOk();
        $update->assertJson([
            'ok' => true,
            'prefs' => [
                'theme' => 'flatly',
                'mode' => 'light',
                'sidebar' => [
                    'collapsed' => true,
                ],
            ],
        ]);

        /** @var UserUiPreferencesService $service */
        $service = app(UserUiPreferencesService::class);
        $prefs = $service->get($user->id);

        self::assertSame('flatly', $prefs['theme']);
        self::assertSame('light', $prefs['mode']);
        self::assertSame('#123456', $prefs['overrides']['color.primary']);
        self::assertTrue($prefs['sidebar']['collapsed']);
        self::assertSame(320, $prefs['sidebar']['width']);
        self::assertSame(['risks', 'audits'], $prefs['sidebar']['order']);
    }

    public function test_get_preferences_returns_defaults_when_auth_disabled(): void
    {
        config()->set('core.rbac.require_auth', false);

        $response = $this->getJson('/me/prefs/ui');

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
        ]);
        $response->assertHeader('ETag');
    }

    public function test_get_preferences_requires_auth_when_flag_enabled(): void
    {
        config()->set('core.rbac.require_auth', true);

        $response = $this->getJson('/me/prefs/ui');

        $response->assertUnauthorized();
    }

    public function test_put_preferences_allows_guest_when_auth_disabled(): void
    {
        config()->set('core.rbac.require_auth', false);

        $response = $this->putJson('/me/prefs/ui', [
            'sidebar' => [
                'collapsed' => true,
                'width' => 260,
            ],
        ]);

        $response->assertOk();
        $response->assertJson([
            'ok' => true,
            'prefs' => [
                'sidebar' => [
                    'collapsed' => true,
                ],
            ],
        ]);
        $response->assertHeader('ETag');

        self::assertSame(0, UserUiPreference::query()->count());
    }
}
