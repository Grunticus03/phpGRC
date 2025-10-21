<?php

declare(strict_types=1);

namespace Tests\Feature\Auth;

use App\Models\IdpProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

final class AuthOptionsTest extends TestCase
{
    use RefreshDatabase;

    public function test_returns_local_only_when_no_idps(): void
    {
        $this->getJson('/auth/options')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'local_only')
            ->assertJsonPath('local.enabled', true)
            ->assertJsonPath('idp.providers', []);
    }

    public function test_returns_none_mode_when_everything_disabled(): void
    {
        config()->set('core.auth.local.enabled', false);

        $this->getJson('/auth/options')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'none')
            ->assertJsonPath('local.enabled', false)
            ->assertJsonPath('idp.providers', []);
    }

    public function test_auto_redirect_when_single_idp_and_local_disabled(): void
    {
        config()->set('core.auth.local.enabled', false);

        /** @var IdpProvider $provider */
        $provider = IdpProvider::query()->create([
            'key' => 'primary-oidc',
            'name' => 'Primary OIDC',
            'driver' => 'oidc',
            'enabled' => true,
            'evaluation_order' => 1,
            'config' => [
                'issuer' => 'https://idp.example.test',
                'client_id' => 'client-123',
                'client_secret' => 'secret-123',
            ],
            'meta' => null,
        ]);

        $response = $this->getJson('/auth/options')
            ->assertStatus(200)
            ->assertJsonPath('ok', true)
            ->assertJsonPath('mode', 'idp_only')
            ->assertJsonPath('local.enabled', false)
            ->assertJsonPath('idp.providers.0.key', 'primary-oidc')
            ->assertJsonPath('idp.providers.0.links.authorize', '/auth/oidc/authorize?provider='.$provider->id)
            ->assertJsonPath('auto_redirect.key', 'primary-oidc')
            ->assertJsonPath('auto_redirect.driver', 'oidc')
            ->assertJsonPath('auto_redirect.authorize', '/auth/oidc/authorize?provider='.$provider->id);

        /** @var array{auto_redirect:array{provider:string}} $payload */
        $payload = $response->json();
        self::assertSame($provider->id, $payload['auto_redirect']['provider']);
    }

    public function test_mixed_mode_when_local_and_idp_enabled(): void
    {
        $provider = IdpProvider::query()->create([
            'key' => 'backup-entra',
            'name' => 'Entra Backup',
            'driver' => 'entra',
            'enabled' => true,
            'evaluation_order' => 5,
            'config' => [
                'tenant_id' => '12345678-90ab-cdef-1234-567890abcdef',
                'client_id' => 'client-entra',
                'client_secret' => 'secret-entra',
                'issuer' => 'https://login.microsoftonline.com/12345678-90ab-cdef-1234-567890abcdef/v2.0',
            ],
            'meta' => null,
        ]);

        $this->getJson('/auth/options')
            ->assertStatus(200)
            ->assertJsonPath('mode', 'mixed')
            ->assertJsonPath('local.enabled', true)
            ->assertJsonPath('auto_redirect', null)
            ->assertJsonPath('idp.providers.0.links.authorize', '/auth/oidc/authorize?provider='.$provider->id)
            ->assertJsonPath('idp.providers.0.driver', 'entra');
    }
}
